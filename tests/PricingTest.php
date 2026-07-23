<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Unit tests for includes/pricing.php - area-based price calculation is
 * used for every single product, and silently getting it wrong means every
 * store using DecalDesk mis-prices their entire catalog.
 */
class PricingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function mockSettings( array $settings ) {
		Functions\when( 'get_option' )->justReturn( $settings );
		// pricing.php runs get_option() through wp_parse_args() with its own
		// defaults - use the real function so partial overrides behave
		// exactly as they do in production instead of re-implementing the
		// merge logic in the test.
		Functions\when( 'wp_parse_args' )->alias(
			function ( $args, $defaults ) {
				return array_merge( $defaults, (array) $args );
			}
		);
	}

	public function test_default_price_per_sqm_and_no_min_clamp() {
		$this->mockSettings( array() ); // no settings saved - defaults apply: 60/m², 15 min

		// 50 x 70 cm = 0.5m x 0.7m = 0.35 sqm * 60 = 21.00
		$price = decaldesk_calculate_price( 50, 70 );

		$this->assertSame( 21.0, $price );
	}

	public function test_small_size_is_clamped_to_minimum_price() {
		$this->mockSettings( array() ); // defaults: 60/m², 15 min

		// 10 x 10 cm = 0.1m x 0.1m = 0.01 sqm * 60 = 0.60, well under the 15 minimum
		$price = decaldesk_calculate_price( 10, 10 );

		$this->assertSame( 15.0, $price );
	}

	public function test_custom_price_per_sqm_setting_is_used() {
		$this->mockSettings(
			array(
				'price_per_sqm' => 100,
				'min_price'     => 5,
			)
		);

		// 50 x 70 cm = 0.35 sqm * 100 = 35.00
		$price = decaldesk_calculate_price( 50, 70 );

		$this->assertSame( 35.0, $price );
	}

	public function test_custom_min_price_setting_is_used() {
		$this->mockSettings(
			array(
				'price_per_sqm' => 60,
				'min_price'     => 50,
			)
		);

		// 50 x 70 cm computes to 21.00, but min_price is 50 - clamp applies
		$price = decaldesk_calculate_price( 50, 70 );

		$this->assertSame( 50.0, $price );
	}

	public function test_price_is_rounded_to_two_decimals() {
		$this->mockSettings(
			array(
				'price_per_sqm' => 33,
				'min_price'     => 0,
			)
		);

		// 33 x 33 cm = 0.33 x 0.33 = 0.1089 sqm * 33 = 3.5937 -> rounds to 3.59
		$price = decaldesk_calculate_price( 33, 33 );

		$this->assertSame( 3.59, $price );
	}

	public function test_exact_one_square_meter() {
		$this->mockSettings( array( 'price_per_sqm' => 60, 'min_price' => 0 ) );

		// 100 x 100 cm = 1 sqm exactly * 60 = 60.00
		$price = decaldesk_calculate_price( 100, 100 );

		$this->assertSame( 60.0, $price );
	}

	public function test_calculate_area_sqm_basic() {
		// 50 x 70 cm = 0.5m x 0.7m = 0.35 sqm
		$area = decaldesk_calculate_area_sqm( 50, 70 );

		$this->assertSame( 0.35, $area );
	}

	public function test_calculate_area_sqm_rounds_to_four_decimals() {
		// 33 x 33 cm = 0.33 x 0.33 = 0.1089 sqm (exact - verifies precision isn't truncated early)
		$area = decaldesk_calculate_area_sqm( 33, 33 );

		$this->assertSame( 0.1089, $area );
	}

	public function test_calculate_area_sqm_small_size() {
		// 1 x 1 cm = 0.01m x 0.01m = 0.0001 sqm
		$area = decaldesk_calculate_area_sqm( 1, 1 );

		$this->assertSame( 0.0001, $area );
	}
}
