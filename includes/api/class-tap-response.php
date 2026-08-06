<?php
/**
 * Typed wrapper around a Tap API response.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Distinguishes the three outcomes 2.x conflated into a bare null: transport
 * failure, an API-level rejection, and success.
 *
 * Because 2.x returned the decoded body regardless of HTTP status, a 401 with a
 * well-formed JSON error body was indistinguishable from a successful charge,
 * and the customer was shown "success" for a payment that never started.
 */
final class Tap_Response {

	/**
	 * Whether the call succeeded.
	 *
	 * @var bool
	 */
	private bool $success;

	/**
	 * HTTP status code, or 0 when the request never completed.
	 *
	 * @var int
	 */
	private int $status_code;

	/**
	 * Decoded response body.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Human-readable error message, empty on success.
	 *
	 * @var string
	 */
	private string $error_message;

	/**
	 * Constructor.
	 *
	 * @param bool                 $success       Whether the call succeeded.
	 * @param int                  $status_code   HTTP status code.
	 * @param array<string, mixed> $data          Decoded body.
	 * @param string               $error_message Error message.
	 */
	private function __construct( bool $success, int $status_code, array $data, string $error_message = '' ) {
		$this->success       = $success;
		$this->status_code   = $status_code;
		$this->data          = $data;
		$this->error_message = $error_message;
	}

	/**
	 * Build a successful response.
	 *
	 * @param int                  $status_code HTTP status code.
	 * @param array<string, mixed> $data        Decoded body.
	 * @return self
	 */
	public static function success( int $status_code, array $data ): self {
		return new self( true, $status_code, $data );
	}

	/**
	 * Build a response representing an API-level rejection.
	 *
	 * @param int                       $status_code HTTP status code.
	 * @param array<string, mixed>|null $data        Decoded body, when present.
	 * @return self
	 */
	public static function error( int $status_code, ?array $data ): self {
		$data    = is_array( $data ) ? $data : array();
		$message = self::extract_error_message( $data );

		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'The payment provider returned HTTP %d.', 'wc-tap-gateway' ), $status_code );
		}

		return new self( false, $status_code, $data, $message );
	}

	/**
	 * Build a response representing a transport failure.
	 *
	 * @param WP_Error $wp_error Error from the WordPress HTTP API.
	 * @return self
	 */
	public static function from_wp_error( WP_Error $wp_error ): self {
		return new self( false, 0, array(), $wp_error->get_error_message() );
	}

	/**
	 * Build a response representing an unexpected throwable during the call.
	 *
	 * @param Throwable $throwable The caught throwable.
	 * @return self
	 */
	public static function from_throwable( Throwable $throwable ): self {
		return new self(
			false,
			0,
			array(),
			__( 'The payment provider could not be reached.', 'wc-tap-gateway' ) . ' (' . get_class( $throwable ) . ')'
		);
	}

	/**
	 * Build a response representing an unparseable body.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $raw_body    Raw response body.
	 * @return self
	 */
	public static function invalid_body( int $status_code, string $raw_body ): self {
		return new self(
			false,
			$status_code,
			array(),
			sprintf(
				/* translators: 1: HTTP status code, 2: JSON error description. */
				__( 'The payment provider returned an unreadable response (HTTP %1$d): %2$s', 'wc-tap-gateway' ),
				$status_code,
				json_last_error_msg()
			) . ' | ' . substr( $raw_body, 0, 200 )
		);
	}

	/**
	 * Whether the call succeeded.
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * HTTP status code, or 0 when the request never completed.
	 *
	 * @return int
	 */
	public function get_status_code(): int {
		return $this->status_code;
	}

	/**
	 * The full decoded body.
	 *
	 * @return array<string, mixed>
	 */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * Read a value from the body using dot notation.
	 *
	 * @param string $path    Dot-separated path, e.g. "reference.order".
	 * @param mixed  $default Value returned when the path is absent.
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$value = $this->data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * Read a value from the body as a string.
	 *
	 * @param string $path    Dot-separated path.
	 * @param string $default Value returned when the path is absent or not scalar.
	 * @return string
	 */
	public function get_string( string $path, string $default = '' ): string {
		$value = $this->get( $path );
		return is_scalar( $value ) ? (string) $value : $default;
	}

	/**
	 * Error message, empty on success.
	 *
	 * @return string
	 */
	public function get_error_message(): string {
		return $this->error_message;
	}

	/**
	 * Pull a usable message out of a Tap error body.
	 *
	 * @param array<string, mixed> $data Decoded body.
	 * @return string
	 */
	private static function extract_error_message( array $data ): string {
		if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
			$messages = array();
			foreach ( $data['errors'] as $error ) {
				if ( is_array( $error ) && isset( $error['description'] ) && is_scalar( $error['description'] ) ) {
					$messages[] = (string) $error['description'];
				} elseif ( is_scalar( $error ) ) {
					$messages[] = (string) $error;
				}
			}
			if ( ! empty( $messages ) ) {
				return implode( '; ', $messages );
			}
		}

		foreach ( array( 'message', 'description', 'error' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				return (string) $data[ $key ];
			}
		}

		if ( isset( $data['response']['message'] ) && is_scalar( $data['response']['message'] ) ) {
			return (string) $data['response']['message'];
		}

		return '';
	}
}
