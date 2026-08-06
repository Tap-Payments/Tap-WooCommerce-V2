<?php
/**
 * Exception hierarchy for the Tap gateway.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Base class for every exception this plugin raises deliberately.
 *
 * Carries two things a plain Exception does not:
 *
 * - a structured context array, so the log line has the order id, transaction
 *   id, and so on without having to be formatted into the message; and
 * - a separate customer-facing message, so internal detail is never rendered
 *   at the checkout. getMessage() is for the log; get_customer_message() is for
 *   the shopper.
 */
class Tap_Exception extends RuntimeException {

	/**
	 * Structured context for logging.
	 *
	 * @var array<string, mixed>
	 */
	protected array $context = array();

	/**
	 * Message safe to show a customer, if the caller supplied one.
	 *
	 * @var string
	 */
	protected string $customer_message = '';

	/**
	 * Constructor.
	 *
	 * @param string               $message          Technical message, for the log.
	 * @param array<string, mixed> $context          Structured context.
	 * @param string               $customer_message Message safe to show a customer.
	 * @param Throwable|null       $previous         Previous throwable.
	 */
	public function __construct( string $message, array $context = array(), string $customer_message = '', ?Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );

		$this->context          = $context;
		$this->customer_message = $customer_message;
	}

	/**
	 * Structured context for logging.
	 *
	 * @return array<string, mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * A message that is safe to render to a customer.
	 *
	 * Never falls back to getMessage(): technical detail must not reach the
	 * storefront.
	 *
	 * @return string
	 */
	public function get_customer_message(): string {
		if ( '' !== $this->customer_message ) {
			return $this->customer_message;
		}

		return __( 'Something went wrong while processing your payment. Please try again.', 'wc-tap-gateway' );
	}
}

/**
 * The gateway is missing configuration it cannot work without.
 */
final class Tap_Configuration_Exception extends Tap_Exception {

	/**
	 * A message that is safe to render to a customer.
	 *
	 * @return string
	 */
	public function get_customer_message(): string {
		if ( '' !== $this->customer_message ) {
			return $this->customer_message;
		}

		return __( 'This payment method is not available right now. Please choose another one.', 'wc-tap-gateway' );
	}
}

/**
 * Input failed validation before it was used.
 */
final class Tap_Validation_Exception extends Tap_Exception {

	/**
	 * A message that is safe to render to a customer.
	 *
	 * @return string
	 */
	public function get_customer_message(): string {
		if ( '' !== $this->customer_message ) {
			return $this->customer_message;
		}

		return __( 'We could not verify this payment. Please try again.', 'wc-tap-gateway' );
	}
}

/**
 * The Tap API could not be reached, or rejected the request.
 */
final class Tap_Api_Exception extends Tap_Exception {

	/**
	 * The failed response, when there was one.
	 *
	 * @var Tap_Response|null
	 */
	private ?Tap_Response $response;

	/**
	 * Constructor.
	 *
	 * @param string               $message          Technical message.
	 * @param Tap_Response|null    $response         Failed response, when there was one.
	 * @param array<string, mixed> $context          Structured context.
	 * @param string               $customer_message Message safe to show a customer.
	 * @param Throwable|null       $previous         Previous throwable.
	 */
	public function __construct(
		string $message,
		?Tap_Response $response = null,
		array $context = array(),
		string $customer_message = '',
		?Throwable $previous = null
	) {
		parent::__construct( $message, $context, $customer_message, $previous );

		$this->response = $response;
	}

	/**
	 * Build an exception from a failed response.
	 *
	 * @param Tap_Response         $response  Failed response.
	 * @param string               $operation What was being attempted.
	 * @param array<string, mixed> $context   Structured context.
	 * @return self
	 */
	public static function from_response( Tap_Response $response, string $operation, array $context = array() ): self {
		return new self(
			$operation . ' failed: ' . $response->get_error_message(),
			$response,
			array_merge( $context, array( 'status' => $response->get_status_code() ) )
		);
	}

	/**
	 * The failed response, when there was one.
	 *
	 * @return Tap_Response|null
	 */
	public function get_response(): ?Tap_Response {
		return $this->response;
	}

	/**
	 * HTTP status code, or 0 when the request never completed.
	 *
	 * @return int
	 */
	public function get_status_code(): int {
		return $this->response instanceof Tap_Response ? $this->response->get_status_code() : 0;
	}

	/**
	 * Whether retrying the request could plausibly succeed.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		$status = $this->get_status_code();

		return 0 === $status || 429 === $status || $status >= 500;
	}
}

/**
 * An order could not be moved to the state the payment requires.
 */
final class Tap_Order_Exception extends Tap_Exception {
}
