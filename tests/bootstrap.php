<?php
/**
 * PHPUnit bootstrap for DecalDesk's business-logic unit tests.
 *
 * Deliberately NOT a full WordPress test suite bootstrap (no WP_UnitTestCase,
 * no MySQL test DB) - includes/parser.php and includes/pricing.php are pure
 * computation with only a thin WP surface (get_option(), wp_parse_args(),
 * __(), WP_Error), so Brain Monkey (function mocking) plus a couple of
 * minimal core-class stubs is enough and runs in milliseconds.
 */

require_once __DIR__ . '/../tools/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal WP_Error / is_wp_error - just enough of the real API surface for
// parser.php's usage (single error code + message per instance). Not a full
// copy of wp-includes/class-wp-error.php since nothing here needs multi-code
// error stacking, add_data(), etc.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors     = array();
		public $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes ? $codes[0] : '';
		}

		public function get_error_message( $code = '' ) {
			if ( '' === $code ) {
				$code = $this->get_error_code();
			}
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		public function has_errors() {
			return (bool) $this->errors;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

require_once __DIR__ . '/../includes/parser.php';
require_once __DIR__ . '/../includes/pricing.php';
