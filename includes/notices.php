<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ==========================================================
 * Admin notices - по-добра видимост на проблеми
 * ==========================================================
 * Досега грешките отиваха само в лог файл, до който трудно се стига.
 * Тук показваме кратко, ясно предупреждение директно в админ панела,
 * само на страниците на DecalDesk (за да не досажда навсякъде другаде),
 * и само ако наистина има за какво да предупредим.
 *
 * Всеки тип известие може да бъде затворен от потребителя за 24 часа
 * (transient per user), за да не се повтаря на всяко презареждане.
 */

/**
 * Обработва линка "Скрий за 24 часа" - трябва да е на admin_init (преди да
 * са изпратени headers), за да можем да пренасочим и да изчистим query
 * параметъра от адреса след запазването.
 */
function decaldesk_handle_notice_dismiss() {
	if ( ! isset( $_GET['decaldesk_dismiss_notice'] ) ) {
		return;
	}

	if ( ! check_admin_referer( 'decaldesk_dismiss_notice' ) ) {
		return;
	}

	$notice_key = sanitize_key( wp_unslash( $_GET['decaldesk_dismiss_notice'] ) );
	decaldesk_dismiss_notice( $notice_key );

	$clean_url = remove_query_arg( array( 'decaldesk_dismiss_notice', '_wpnonce' ) );
	wp_safe_redirect( $clean_url );
	exit;
}
add_action( 'admin_init', 'decaldesk_handle_notice_dismiss' );

/**
 * ==========================================================
 * "Getting started" чеклист - вижда се само докато инсталацията изглежда
 * все още "прясна" (примерните категории/цена от активацията, никакъв
 * реален качен дизайн). Целта е новият потребител да не остане несъзнателно
 * с примерните категории "Stickers/Car Wraps/Wall Decals/Kitchen Backsplash"
 * и generic мокъп шаблон завинаги - плъгинът работи технически още от
 * активацията (вградени defaults), но резултатът няма да пасне на реалния
 * му бизнес, докато не прегледа тези стъпки.
 * ==========================================================
 */

/**
 * Стъпките на чеклиста, всяка с 'done' статус, изведен от реалното
 * състояние на настройките - не флаг, който трябва ръчно да се тика.
 */
function decaldesk_get_onboarding_steps() {
	$settings = get_option( 'decaldesk_settings', array() );

	$default_categories = array(
		'sticker' => 'Stickers',
		'wrap'    => 'Car Wraps',
		'wall'    => 'Wall Decals',
		'kitchen' => 'Kitchen Backsplash',
	);

	$categories             = isset( $settings['categories'] ) ? $settings['categories'] : array();
	$categories_customized  = $categories !== $default_categories;
	$settings_reviewed      = ! empty( $settings['onboarding_settings_reviewed'] );
	$has_custom_template    = ! empty( $settings['template_zones'] );
	$has_upload             = function_exists( 'decaldesk_count_jobs' ) && decaldesk_count_jobs() > 0;

	return array(
		'settings'   => array(
			'label' => __( 'Review your price per m² and minimum price (currently using example defaults)', 'decaldesk' ),
			'done'  => $settings_reviewed,
			'url'   => admin_url( 'admin.php?page=decaldesk-settings' ),
		),
		'categories' => array(
			'label' => __( 'Set up your own categories (currently the example ones: Stickers, Car Wraps, Wall Decals, Kitchen Backsplash)', 'decaldesk' ),
			'done'  => $categories_customized,
			'url'   => admin_url( 'admin.php?page=decaldesk-categories' ),
		),
		'template'   => array(
			'label' => __( 'Upload a mockup template for your first category (optional - a generic placeholder is used until then)', 'decaldesk' ),
			'done'  => $has_custom_template,
			'url'   => admin_url( 'admin.php?page=decaldesk-categories' ),
		),
		'upload'     => array(
			'label' => __( 'Upload your first design file', 'decaldesk' ),
			'done'  => $has_upload,
			'url'   => admin_url( 'admin.php?page=decaldesk' ),
		),
	);
}

function decaldesk_render_onboarding_checklist() {
	$screen = get_current_screen();
	if ( ! $screen || strpos( $screen->id, 'decaldesk' ) === false ) {
		return;
	}

	if ( get_option( 'decaldesk_onboarding_dismissed' ) ) {
		return;
	}

	$steps    = decaldesk_get_onboarding_steps();
	$all_done = true;
	foreach ( $steps as $step ) {
		if ( ! $step['done'] ) {
			$all_done = false;
			break;
		}
	}

	// Всички стъпки готови - вече няма смисъл да заема място, скриваме го за постоянно.
	if ( $all_done ) {
		update_option( 'decaldesk_onboarding_dismissed', 1 );
		return;
	}

	$dismiss_url = wp_nonce_url(
		add_query_arg( 'decaldesk_dismiss_onboarding', '1' ),
		'decaldesk_dismiss_onboarding'
	);
	?>
	<div class="notice notice-info decaldesk-notice decaldesk-onboarding-checklist">
		<p><strong><?php esc_html_e( 'Getting started with DecalDesk', 'decaldesk' ); ?></strong></p>
		<ul class="decaldesk-onboarding-list">
			<?php foreach ( $steps as $step ) : ?>
				<li class="<?php echo $step['done'] ? 'decaldesk-onboarding-step-done' : 'decaldesk-onboarding-step-pending'; ?>">
					<?php if ( $step['done'] ) : ?>
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<span><?php echo esc_html( $step['label'] ); ?></span>
					<?php else : ?>
						<span class="dashicons dashicons-marker" aria-hidden="true"></span>
						<a href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p><a href="<?php echo esc_url( $dismiss_url ); ?>" class="decaldesk-notice-dismiss-link"><?php esc_html_e( 'Dismiss', 'decaldesk' ); ?></a></p>
	</div>
	<?php
}
add_action( 'admin_notices', 'decaldesk_render_onboarding_checklist', 5 );

/**
 * Обработва линка "Dismiss" на "Getting started" чеклиста - за разлика от
 * другите известия (24ч transient), този е скрит за постоянно, защото не е
 * повтарящо се предупреждение, а еднократно упътване.
 */
function decaldesk_handle_onboarding_dismiss() {
	if ( ! isset( $_GET['decaldesk_dismiss_onboarding'] ) ) {
		return;
	}

	if ( ! check_admin_referer( 'decaldesk_dismiss_onboarding' ) ) {
		return;
	}

	update_option( 'decaldesk_onboarding_dismissed', 1 );

	$clean_url = remove_query_arg( array( 'decaldesk_dismiss_onboarding', '_wpnonce' ) );
	wp_safe_redirect( $clean_url );
	exit;
}
add_action( 'admin_init', 'decaldesk_handle_onboarding_dismiss' );

function decaldesk_render_admin_notices() {
	// Показваме само на страниците на самия плъгин
	$screen = get_current_screen();
	if ( ! $screen || strpos( $screen->id, 'decaldesk' ) === false ) {
		return;
	}

	$health = decaldesk_get_recent_job_health( 7 );

	/*! <fs_premium_only> */
	// --- Notice 1: висок дял fallback описания вместо AI ---
	$settings        = get_option( 'decaldesk_settings', array() );
	$ai_provider     = ! empty( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'none';
	$total_generated = $health['fallback_count'] + $health['ai_success_count'];

	if ( 'none' !== $ai_provider && $health['fallback_count'] > 0 && ! decaldesk_notice_dismissed( 'fallback_ratio' ) ) {
		$settings_url = admin_url( 'admin.php?page=decaldesk-settings' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( 'decaldesk_dismiss_notice', 'fallback_ratio' ),
			'decaldesk_dismiss_notice'
		);
		?>
		<div class="notice notice-warning is-dismissible decaldesk-notice">
			<p>
				<strong><?php esc_html_e( 'DecalDesk:', 'decaldesk' ); ?></strong>
				<?php
				printf(
					/* translators: 1: number of products with a fallback description, 2: total descriptions generated over the past 7 days */
					esc_html__( '%1$d of %2$d products over the past week used the static template instead of an AI description.', 'decaldesk' ),
					(int) $health['fallback_count'],
					(int) $total_generated
				);
				?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Check your AI settings →', 'decaldesk' ); ?></a>
			</p>
			<a href="<?php echo esc_url( $dismiss_url ); ?>" class="decaldesk-notice-dismiss-link"><?php esc_html_e( 'Hide for 24 hours', 'decaldesk' ); ?></a>
		</div>
		<?php
	}
	/*! </fs_premium_only> */

	// --- Notice 2: грешки при обработка ---
	if ( $health['error_count'] > 0 && ! decaldesk_notice_dismissed( 'processing_errors' ) ) {
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'decaldesk_dismiss_notice', 'processing_errors' ),
			'decaldesk_dismiss_notice'
		);
		?>
		<div class="notice notice-error is-dismissible decaldesk-notice">
			<p>
				<strong><?php esc_html_e( 'DecalDesk:', 'decaldesk' ); ?></strong>
				<?php
				printf(
					/* translators: %d: number of failed processing attempts over the past 7 days */
					esc_html__( '%d products failed to process successfully over the past week.', 'decaldesk' ),
					(int) $health['error_count']
				);
				?>
				<?php if ( $health['last_error_message'] ) : ?>
					<br><em><?php esc_html_e( 'Last error:', 'decaldesk' ); ?></em>
					"<?php echo esc_html( $health['last_error_message'] ); ?>"
				<?php endif; ?>
			</p>
			<a href="<?php echo esc_url( $dismiss_url ); ?>" class="decaldesk-notice-dismiss-link"><?php esc_html_e( 'Hide for 24 hours', 'decaldesk' ); ?></a>
		</div>
		<?php
	}
}
add_action( 'admin_notices', 'decaldesk_render_admin_notices' );

/**
 * Проверява дали текущият потребител е скрил това известие през последните 24ч.
 */
function decaldesk_notice_dismissed( $notice_key ) {
	return (bool) get_transient( 'decaldesk_notice_dismissed_' . $notice_key . '_' . get_current_user_id() );
}

/**
 * Скрива известие за текущия потребител за 24 часа.
 */
function decaldesk_dismiss_notice( $notice_key ) {
	set_transient( 'decaldesk_notice_dismissed_' . $notice_key . '_' . get_current_user_id(), 1, DAY_IN_SECONDS );
}
