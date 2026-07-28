<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Регистрация на менюто в WP Admin
 */
function decaldesk_register_admin_menu() {
	add_menu_page(
		__( 'DecalDesk', 'decaldesk' ),
		__( 'DecalDesk', 'decaldesk' ),
		'manage_woocommerce',
		'decaldesk',
		'decaldesk_render_upload_page',
		decaldesk_menu_icon_svg(),
		56
	);

	add_submenu_page(
		'decaldesk',
		__( 'Upload Designs', 'decaldesk' ),
		__( 'Upload', 'decaldesk' ),
		'manage_woocommerce',
		'decaldesk',
		'decaldesk_render_upload_page'
	);

	add_submenu_page(
		'decaldesk',
		__( 'History', 'decaldesk' ),
		__( 'History', 'decaldesk' ),
		'manage_woocommerce',
		'decaldesk-history',
		'decaldesk_render_history_page'
	);

	add_submenu_page(
		'decaldesk',
		__( 'Categories', 'decaldesk' ),
		__( 'Categories', 'decaldesk' ),
		'manage_options',
		'decaldesk-categories',
		'decaldesk_render_categories_page'
	);

	add_submenu_page(
		'decaldesk',
		__( 'Settings', 'decaldesk' ),
		__( 'Settings', 'decaldesk' ),
		'manage_options',
		'decaldesk-settings',
		'decaldesk_render_settings_page'
	);
}
add_action( 'admin_menu', 'decaldesk_register_admin_menu' );

/**
 * Малка брандирана SVG икона за менюто (заменя generic dashicons-format-image).
 * WP admin меню сам управлява opacity/цвят на монохромни base64 SVG икони
 * според избраната админ цветова схема - затова е с плътен черен fill,
 * не с брандираните цветове (те се виждат само в самия header, виж
 * admin-header.php).
 *
 * @return string data: URI
 */
function decaldesk_menu_icon_svg() {
	$svg = '<svg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><path fill="black" d="M10 2L18 17H2L10 2Z"/></svg>';
	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

require_once DECALDESK_PATH . 'admin/admin-header.php';
require_once DECALDESK_PATH . 'admin/admin-page-upload.php';
require_once DECALDESK_PATH . 'admin/admin-page-history.php';
require_once DECALDESK_PATH . 'admin/admin-page-categories.php';
