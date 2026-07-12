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

function decaldesk_render_admin_notices() {
    // Показваме само на страниците на самия плъгин
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'decaldesk' ) === false ) {
        return;
    }

    $health = decaldesk_get_recent_job_health( 7 );

    // --- Notice 1: висок дял fallback описания вместо AI ---
    $settings = get_option( 'decaldesk_settings', array() );
    $ai_provider = ! empty( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'none';
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
