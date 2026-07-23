<?php
/**
 * Plugin Name:       DecalDesk
 * Plugin URI:        https://decaldesk.com
 * Description:       Автоматизирано създаване на WooCommerce продукти от дизайн файлове — парсване на име, ценообразуване по площ, AI описания, мокъп генериране, размерни варианти.
 * Version:           1.5.13
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Tested up to:      7.0
 * WC requires at least: 8.0
 * WC tested up to:   10.9
 * Author:            DecalDesk
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
// каноничният update/license сървър. Тази версия (WP.org) е is_premium=false
// и не съдържа код за Pro-only функции (те живеят само в DecalDesk Pro).
if ( ! function_exists( 'decaldesk_fs' ) ) {
	function decaldesk_fs() {
		global $decaldesk_fs;

		if ( ! isset( $decaldesk_fs ) ) {
			require_once __DIR__ . '/vendor/freemius/start.php';

			$decaldesk_fs = fs_dynamic_init(
				array(
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
						'support' => false,
					),
				)
			);
		}

		return $decaldesk_fs;
	}

	decaldesk_fs();
	do_action( 'decaldesk_fs_loaded' );
}

// ==========================================================
// Константи
// ==========================================================
define( 'DECALDESK_VERSION', '1.5.13' );
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
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

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
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'DecalDesk requires WooCommerce to be active in order to work.', 'decaldesk' );
				echo '</p></div>';
			}
		);
		return false;
	}
	return true;
}
// ==========================================================
// Преводи (i18n)
// ==========================================================
// Source кодът е на английски (стандартна WordPress конвенция) - всеки
// друг език, включително български, идва като превод от languages/.
// Ръчно load_plugin_textdomain() НЕ е нужно за плъгини, хоствани в
// WordPress.org директорията - WordPress автоматично зарежда преводите
// по slug-а на плъгина от версия 4.6 насам.

// ==========================================================
// Инициализация - includes/admin менюто се зареждат САМО ако WooCommerce
// е активен. Това предпазва от ситуацията, в която WooCommerce бъде
// деактивиран, докато DecalDesk остава активен (напр. по невнимание) -
// без тази проверка менюто, AJAX handler-ите и admin_init хуковете щяха
// да се регистрират и изпълняват независимо от липсващия WooCommerce,
// с риск от fatal грешки при извикване на WC-специфични функции
// (напр. wc_price(), wc_get_product()).
// ==========================================================
function decaldesk_init_plugin() {
	if ( ! decaldesk_check_woocommerce() ) {
		return;
	}

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

	// Ако плъгинът е бил активен вече ПРЕДИ версията, в която е добавен този
	// cron (т.е. активиран е FTP/rsync ъпдейт, без деактивиране+активиране -
	// activation hook-ът няма да се изпълни в такъв случай), се презастраховаме
	// тук - wp_next_scheduled() е евтина проверка, безопасно е да е на всяка заявка.
	if ( ! wp_next_scheduled( 'decaldesk_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'decaldesk_daily_cleanup' );
	}
}
add_action( 'plugins_loaded', 'decaldesk_init_plugin', 20 );

// ==========================================================
// Активиране / Деактивиране
// ==========================================================
function decaldesk_activate() {
	// register_activation_hook се задейства веднага при клик на "Активирай",
	// в същата заявка, но ПРЕДИ plugins_loaded (decaldesk_init_plugin()) да
	// е стигнал до зареждане на includes/database.php - затова тук изрично
	// изискваме файла, вместо да разчитаме на обичайния ред на зареждане.
	require_once DECALDESK_PATH . 'includes/database.php';

	// Създаваме папки за качване, ако не съществуват
	$upload_dir = wp_upload_dir();
	$base       = $upload_dir['basedir'] . '/decaldesk';

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
		add_option(
			'decaldesk_settings',
			array(
				'price_per_sqm' => 60,
				'min_price'     => 15,
				'categories'    => array(
					'sticker' => 'Stickers',
					'wrap'    => 'Car Wraps',
					'wall'    => 'Wall Decals',
					'kitchen' => 'Kitchen Backsplash',
				),
			)
		);
	}

	// Дневно автоматично почистване на стара история (jobs таблица + евентуални
	// осиротели incoming/ файлове) - виж decaldesk_cleanup_old_jobs() в
	// includes/database.php. Не пипа реалните вече създадени продукти.
	if ( ! wp_next_scheduled( 'decaldesk_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'decaldesk_daily_cleanup' );
	}
}
register_activation_hook( __FILE__, 'decaldesk_activate' );

/**
 * Проверява при всяко зареждане дали нужните upload директории съществуват -
 * важно за работния процес чрез директно презаписване на файлове (rsync/FTP),
 * защото activation hook не се задейства при обикновено презаписване.
 */
function decaldesk_maybe_create_upload_dirs() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

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
// Деактивиране
// ==========================================================
// НЕ трие потребителски данни (опции, DB таблица, качени файлове) - само
// спира чакащите фонови задачи (Action Scheduler), за да не се изпълнят
// докато плъгинът е неактивен и после плъгинът бъде премахнат другояче.
function decaldesk_deactivate() {
	// Ползваме литералния hook name, а не DECALDESK_JOB_HOOK константата -
	// тя се дефинира в includes/background.php, който се зарежда само ако
	// WooCommerce е активен (виж decaldesk_init_plugin()); при деактивиране
	// на DecalDesk точно защото WooCommerce вече липсва, константата няма
	// да съществува.
	$job_hook = 'decaldesk_process_design';

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( '', array(), 'decaldesk' );
	}

	$timestamp = wp_next_scheduled( $job_hook );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, $job_hook );
	}

	$cleanup_timestamp = wp_next_scheduled( 'decaldesk_daily_cleanup' );
	if ( $cleanup_timestamp ) {
		wp_unschedule_event( $cleanup_timestamp, 'decaldesk_daily_cleanup' );
	}
}
register_deactivation_hook( __FILE__, 'decaldesk_deactivate' );

// ==========================================================
// Деинсталиране (през Freemius after_uninstall hook)
// ==========================================================
// По подразбиране НЕ трие нищо - потребителят трябва изрично да е включил
// "Пълно почистване" от DecalDesk → Настройки, преди да изтрие плъгина.
// Това е нарочно консервативно поведение: ако плъгинът се изтрие временно
// (напр. за ръчен ъпдейт чрез изтриване + качване наново), данните не се губят.
//
// WooCommerce продуктите, създадени с плъгина, НИКОГА не се трият тук - те
// са реални бизнес данни (инвентар на магазина), не вътрешни данни на
// плъгина. Тук чистим само собствените опции/файлове на DecalDesk
// (настройки, дневна AI квота, лог, временни файлове).
//
// Ползваме decaldesk_fs()->add_action('after_uninstall', ...) като единствен
// механизъм (вместо и самостоятелен uninstall.php) - Freemius изисква това,
// защото сам прихваща/показва диалога за деинсталиране и координира кога
// точно да се задейства почистването.
function decaldesk_run_uninstall_cleanup() {
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

	wp_clear_scheduled_hook( 'decaldesk_daily_cleanup' );

	$upload_dir    = wp_upload_dir();
	$decaldesk_dir = $upload_dir['basedir'] . '/decaldesk';

	if ( is_dir( $decaldesk_dir ) ) {
		decaldesk_recursive_delete_dir( $decaldesk_dir );
	}
}

/**
 * Рекурсивно изтрива директория и цялото ѝ съдържание.
 */
function decaldesk_recursive_delete_dir( $dir ) {
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
			decaldesk_recursive_delete_dir( $path );
		} else {
			wp_delete_file( $path );
		}
	}

	@rmdir( $dir );
}

/**
 * Поддръжка на multisite: ако плъгинът е бил активиран мрежово, почистваме
 * за всеки сайт в мрежата поотделно (всеки сайт си има собствени опции/uploads).
 */
function decaldesk_run_uninstall_cleanup_all_sites() {
	if ( is_multisite() ) {
		$site_ids = get_sites( array( 'fields' => 'ids' ) );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			decaldesk_run_uninstall_cleanup();
			restore_current_blog();
		}
	} else {
		decaldesk_run_uninstall_cleanup();
	}
}
decaldesk_fs()->add_action( 'after_uninstall', 'decaldesk_run_uninstall_cleanup_all_sites' );

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
		array( 'jquery', 'wp-i18n' ),
		$uploader_ver,
		true
	);

	// Прави wp.i18n.__()/sprintf() в uploader.js преводими - JSON преводния
	// файл се търси в languages/ по стандартната конвенция decaldesk-{locale}-{handle-hash}.json
	// (генерира се с `wp i18n make-json`).
	wp_set_script_translations( 'decaldesk-uploader', 'decaldesk', DECALDESK_PATH . 'languages' );

	$decaldesk_settings = get_option( 'decaldesk_settings', array() );

	wp_localize_script(
		'decaldesk-uploader',
		'DecalDeskData',
		array(
			'ajax_url'          => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( 'decaldesk_upload_nonce' ),
			'maxFiles'          => DECALDESK_MAX_BATCH_FILES,
			'allowedExtensions' => DECALDESK_ALLOWED_EXTENSIONS,
			// За live преглед/валидация на файловото име в браузъра, ПРЕДИ
			// реалното качване - огледва decaldesk_parse_filename() (includes/parser.php).
			'categories'        => isset( $decaldesk_settings['categories'] ) ? $decaldesk_settings['categories'] : array(),
			'maxDimensionCm'    => ! empty( $decaldesk_settings['max_dimension_cm'] ) ? (int) $decaldesk_settings['max_dimension_cm'] : 1000,
		)
	);

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

		wp_localize_script(
			'decaldesk-categories',
			'DecalDeskCategoriesData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'decaldesk_categories_nonce' ),
				'isPro'    => decaldesk_fs()->can_use_premium_code(),
				'maxSlots' => decaldesk_max_template_slots(),
			)
		);
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
