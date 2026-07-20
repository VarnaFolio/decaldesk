<?php
/**
 * Изпълнява се само когато потребителят изтрие плъгина от админ панела
 * (Plugins → Delete). Не се зарежда decaldesk.php, затова файлът е напълно
 * самостоятелен - не разчита на функции, дефинирани в главния bootstrap.
 *
 * Действа като резервен механизъм към decaldesk_run_uninstall_cleanup_all_sites()
 * (декларирана в decaldesk.php, извиквана през decaldesk_fs()->add_action(
 * 'after_uninstall', ... )) - и двата пътя изпълняват идентична, идемпотентна
 * логика, така че евентуалното им едновременно изпълнение е безопасно
 * (delete_option/DROP TABLE IF EXISTS/изтриване на директория са no-op при
 * повторно изпълнение).
 *
 * По подразбиране НЕ трие нищо - изисква изрично включена настройка
 * "Пълно почистване" от DecalDesk → Настройки. WooCommerce продуктите,
 * създадени с плъгина, НИКОГА не се трият тук - те са реални бизнес данни.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Чисти опциите, DB таблицата и качените файлове на DecalDesk за текущия сайт.
 */
function decaldesk_uninstall_cleanup_site() {
	$settings = get_option( 'decaldesk_settings', array() );

	if ( empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	delete_option( 'decaldesk_settings' );
	delete_option( 'decaldesk_ai_daily_usage' );
	delete_option( 'decaldesk_db_version' );
	delete_option( 'decaldesk_migrated_from_productops' );

	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'decaldesk_jobs' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'decaldesk' );
	}

	$upload_dir    = wp_upload_dir();
	$decaldesk_dir = $upload_dir['basedir'] . '/decaldesk';

	if ( is_dir( $decaldesk_dir ) ) {
		decaldesk_uninstall_recursive_delete_dir( $decaldesk_dir );
	}
}

/**
 * Рекурсивно изтрива директория и цялото ѝ съдържание.
 */
function decaldesk_uninstall_recursive_delete_dir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = scandir( $dir );
	if ( false === $items ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = $dir . '/' . $item;

		if ( is_dir( $path ) ) {
			decaldesk_uninstall_recursive_delete_dir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	@rmdir( $dir );
}

// Поддръжка на multisite: ако плъгинът е бил активиран мрежово, чистим за
// всеки сайт в мрежата поотделно (всеки сайт си има собствени опции/uploads).
if ( is_multisite() ) {
	$decaldesk_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $decaldesk_site_ids as $decaldesk_site_id ) {
		switch_to_blog( $decaldesk_site_id );
		decaldesk_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	decaldesk_uninstall_cleanup_site();
}
