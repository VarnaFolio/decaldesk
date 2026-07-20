<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Изчислява цена въз основа на размери (в см).
 * Ценообразуване: цена на м² (по подразбиране 60 €), с минимална цена.
 *
 * @param int $width_cm  Ширина в сантиметри.
 * @param int $height_cm Височина в сантиметри.
 * @return float Крайна цена, закръглена до 2 знака.
 */
function decaldesk_calculate_price( $width_cm, $height_cm ) {
	$settings = wp_parse_args(
		get_option( 'decaldesk_settings', array() ),
		array(
			'price_per_sqm' => 60,
			'min_price'     => 15,
		)
	);

	$price_per_sqm = (float) $settings['price_per_sqm'];
	$min_price     = (float) $settings['min_price'];

	// см -> м
	$width_m  = $width_cm / 100;
	$height_m = $height_cm / 100;

	$area_sqm = $width_m * $height_m;
	$price    = $area_sqm * $price_per_sqm;

	// Прилагаме минимална цена
	$price = max( $price, $min_price );

	return round( $price, 2 );
}

/**
 * Помощна функция: връща площта в м² за дадени размери в см.
 *
 * @param int $width_cm
 * @param int $height_cm
 * @return float
 */
function decaldesk_calculate_area_sqm( $width_cm, $height_cm ) {
	return round( ( $width_cm / 100 ) * ( $height_cm / 100 ), 4 );
}
