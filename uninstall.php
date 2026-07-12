<?php
/**
 * Uninstall handler за DecalDesk.
 *
 * WordPress извиква този файл АВТОМАТИЧНО, когато плъгинът бъде изтрит
 * (не само деактивиран) от Приставки → Изтрий. Никога не се извиква ръчно.
 *
 * По подразбиране НЕ трие нищо - потребителят трябва изрично да е включил
 * "Пълно почистване" от DecalDesk → Настройки, преди да изтрие плъгина.
 * Това е нарочно консервативно поведение: ако плъгинът се изтрие временно
 * (напр. за ръчен ъпдейт чрез изтриване + качване наново), данните не се губят.
 *
 * Забележка: WooCommerce продуктите, създадени с плъгина, НИКОГА не се
 * трият тук - те са реални бизнес данни (инвентар на магазина), не вътрешни
 * данни на плъгина. Uninstall.php чисти само собствените опции/файлове на
 * DecalDesk (настройки, дневна AI квота, лог, временни файлове).
 */

// Сигурност: този файл трябва да се изпълнява само от самия WordPress
// core при истинско изтриване на плъгина - не позволяваме директен достъп.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Изпълнява почистването за текущия сайт (или за едно блог в multisite мрежа).
 */
function decaldesk_run_uninstall_cleanup() {
    $settings = get_option( 'decaldesk_settings', array() );

    // Ако потребителят НЕ е включил изрично "Пълно почистване" - не трием нищо.
    if ( empty( $settings['delete_data_on_uninstall'] ) ) {
        return;
    }

    // 1) Изтриваме опциите на плъгина от базата данни
    delete_option( 'decaldesk_settings' );
    delete_option( 'decaldesk_ai_daily_usage' );
    delete_option( 'decaldesk_db_version' );

    // 1б) Изтриваме собствената таблица с фонови задачи (jobs)
    global $wpdb;
    $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'decaldesk_jobs' );

    // 1в) Отменяме евентуални чакащи Action Scheduler задачи от нашата група
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( '', array(), 'decaldesk' );
    }

    // 2) Изтриваме собствената директория на плъгина в uploads/
    //    (incoming/ - оригинални качени дизайни, mockups/ - генерирани мокъпи,
    //    logs/ - лог файлове). Тук НЕ пипаме стандартната WP медия библиотека -
    //    мокъпите, вече прикачени като продуктови снимки, си остават в
    //    стандартните uploads/YYYY/MM/ папки и не се трият от тук.
    $upload_dir      = wp_upload_dir();
    $decaldesk_dir  = $upload_dir['basedir'] . '/decaldesk';

    if ( is_dir( $decaldesk_dir ) ) {
        decaldesk_recursive_delete_dir( $decaldesk_dir );
    }
}

/**
 * Рекурсивно изтрива директория и цялото ѝ съдържание.
 * Проста нативна PHP имплементация - без WP_Filesystem, защото в контекста
 * на uninstall.php почти винаги имаме директен файлов достъп, а инициализацията
 * на WP_Filesystem понякога изисква FTP credentials на споделен хостинг.
 *
 * @param string $dir Пълен път до директорията за изтриване.
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
            @unlink( $path );
        }
    }

    @rmdir( $dir );
}

// Поддръжка на multisite: ако плъгинът е бил активиран мрежово, почистваме
// за всеки сайт в мрежата поотделно (всеки сайт си има собствени опции/uploads).
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
