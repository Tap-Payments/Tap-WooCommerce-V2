<?php
/**
 * HTTP client for the Tap API.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Wraps every call to the Tap API.
 *
 * Uses the WordPress HTTP API rather than raw cURL so the plugin honours proxy
 * configuration, WP_HTTP_BLOCK_EXTERNAL, and the http_request_args filters, and
 * so it works on hosts where the cURL extension is unavailable.
 */
final class Tap_Api_Client {

	/**
	 * Tap API base URL.
	 */
	private const BASE_URL = 'https://api.tap.company/v2/';

	/**
	 * Request timeout in seconds.
	 */
	private const TIMEOUT = 30;

	/**
	 * Number of times a retryable failure is retried.
	 */
	private const MAX_RETRIES = 2;

	/**
	 * Secret key used for bearer authentication.
	 *
	 * @var string
	 */
	private string $secret_key;

	/**
	 * Correlation id sent as the idempotency key.
	 *
	 * @var string
	 */
	private string $request_id;

	/**
	 * Constructor.
	 *
	 * @param string $secret_key Tap secret key.
	 * @param string $request_id Correlation id; generated when empty.
	 */
	public function __construct( string $secret_key, string $request_id = '' ) {
		$this->secret_key = trim( $secret_key );
		$this->request_id = '' !== $request_id ? $request_id : Tap_Logger::get_request_id();
	}

	/**
	 * The correlation id used for this client's requests.
	 *
	 * @return string
	 */
	public function get_request_id(): string {
		return $this->request_id;
	}

	/**
	 * Fetch a transaction, dispatching on the id prefix.
	 *
	 * @param string $transaction_id Tap transaction id.
	 * @return Tap_Response
	 */
	public function get_transaction( string $transaction_id ): Tap_Response {
		if ( ! Tap_Validator::is_valid_transaction_id( $transaction_id ) ) {
			return Tap_Response::error( 0, array( 'message' => __( 'Malformed transaction id.', 'wc-tap-gateway' ) ) );
		}

		$segment = Tap_Validator::KIND_AUTHORIZE === Tap_Validator::transaction_kind( $transaction_id )
			? 'authorize'
			: 'charges';

		return $this->request( 'GET', $segment . '/' . rawurlencode( $transaction_id ) );
	}

	/**
	 * Create a charge or an authorization.
	 *
	 * @param string               $mode     Tap_Validator::KIND_CHARGE or KIND_AUTHORIZE.
	 * @param array<string, mixed> $payload  Request body.
	 * @param string               $language Two-letter UI language code.
	 * @return Tap_Response
	 */
	public function create_transaction( string $mode, array $payload, string $language = 'en' ): Tap_Response {
		$segment = Tap_Validator::KIND_AUTHORIZE === $mode ? 'authorize' : 'charges';

		return $this->request( 'POST', $segment, $payload, array( 'lang_code' => $language ) );
	}

	/**
	 * Create a refund.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @return Tap_Response
	 */
	public function create_refund( array $payload ): Tap_Response {
		return $this->request( 'POST', 'refunds', $payload );
	}

	/**
	 * Void an authorization.
	 *
	 * @param string $authorization_id Tap authorization id.
	 * @return Tap_Response
	 */
	public function void_authorization( string $authorization_id ): Tap_Response {
		if ( ! Tap_Validator::is_valid_transaction_id( $authorization_id ) ) {
			return Tap_Response::error( 0, array( 'message' => __( 'Malformed authorization id.', 'wc-tap-gateway' ) ) );
		}

		return $this->request( 'POST', 'authorize/' . rawurlencode( $authorization_id ) . '/void', array() );
	}

	/**
	 * Perform an HTTP request against the Tap API.
	 *
	 * @param string                    $method       HTTP method.
	 * @param string                    $path         Path relative to the API base URL.
	 * @param array<string, mixed>|null $body         Request body, JSON encoded when not null.
	 * @param array<string, string>     $extra_header Additional headers.
	 * @return Tap_Response
	 */
	private function request( string $method, string $path, ?array $body = null, array $extra_header = array() ): Tap_Response {
		if ( '' === $this->secret_key ) {
			Tap_Logger::error( 'Refusing to call the Tap API: no secret key configured.', array( 'path' => $path ) );
			return Tap_Response::error( 0, array( 'message' => __( 'The Tap gateway is not configured with an API key.', 'wc-tap-gateway' ) ) );
		}

		$url = self::BASE_URL . ltrim( $path, '/' );

		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0, // Never follow redirects: it would leak the Authorization header.
			'sslverify'   => true,
			'headers'     => array_merge(
				array(
					'Authorization'   => 'Bearer ' . $this->secret_key,
					'Content-Type'    => 'application/json',
					'Accept'          => 'application/json',
					'Idempotency-Key' => $this->request_id,
					'User-Agent'      => 'WooCommerce-Tap/' . TAP_GATEWAY_VERSION . '; ' . home_url( '/' ),
				),
				$extra_header
			),
		);

		if ( null !== $body ) {
			// JSON_UNESCAPED_SLASHES replaces the 2.x stripslashes() call, which
			// stripped semantically required escapes and produced malformed JSON
			// for any customer whose details contained a quote or a backslash.
			$args['body'] = wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

			if ( false === $args['body'] ) {
				Tap_Logger::error( 'Could not JSON-encode the request body.', array( 'path' => $path ) );
				return Tap_Response::error( 0, array( 'message' => __( 'Could not build the payment request.', 'wc-tap-gateway' ) ) );
			}
		}

		Tap_Logger::debug( 'Tap API request.', array( 'method' => $method, 'path' => $path ) );

		try {
			return $this->send( $url, $method, $path, $args );
		} catch ( Throwable $e ) {
			// wp_remote_request() itself returns WP_Error rather than throwing,
			// but the http_api_* filters run arbitrary third-party code, and a
			// throwable escaping from there must not take down the checkout.
			Tap_Logger::exception(
				'Unexpected failure while calling the Tap API.',
				$e,
				array(
					'method' => $method,
					'path'   => $path,
				)
			);

			return Tap_Response::from_throwable( $e );
		}
	}

	/**
	 * Send the request, retrying transient failures.
	 *
	 * @param string               $url    Absolute URL.
	 * @param string               $method HTTP method.
	 * @param string               $path   Path, for logging.
	 * @param array<string, mixed> $args   Arguments for wp_remote_request().
	 * @return Tap_Response
	 */
	private function send( string $url, string $method, string $path, array $args ): Tap_Response {
		$attempt  = 0;
		$response = null;

		do {
			++$attempt;

			$http = wp_remote_request( $url, $args );

			if ( is_wp_error( $http ) ) {
				$response = Tap_Response::from_wp_error( $http );
				Tap_Logger::error(
					'Tap API transport error.',
					array(
						'method'  => $method,
						'path'    => $path,
						'attempt' => $attempt,
						'error'   => $http->get_error_message(),
					)
				);
			} else {
				$status_code = (int) wp_remote_retrieve_response_code( $http );
				$raw_body    = (string) wp_remote_retrieve_body( $http );
				$decoded     = json_decode( $raw_body, true );

				if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
					$response = Tap_Response::invalid_body( $status_code, $raw_body );
					Tap_Logger::error(
						'Tap API returned an unreadable body.',
						array(
							'method' => $method,
							'path'   => $path,
							'status' => $status_code,
						)
					);
				} elseif ( $status_code >= 400 ) {
					$response = Tap_Response::error( $status_code, $decoded );
					Tap_Logger::error(
						'Tap API rejected the request.',
						array(
							'method' => $method,
							'path'   => $path,
							'status' => $status_code,
							'body'   => $raw_body,
						)
					);
				} else {
					Tap_Logger::debug(
						'Tap API request succeeded.',
						array(
							'method' => $method,
							'path'   => $path,
							'status' => $status_code,
						)
					);
					return Tap_Response::success( $status_code, $decoded );
				}
			}

			$should_retry = $attempt <= self::MAX_RETRIES && $this->is_retryable( $response );

			if ( $should_retry ) {
				// Exponential backoff. Safe to retry because every mutating call
				// carries the same Idempotency-Key.
				usleep( 250000 * $attempt );
			}
		} while ( $should_retry );

		return $response;
	}

	/**
	 * Whether a failed response is worth retrying.
	 *
	 * Client errors (4xx) are not retried: the request will be rejected again.
	 *
	 * @param Tap_Response $response Failed response.
	 * @return bool
	 */
	private function is_retryable( Tap_Response $response ): bool {
		$status = $response->get_status_code();

		if ( 0 === $status ) {
			return true; // Transport failure.
		}

		return $status >= 500 || 429 === $status;
	}
}
