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
        'dashicons-format-image',
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

require_once DECALDESK_PATH . 'admin/admin-page-upload.php';
require_once DECALDESK_PATH . 'admin/admin-page-history.php';
require_once DECALDESK_PATH . 'admin/admin-page-categories.php';
