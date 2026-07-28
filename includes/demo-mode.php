<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ==========================================================
 * Demo mode - твърди лимити за публично demo/preview копие на сайта
 * ==========================================================
 * Предназначено за инсталация на собствен поддомейн, до която клиенти имат
 * достъп преди покупка, за да пробват плъгина реално. Всички посетители
 * споделят един demo акаунт (upload изисква manage_woocommerce), затова
 * лимитите тук са ГЛОБАЛНИ за инсталацията, не per-потребител/IP - същият
 * модел като съществуващата дневна AI квота (виж decaldesk_daily_quota_available()
 * в includes/ai-content.php).
 */

/**
 * Дали Demo mode е включен в настройките.
 */
function decaldesk_is_demo_mode_enabled() {
	$settings = get_option( 'decaldesk_settings', array() );
	return ! empty( $settings['demo_mode'] );
}

/**
 * Максималният размер на файл (в байтове), допустим докато Demo mode е включен.
 */
function decaldesk_get_demo_max_filesize_bytes() {
	$settings = get_option( 'decaldesk_settings', array() );
	$mb       = isset( $settings['demo_max_filesize_mb'] ) ? max( 1, (int) $settings['demo_max_filesize_mb'] ) : 3;
	return $mb * MB_IN_BYTES;
}

/**
 * Дневният лимит качвания, докато Demo mode е включен.
 */
function decaldesk_get_demo_upload_daily_cap() {
	$settings = get_option( 'decaldesk_settings', array() );
	return isset( $settings['demo_upload_daily_cap'] ) ? max( 1, (int) $settings['demo_upload_daily_cap'] ) : 30;
}

/**
 * Дневният лимит РЕАЛНИ AI извиквания, докато Demo mode е включен (отделен от
 * обичайния Gemini лимит - важи и за Claude, който иначе няма вграден лимит).
 */
function decaldesk_get_demo_ai_daily_cap() {
	$settings = get_option( 'decaldesk_settings', array() );
	return isset( $settings['demo_ai_daily_cap'] ) ? max( 1, (int) $settings['demo_ai_daily_cap'] ) : 15;
}

if ( ! defined( 'DECALDESK_DEMO_UPLOAD_QUOTA_OPTION' ) ) {
	define( 'DECALDESK_DEMO_UPLOAD_QUOTA_OPTION', 'decaldesk_demo_upload_daily_usage' );
}

if ( ! defined( 'DECALDESK_DEMO_AI_QUOTA_OPTION' ) ) {
	define( 'DECALDESK_DEMO_AI_QUOTA_OPTION', 'decaldesk_demo_ai_daily_usage' );
}

/**
 * Общ хелпър - текущия брой изразходвани "слотове" за деня, за даден option ключ.
 * Нулира се автоматично при смяна на деня (по часовата зона на сайта).
 *
 * @param string $option_name Име на option-а, в който се пази { date, count }.
 * @return int
 */
function decaldesk_get_demo_daily_usage( $option_name ) {
	$usage = get_option(
		$option_name,
		array(
			'date'  => '',
			'count' => 0,
		)
	);

	if ( $usage['date'] !== current_time( 'Y-m-d' ) ) {
		return 0;
	}

	return (int) $usage['count'];
}

/**
 * Увеличава с 1 брояча за деня, за даден option ключ.
 *
 * @param string $option_name Име на option-а, в който се пази { date, count }.
 */
function decaldesk_increment_demo_daily_usage( $option_name ) {
	$today = current_time( 'Y-m-d' );
	$usage = get_option(
		$option_name,
		array(
			'date'  => '',
			'count' => 0,
		)
	);

	if ( $usage['date'] !== $today ) {
		$usage = array(
			'date'  => $today,
			'count' => 0,
		);
	}

	++$usage['count'];
	update_option( $option_name, $usage, false );
}

function decaldesk_demo_upload_quota_available( $daily_cap ) {
	return decaldesk_get_demo_daily_usage( DECALDESK_DEMO_UPLOAD_QUOTA_OPTION ) < $daily_cap;
}

function decaldesk_increment_demo_upload_quota() {
	decaldesk_increment_demo_daily_usage( DECALDESK_DEMO_UPLOAD_QUOTA_OPTION );
}

function decaldesk_demo_ai_quota_available( $daily_cap ) {
	return decaldesk_get_demo_daily_usage( DECALDESK_DEMO_AI_QUOTA_OPTION ) < $daily_cap;
}

function decaldesk_increment_demo_ai_quota() {
	decaldesk_increment_demo_daily_usage( DECALDESK_DEMO_AI_QUOTA_OPTION );
}

/**
 * Проверява размер и дневен лимит качвания за Demo mode. Вика се само след
 * като е потвърдено, че Demo mode е включен - връща true ако качването е
 * позволено, или WP_Error с готово за показ съобщение ако не е.
 *
 * @param array $file Елемент от $_FILES.
 * @return true|WP_Error
 */
function decaldesk_check_demo_upload_limits( $file ) {
	$max_bytes = decaldesk_get_demo_max_filesize_bytes();

	if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
		return new WP_Error(
			'decaldesk_demo_file_too_large',
			sprintf(
				/* translators: %d: max file size in MB */
				__( 'This is a demo preview site: files larger than %d MB aren\'t accepted here.', 'decaldesk' ),
				(int) round( $max_bytes / MB_IN_BYTES )
			)
		);
	}

	$daily_cap = decaldesk_get_demo_upload_daily_cap();

	if ( ! decaldesk_demo_upload_quota_available( $daily_cap ) ) {
		return new WP_Error(
			'decaldesk_demo_upload_cap_reached',
			__( 'This is a demo preview site: the daily upload limit has been reached. Please try again tomorrow, or contact us to try the full version.', 'decaldesk' )
		);
	}

	return true;
}

/**
 * Пази дневния cron за почистване на историята ('decaldesk_daily_cleanup',
 * виж includes/database.php) синхронизиран с Demo mode - на публично demo
 * копие искаме почистване на всеки час, не веднъж дневно, за да не се
 * трупат чужди тестови продукти/файлове между посетители. 'hourly' е
 * вградено WP разписание, не изисква регистриране на custom интервал.
 */
function decaldesk_sync_cleanup_cron_schedule() {
	$desired = decaldesk_is_demo_mode_enabled() ? 'hourly' : 'daily';

	$scheduled = wp_get_scheduled_event( 'decaldesk_daily_cleanup' );
	if ( $scheduled && $scheduled->schedule === $desired ) {
		return;
	}

	wp_clear_scheduled_hook( 'decaldesk_daily_cleanup' );
	wp_schedule_event( time(), $desired, 'decaldesk_daily_cleanup' );
}
add_action( 'update_option_decaldesk_settings', 'decaldesk_sync_cleanup_cron_schedule' );
