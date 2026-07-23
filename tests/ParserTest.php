<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * Unit tests for includes/parser.php - filename parsing is the entry point
 * for every single upload, so a parsing regression silently breaks the
 * whole plugin. Covers the current 4-segment (with material) and 3-segment
 * (without material, added in 1.5.9) formats, plus the error paths.
 */
class ParserTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		// Default: no settings saved yet - decaldesk_parse_filename() falls
		// back to its own default (1000cm) when max_dimension_cm is unset.
		Functions\when( 'get_option' )->justReturn( array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_parses_filename_with_material() {
		$result = decaldesk_parse_filename( 'koleda_50x70_matte_kitchen.jpg' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Koleda', $result['name'] );
		$this->assertSame( 50, $result['width'] );
		$this->assertSame( 70, $result['height'] );
		$this->assertSame( 'matte', $result['material'] );
		$this->assertSame( 'kitchen', $result['category'] );
	}

	public function test_parses_filename_without_material() {
		$result = decaldesk_parse_filename( 'koleda_50x70_kitchen.jpg' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Koleda', $result['name'] );
		$this->assertSame( 50, $result['width'] );
		$this->assertSame( 70, $result['height'] );
		$this->assertSame( '', $result['material'] );
		$this->assertSame( 'kitchen', $result['category'] );
	}

	public function test_design_name_with_underscores_and_material() {
		$result = decaldesk_parse_filename( 'winter_forest_50x70_matte_kitchen.jpg' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Winter forest', $result['name'] );
		$this->assertSame( 'matte', $result['material'] );
		$this->assertSame( 'kitchen', $result['category'] );
	}

	public function test_design_name_with_underscores_and_no_material() {
		$result = decaldesk_parse_filename( 'winter_forest_50x70_kitchen.jpg' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Winter forest', $result['name'] );
		$this->assertSame( '', $result['material'] );
		$this->assertSame( 'kitchen', $result['category'] );
	}

	public function test_cyrillic_name_is_preserved_and_capitalized() {
		$result = decaldesk_parse_filename( 'коледа_50x70_matte_kitchen.png' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Коледа', $result['name'] );
	}

	public function test_dashes_in_name_become_spaces() {
		$result = decaldesk_parse_filename( 'holiday-design_50x70_matte_kitchen.png' );

		$this->assertIsArray( $result );
		$this->assertSame( 'Holiday design', $result['name'] );
	}

	public function test_extension_is_ignored_for_parsing() {
		foreach ( array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ) as $ext ) {
			$result = decaldesk_parse_filename( "koleda_50x70_matte_kitchen.{$ext}" );
			$this->assertIsArray( $result, "Failed for extension: {$ext}" );
			$this->assertSame( 'kitchen', $result['category'] );
		}
	}

	public function test_completely_malformed_filename_returns_wp_error() {
		$result = decaldesk_parse_filename( 'not_a_valid_filename_at_all.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_missing_dimensions_returns_wp_error() {
		$result = decaldesk_parse_filename( 'koleda_kitchen.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_zero_width_returns_wp_error() {
		$result = decaldesk_parse_filename( 'koleda_0x70_matte_kitchen.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'positive', $result->get_error_message() );
	}

	public function test_dimension_over_configured_max_returns_wp_error() {
		Functions\when( 'get_option' )->justReturn( array( 'max_dimension_cm' => 200 ) );

		$result = decaldesk_parse_filename( 'koleda_500x70_matte_kitchen.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_dimension_within_configured_max_is_accepted() {
		Functions\when( 'get_option' )->justReturn( array( 'max_dimension_cm' => 200 ) );

		$result = decaldesk_parse_filename( 'koleda_150x70_matte_kitchen.jpg' );

		$this->assertIsArray( $result );
	}

	public function test_dimension_uses_default_max_when_unconfigured() {
		Functions\when( 'get_option' )->justReturn( array() );

		// Default ceiling is 1000cm - 900 should pass, 1500 should not.
		$ok  = decaldesk_parse_filename( 'koleda_900x70_matte_kitchen.jpg' );
		$too_big = decaldesk_parse_filename( 'koleda_1500x70_matte_kitchen.jpg' );

		$this->assertIsArray( $ok );
		$this->assertInstanceOf( WP_Error::class, $too_big );
	}

	public function test_material_and_category_are_lowercased() {
		$result = decaldesk_parse_filename( 'koleda_50x70_MATTE_KITCHEN.jpg' );

		$this->assertIsArray( $result );
		$this->assertSame( 'matte', $result['material'] );
		$this->assertSame( 'kitchen', $result['category'] );
	}

	public function test_category_slug_allows_hyphens() {
		$result = decaldesk_parse_filename( 'koleda_50x70_matte_car-wraps.jpg' );

		$this->assertIsArray( $result );
		$this->assertSame( 'car-wraps', $result['category'] );
	}

	public function test_raw_name_preserves_original_separators() {
		$result = decaldesk_parse_filename( 'winter_forest_50x70_matte_kitchen.jpg' );

		$this->assertSame( 'winter_forest', $result['raw_name'] );
	}
}
