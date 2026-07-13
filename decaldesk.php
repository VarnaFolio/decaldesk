<?php
/**
 * Plugin Name:       DecalDesk
 * Plugin URI:        https://decaldesk.com
 * Description:       Автоматизирано създаване на WooCommerce продукти от дизайн файлове — парсване на име, ценообразуване по площ, AI описания, мокъп генериране, размерни варианти.
 * Version:           1.3.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Tested up to:      7.0
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 * Author:            DecalDesk
 * Author URI:        https://decaldesk.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       decaldesk
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Директен достъп забранен
}

// ==========================================================
// Freemius (лицензиране, плащания, Pro/Free gating, ъпдейти)
// ==========================================================
// Трябва да се зареди възможно най-рано - преди всичко останало в плъгина.
// Заменя предишния GitHub Plugin Update Checker: Freemius вече е
// каноничният update/license сървър (виж decaldesk_fs()->can_use_premium_code()
// на местата, където Pro функциите се заключват).
if ( ! function_exists( 'decaldesk_fs' ) ) {
    function decaldesk_fs() {
        global $decaldesk_fs;

        if ( ! isset( $decaldesk_fs ) ) {
            require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

            $decaldesk_fs = fs_dynamic_init( array(
                'id'                  => '34508',
                'slug'                => 'decaldesk',
                'type'                => 'plugin',
                'public_key'          => 'pk_5f71bbda1294ec97ace8d99c33f3b',
                'is_premium'          => true,
                'premium_suffix'      => 'Pro',
                // Ако плъгинът е "serviceware" (работи само през външен сървър,
                // без реален premium код в самия плъгин), тази опция трябва да е false.
                'has_premium_version' => true,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'is_org_compliant'    => true,
                // Ползва се само ако/когато Freemius автоматично генерира
                // WP.org-съвместима free версия от този код (маркирани блокове).
                // Премахва се автоматично в тази free версия, ако въобще стигнем
                // дотам - засега няма ефект, докато не активираме тази функция.
                'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                'menu'                => array(
                    'support'        => false,
                ),
            ) );
        }

        return $decaldesk_fs;
    }

    decaldesk_fs();
    do_action( 'decaldesk_fs_loaded' );
}

// ==========================================================
// Константи
// ==========================================================
define( 'DECALDESK_VERSION', '1.3.1' );
define( 'DECALDESK_PATH', plugin_dir_path( __FILE__ ) );
define( 'DECALDESK_URL', plugin_dir_url( __FILE__ ) );

// Максимален брой PNG файлове, качвани наведнъж (пакетно качване)
if ( ! defined( 'DECALDESK_MAX_BATCH_FILES' ) ) {
    define( 'DECALDESK_MAX_BATCH_FILES', 50 );
}

// Позволени файлови формати за качване на дизайни (най-разпространените за графика)
if ( ! defined( 'DECALDESK_ALLOWED_EXTENSIONS' ) ) {
    define( 'DECALDESK_ALLOWED_EXTENSIONS', array( 'png', 'jpg', 'jpeg', 'webp', 'gif' ) );
}

// ==========================================================
// Съвместимост с WooCommerce HPOS (High-Performance Order Storage)
// ==========================================================
// Без тази декларация WooCommerce показва предупредителна нотификация в
// админ панела на всеки нов потребител, дори плъгинът да работи напълно
// нормално - DecalDesk не пипа директно order таблиците изобщо (работи
// само с продукти и собствена jobs таблица), затова е безопасно да
// декларираме пълна съвместимост.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// ==========================================================
// Миграция от старото име "ProductOps" (ако сайтът е ъпдейтнат от
// по-стара версия, преди rebrand-а към DecalDesk)
// ==========================================================
// ВАЖНО: преди да качиш тази версия, деактивирай старата "ProductOps"
// (или просто изтрий старата productops/ папка и качи decaldesk/ вместо
// нея) - двата плъгина не трябва да са активни едновременно. Тази функция
// прехвърля настройките, историята (jobs таблицата) и качените шаблони/
// файлове от старите имена към новите, автоматично и еднократно.
function decaldesk_maybe_migrate_from_productops() {
    if ( get_option( 'decaldesk_migrated_from_productops' ) ) {
        return; // Вече мигрирано - нищо за правене
    }

    global $wpdb;
    $migrated_anything = false;

    // 1) Основни настройки
    $old_settings = get_option( 'productops_settings', false );
    if ( false !== $old_settings && false === get_option( 'decaldesk_settings', false ) ) {
        add_option( 'decaldesk_settings', $old_settings );
        $migrated_anything = true;
    }

    // 2) Дневна AI квота
    $old_quota = get_option( 'productops_ai_daily_usage', false );
    if ( false !== $old_quota && false === get_option( 'decaldesk_ai_daily_usage', false ) ) {
        add_option( 'decaldesk_ai_daily_usage', $old_quota );
        $migrated_anything = true;
    }

    // 3) DB таблица с историята (jobs) - RENAME TABLE е бърз, атомарен, не губи данни
    $old_table = $wpdb->prefix . 'productops_jobs';
    $new_table = $wpdb->prefix . 'decaldesk_jobs';

    $old_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
    $new_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table;

    if ( $old_table_exists && ! $new_table_exists ) {
        $wpdb->query( "RENAME TABLE {$old_table} TO {$new_table}" );
        $migrated_anything = true;
    }

    // 4) Upload директория (качени шаблони, incoming/mockups) - преместваме цялата папка наведнъж
    $upload_dir = wp_upload_dir();
    $old_dir    = $upload_dir['basedir'] . '/productops';
    $new_dir    = $upload_dir['basedir'] . '/decaldesk';

    if ( is_dir( $old_dir ) && ! is_dir( $new_dir ) ) {
        if ( @rename( $old_dir, $new_dir ) ) {
            $migrated_anything = true;
        } else {
            // rename() между различни файлови системи понякога не работи на
            // някои хостинги - fallback на рекурсивно копиране
            decaldesk_recursive_copy_dir( $old_dir, $new_dir );
            $migrated_anything = true;
        }
    }

    update_option( 'decaldesk_migrated_from_productops', current_time( 'mysql' ) );

    if ( $migrated_anything ) {
        set_transient( 'decaldesk_migration_notice', true, DAY_IN_SECONDS );
    }
}
add_action( 'plugins_loaded', 'decaldesk_maybe_migrate_from_productops', 5 );

/**
 * Рекурсивно копиране на директория (fallback, ако rename() между
 * файлови системи не сработи на дадения хостинг).
 */
function decaldesk_recursive_copy_dir( $source, $destination ) {
    if ( ! is_dir( $source ) ) {
        return;
    }

    wp_mkdir_p( $destination );

    $items = scandir( $source );
    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }

        $src_path  = $source . '/' . $item;
        $dest_path = $destination . '/' . $item;

        if ( is_dir( $src_path ) ) {
            decaldesk_recursive_copy_dir( $src_path, $dest_path );
        } else {
            @copy( $src_path, $dest_path );
        }
    }
}

/**
 * Кратко еднократно известие в админ панела, потвърждаващо успешна миграция.
 */
function decaldesk_render_migration_notice() {
    if ( ! get_transient( 'decaldesk_migration_notice' ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'decaldesk' ) === false ) {
        return;
    }

    delete_transient( 'decaldesk_migration_notice' );
    ?>
    <div class="notice notice-success is-dismissible">
        <p><strong>DecalDesk:</strong> <?php esc_html_e( 'Your settings, history, and uploaded templates were successfully migrated from the previous version (ProductOps). Everything is in place.', 'decaldesk' ); ?></p>
    </div>
    <?php
}
add_action( 'admin_notices', 'decaldesk_render_migration_notice' );

// ==========================================================
// Проверка дали WooCommerce е активен
// ==========================================================
function decaldesk_check_woocommerce() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__( 'DecalDesk requires WooCommerce to be active in order to work.', 'decaldesk' );
            echo '</p></div>';
        } );
        return false;
    }
    return true;
}
// ==========================================================
// Зареждане на преводи (i18n)
// ==========================================================
// Source кодът е на английски (стандартна WordPress конвенция) - всеки
// друг език, включително български, идва като превод от languages/.
// Затова сайт с bg_BG locale автоматично вижда българския интерфейс,
// без да е нужна промяна в кода.
function decaldesk_load_textdomain() {
    load_plugin_textdomain( 'decaldesk', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'decaldesk_load_textdomain' );

add_action( 'plugins_loaded', 'decaldesk_check_woocommerce' );

// ==========================================================
// Includes
// ==========================================================
require_once DECALDESK_PATH . 'includes/parser.php';
require_once DECALDESK_PATH . 'includes/pricing.php';
require_once DECALDESK_PATH . 'includes/ai-content.php';
require_once DECALDESK_PATH . 'includes/mockup.php';
require_once DECALDESK_PATH . 'includes/product.php';
require_once DECALDESK_PATH . 'includes/database.php';
require_once DECALDESK_PATH . 'includes/background.php';
require_once DECALDESK_PATH . 'includes/notices.php';
require_once DECALDESK_PATH . 'includes/settings.php';

require_once DECALDESK_PATH . 'admin/admin-menu.php';

// ==========================================================
// Активиране / Деактивиране
// ==========================================================
function decaldesk_activate() {
    // Създаваме папки за качване, ако не съществуват
    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'] . '/decaldesk';

    foreach ( array( 'incoming', 'mockups', 'templates' ) as $sub ) {
        $dir = $base . '/' . $sub;
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
    }

    // Създаваме DB таблицата за фоновите задачи (jobs)
    decaldesk_create_jobs_table();

    // Настройки по подразбиране
    if ( false === get_option( 'decaldesk_settings' ) ) {
        add_option( 'decaldesk_settings', array(
            'price_per_sqm' => 60,
            'min_price'     => 15,
            'categories'    => array(
                'sticker' => 'Stickers',
                'wrap'    => 'Car Wraps',
                'wall'    => 'Wall Decals',
                'kitchen' => 'Kitchen Backsplash',
            ),
        ) );
    }
}
register_activation_hook( __FILE__, 'decaldesk_activate' );

/**
 * Проверява при всяко зареждане дали нужните upload директории съществуват -
 * важно за работния процес чрез директно презаписване на файлове (rsync/FTP),
 * защото activation hook не се задейства при обикновено презаписване.
 */
function decaldesk_maybe_create_upload_dirs() {
    $upload_dir = wp_upload_dir();
    $base       = $upload_dir['basedir'] . '/decaldesk';

    foreach ( array( 'incoming', 'mockups', 'templates' ) as $sub ) {
        $dir = $base . '/' . $sub;
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
    }
}
add_action( 'admin_init', 'decaldesk_maybe_create_upload_dirs' );

// ==========================================================
// Enqueue admin assets (само на страницата на DecalDesk)
// ==========================================================
function decaldesk_enqueue_admin_assets( $hook ) {
    if ( strpos( $hook, 'decaldesk' ) === false ) {
        return;
    }

    // Ползваме filemtime() на самите файлове като версия вместо статична
    // константа - гарантира, че браузърът винаги дърпа свежа версия след
    // всяка промяна, без риск да забравим ръчно да вдигнем номер (точно
    // това се случи веднъж и доведе до объркващ "счупен" upload екран,
    // докато реално причината беше кеширан стар JS файл в браузъра).
    $style_path    = DECALDESK_PATH . 'assets/css/style.css';
    $uploader_path = DECALDESK_PATH . 'assets/js/uploader.js';

    $style_ver    = file_exists( $style_path ) ? filemtime( $style_path ) : DECALDESK_VERSION;
    $uploader_ver = file_exists( $uploader_path ) ? filemtime( $uploader_path ) : DECALDESK_VERSION;

    wp_enqueue_style(
        'decaldesk-style',
        DECALDESK_URL . 'assets/css/style.css',
        array(),
        $style_ver
    );

    wp_enqueue_script(
        'decaldesk-uploader',
        DECALDESK_URL . 'assets/js/uploader.js',
        array( 'jquery' ),
        $uploader_ver,
        true
    );

    wp_localize_script( 'decaldesk-uploader', 'DecalDeskData', array(
        'ajax_url'  => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'decaldesk_upload_nonce' ),
        'maxFiles'  => DECALDESK_MAX_BATCH_FILES,
        'allowedExtensions' => DECALDESK_ALLOWED_EXTENSIONS,
    ) );

    // JS за drag-box редактора на зоните - само на страницата "Категории"
    if ( false !== strpos( $hook, 'decaldesk-categories' ) ) {
        $categories_path = DECALDESK_PATH . 'assets/js/categories.js';
        $categories_ver  = file_exists( $categories_path ) ? filemtime( $categories_path ) : DECALDESK_VERSION;

        wp_enqueue_script(
            'decaldesk-categories',
            DECALDESK_URL . 'assets/js/categories.js',
            array( 'jquery' ),
            $categories_ver,
            true
        );

        wp_localize_script( 'decaldesk-categories', 'DecalDeskCategoriesData', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'decaldesk_categories_nonce' ),
        ) );
    }
}
add_action( 'admin_enqueue_scripts', 'decaldesk_enqueue_admin_assets' );

// ==========================================================
// Поддръжка / контакт
// ==========================================================
if ( ! defined( 'DECALDESK_SUPPORT_EMAIL' ) ) {
    define( 'DECALDESK_SUPPORT_EMAIL', 'support@decaldesk.com' );
}

/**
 * Заменя стандартния "Thank you for creating with WordPress" футър текст
 * с линк за поддръжка - само на страниците на DecalDesk, не навсякъде в админа.
 */
function decaldesk_admin_footer_text( $text ) {
    $screen = get_current_screen();

    if ( ! $screen || strpos( $screen->id, 'decaldesk' ) === false ) {
        return $text;
    }

    return sprintf(
        /* translators: %s: support email address */
        __( 'DecalDesk — need a hand? Email %s', 'decaldesk' ),
        '<a href="mailto:' . esc_attr( DECALDESK_SUPPORT_EMAIL ) . '">' . esc_html( DECALDESK_SUPPORT_EMAIL ) . '</a>'
    );
}
add_filter( 'admin_footer_text', 'decaldesk_admin_footer_text' );
