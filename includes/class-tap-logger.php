<?php
/**
 * Structured logging for the Tap gateway.
 *
 * @package WC_Tap_Gateway
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log under the "tap" source, with levels, a
 * per-request correlation id, and redaction of anything that looks like an API
 * key or a card number.
 *
 * Debug-level messages are suppressed unless the gateway's "Debug log" setting
 * is enabled, so the log stays usable in production.
 */
final class Tap_Logger {

	/**
	 * Correlation id shared by every log line in the current request.
	 *
	 * @var string
	 */
	private static string $request_id = '';

	/**
	 * Whether debug-level messages should be written.
	 *
	 * @var bool|null
	 */
	private static ?bool $debug_enabled = null;

	/**
	 * Cached logger instance.
	 *
	 * @var WC_Logger_Interface|null
	 */
	private static ?WC_Logger_Interface $logger = null;

	/**
	 * Set the correlation id for the current request.
	 *
	 * @param string $request_id Correlation id.
	 */
	public static function set_request_id( string $request_id ): void {
		self::$request_id = $request_id;
	}

	/**
	 * Get the correlation id, generating one on first use.
	 *
	 * @return string
	 */
	public static function get_request_id(): string {
		if ( '' === self::$request_id ) {
			self::$request_id = self::generate_request_id();
		}
		return self::$request_id;
	}

	/**
	 * Generate a sortable, collision-resistant request id.
	 *
	 * @return string
	 */
	public static function generate_request_id(): string {
		$timestamp = base_convert( (string) round( microtime( true ) * 1000 ), 10, 36 );

		try {
			$random = bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $e ) {
			// random_bytes() throws when no CSPRNG is available. A correlation
			// id is not a security boundary, so a weaker fallback is fine.
			$random = substr( md5( uniqid( '', true ) ), 0, 16 );
		}

		return 'woo_' . $timestamp . '_' . $random;
	}

	/**
	 * Write a debug message. Suppressed unless debug logging is enabled.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra context.
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! self::is_debug_enabled() ) {
			return;
		}
		self::log( 'debug', $message, $context );
	}

	/**
	 * Write an info message.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra context.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( 'info', $message, $context );
	}

	/**
	 * Write a warning message.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra context.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( 'warning', $message, $context );
	}

	/**
	 * Write an error message.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra context.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( 'error', $message, $context );
	}

	/**
	 * Log a caught throwable.
	 *
	 * The stack trace is only written when debug logging is on, and goes through
	 * the same redaction as everything else: traces include function arguments,
	 * which for this plugin can include API keys.
	 *
	 * @param string               $message   What the plugin was trying to do.
	 * @param Throwable            $throwable The caught throwable.
	 * @param array<string, mixed> $context   Extra context.
	 */
	public static function exception( string $message, Throwable $throwable, array $context = array() ): void {
		if ( $throwable instanceof Tap_Exception ) {
			$context = array_merge( $throwable->get_context(), $context );
		}

		$context['exception'] = get_class( $throwable );
		$context['origin']    = basename( $throwable->getFile() ) . ':' . $throwable->getLine();

		self::error( $message . ' — ' . $throwable->getMessage(), $context );

		$previous = $throwable->getPrevious();
		if ( $previous instanceof Throwable ) {
			self::error(
				'Caused by: ' . $previous->getMessage(),
				array(
					'exception' => get_class( $previous ),
					'origin'    => basename( $previous->getFile() ) . ':' . $previous->getLine(),
				)
			);
		}

		if ( self::is_debug_enabled() ) {
			self::log( 'debug', 'Stack trace: ' . $throwable->getTraceAsString() );
		}
	}

	/**
	 * Write a message at an arbitrary level.
	 *
	 * Never throws. Callers use this from inside catch blocks, so a logger that
	 * could itself fail would turn a handled error into an unhandled one.
	 *
	 * @param string               $level   One of emergency|alert|critical|error|warning|notice|info|debug.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra context appended as key=value pairs.
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		try {
			$line = '[' . self::get_request_id() . '] ' . $message;

			if ( ! empty( $context ) ) {
				$parts = array();
				foreach ( $context as $key => $value ) {
					if ( is_scalar( $value ) || null === $value ) {
						$parts[] = $key . '=' . (string) $value;
					} else {
						$parts[] = $key . '=' . (string) wp_json_encode( $value );
					}
				}
				$line .= ' | ' . implode( ' ', $parts );
			}

			$line = self::redact( $line );

			$logger = self::get_logger();
			if ( $logger instanceof WC_Logger_Interface ) {
				$logger->log( $level, $line, array( 'source' => 'tap' ) );
				return;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback only when WooCommerce logging is unavailable.
				error_log( 'Tap [' . $level . ']: ' . $line );
			}
		} catch ( Throwable $e ) {
			// Last resort. Deliberately swallowed: there is nowhere left to report.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The logging path itself failed.
				error_log( 'Tap logger failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Remove secrets from a string before it is written anywhere.
	 *
	 * Redacts Tap API keys (sk_/pk_), long hexadecimal strings that could be an
	 * HMAC, and digit runs long enough to be a card number.
	 *
	 * @param string $text Text to redact.
	 * @return string
	 */
	public static function redact( string $text ): string {
		$patterns = array(
			'/\b((?:sk|pk)_(?:test|live)_)[A-Za-z0-9]+/'  => '$1***',
			'/\b([A-Fa-f0-9]{8})[A-Fa-f0-9]{24,}\b/'      => '$1***',
			'/\b(\d{6})\d{6,13}\b/'                       => '$1******',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$replaced = preg_replace( $pattern, $replacement, $text );
			if ( is_string( $replaced ) ) {
				$text = $replaced;
			}
		}

		return $text;
	}

	/**
	 * Whether debug logging is switched on in the gateway settings.
	 *
	 * @return bool
	 */
	private static function is_debug_enabled(): bool {
		if ( null === self::$debug_enabled ) {
			$settings            = get_option( 'woocommerce_tap_settings', array() );
			self::$debug_enabled = is_array( $settings ) && isset( $settings['debug_log'] ) && 'yes' === $settings['debug_log'];
		}
		return self::$debug_enabled;
	}

	/**
	 * Resolve the WooCommerce logger, if available.
	 *
	 * @return WC_Logger_Interface|null
	 */
	private static function get_logger(): ?WC_Logger_Interface {
		if ( null === self::$logger && function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( $logger instanceof WC_Logger_Interface ) {
				self::$logger = $logger;
			}
		}
		return self::$logger;
	}
}
