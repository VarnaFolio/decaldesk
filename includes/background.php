<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ==========================================================
 * Фоново обработване на качените дизайни (Action Scheduler)
 * ==========================================================
 * Action Scheduler идва вграден в WooCommerce (наша задължителна зависимост),
 * така че не добавя нова библиотека. Разликата спрямо предишния синхронен
 * flow: качването само записва файла и слага job в опашката - истинската
 * обработка (AI описание, мокъп, създаване на продукт) се случва отделно,
 * дори ако браузър табът бъде затворен веднага след качването.
 */

define( 'DECALDESK_JOB_HOOK', 'decaldesk_process_design' );

/**
 * Слага дизайн в опашката за фонова обработка.
 *
 * @param string $file_path            Пълен път до вече качения файл (в incoming/).
 * @param string $original_filename    Оригиналното име на файла (за парсване).
 * @param string $status               'draft' или 'publish'.
 * @param string $file_hash            SHA-256 хеш на съдържанието (за detection на дубликати).
 * @param bool   $use_variants         Дали да се създаде Variable Product с избираеми размер/материал/цвят.
 * @param bool   $generate_all_mockups Дали да се генерира мокъп за ВСЕКИ шаблон на категорията (по-бавно).
 * @return int ID на създадения job (за проследяване от JS).
 */
function decaldesk_queue_design_job( $file_path, $original_filename, $status, $file_hash = '', $use_variants = false, $generate_all_mockups = false ) {
    $job_id = decaldesk_create_job( $original_filename, $file_hash );

    $args = array(
        'job_id'               => $job_id,
        'file_path'            => $file_path,
        'filename'             => $original_filename,
        'status'               => $status,
        'use_variants'         => (bool) $use_variants,
        'generate_all_mockups' => (bool) $generate_all_mockups,
    );

    if ( decaldesk_background_processing_available() ) {
        as_schedule_single_action( time(), DECALDESK_JOB_HOOK, array( $args ), 'decaldesk' );
    } else {
        // Fallback: ако Action Scheduler по някаква причина липсва, пак не
        // обработваме синхронно (AI/мокъп генерирането е твърде бавно за
        // изпълнение вътре в AJAX заявката) - вместо това заявяваме еднократно
        // WP-Cron събитие, което си остава асинхронно спрямо текущата заявка.
        wp_schedule_single_event( time(), DECALDESK_JOB_HOOK, array( $args ) );
    }

    return $job_id;
}

/**
 * Проверява дали Action Scheduler е наличен (идва с активен WooCommerce).
 */
function decaldesk_background_processing_available() {
    return function_exists( 'as_schedule_single_action' );
}
add_action( DECALDESK_JOB_HOOK, 'decaldesk_process_design_job', 10, 1 );

/**
 * Реалната обработка на един дизайн - извиква се от Action Scheduler (или
 * директно като fallback). Съдържа същата логика, която преди беше директно
 * в AJAX handler-а, но сега пише резултата в job таблицата вместо да го
 * връща по AJAX.
 *
 * @param array $args { job_id, file_path, filename, status, use_variants, generate_all_mockups }
 */
function decaldesk_process_design_job( $args ) {
    $job_id               = isset( $args['job_id'] ) ? (int) $args['job_id'] : 0;
    $file_path            = isset( $args['file_path'] ) ? $args['file_path'] : '';
    $filename              = isset( $args['filename'] ) ? $args['filename'] : '';
    $post_status           = isset( $args['status'] ) && 'publish' === $args['status'] ? 'publish' : 'draft';
    $use_variants          = ! empty( $args['use_variants'] );
    $generate_all_mockups  = ! empty( $args['generate_all_mockups'] );

    if ( ! $job_id || ! $file_path || ! file_exists( $file_path ) ) {
        if ( $job_id ) {
            decaldesk_update_job( $job_id, array(
                'status'  => 'error',
                'message' => __( 'The file was missing on the server when processing started.', 'decaldesk' ),
            ) );
        }
        return;
    }

    decaldesk_update_job( $job_id, array( 'status' => 'processing' ) );

    // 1) Парсваме името на файла
    $parsed = decaldesk_parse_filename( $filename );
    if ( is_wp_error( $parsed ) ) {
        decaldesk_update_job( $job_id, array(
            'status'  => 'error',
            'message' => $parsed->get_error_message(),
        ) );
        return;
    }

    // 2) Изчисляваме цена (базова - за Variable Products всяка вариация
    // получава собствена цена според своя размер, това е само за fallback/info)
    $price = decaldesk_calculate_price( $parsed['width'], $parsed['height'] );

    // 3) Генерираме AI описания (или fallback шаблон)
    $ai_content = decaldesk_generate_ai_content( $parsed, $file_path );

    // 4) Генерираме мокъп(и) - по подразбиране само 1 (по-бързо); ако е
    // отметнато "Генерирай мокъпи от всички шаблони", се генерира по един
    // мокъп за ВСЕКИ шаблон на категорията (до 4).
    $mockup_paths = decaldesk_generate_mockup( $file_path, $parsed['category'], $generate_all_mockups );

    if ( is_wp_error( $mockup_paths ) ) {
        decaldesk_update_job( $job_id, array(
            'status'  => 'error',
            'message' => $mockup_paths->get_error_message(),
        ) );
        return;
    }

    // 5) Вграждаме мета описание във ВСЕКИ генериран мокъп
    foreach ( $mockup_paths as $mockup_path ) {
        decaldesk_embed_image_metadata( $mockup_path, $parsed['name'], $ai_content['meta_description'] );
    }

    // 6) Създаваме WooCommerce продукт - Variable (с избираеми размер/материал/
    // цвят) ако е поискано И има конфигурирани размери, иначе обикновен Simple.
    if ( $use_variants && decaldesk_variants_configured() ) {
        $product_id = decaldesk_create_variable_product( $parsed, $mockup_paths, $post_status, $ai_content, $file_path );
    } else {
        $product_id = decaldesk_create_product( $parsed, $price, $mockup_paths, $post_status, $ai_content, $file_path );
    }

    if ( is_wp_error( $product_id ) ) {
        decaldesk_update_job( $job_id, array(
            'status'  => 'error',
            'message' => $product_id->get_error_message(),
        ) );
        return;
    }

    decaldesk_update_job( $job_id, array(
        'status'     => 'done',
        'message'    => sprintf(
            /* translators: %s: the product name */
            __( 'Product "%s" was created successfully.', 'decaldesk' ),
            $parsed['name']
        ),
        'product_id' => $product_id,
        'ai_source'  => isset( $ai_content['source'] ) ? $ai_content['source'] : 'fallback',
        'price'      => $price,
    ) );
}

/**
 * AJAX: връща текущия статус на списък job-ове (за live обновяване в JS).
 * Приема job_ids като comma-separated низ (напр. "12,13,14").
 */
function decaldesk_ajax_job_status() {
    check_ajax_referer( 'decaldesk_upload_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do this.', 'decaldesk' ) ), 403 );
    }

    $ids_param = isset( $_POST['job_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['job_ids'] ) ) : '';
    $job_ids   = array_filter( array_map( 'intval', explode( ',', $ids_param ) ) );

    if ( empty( $job_ids ) ) {
        wp_send_json_success( array( 'jobs' => array() ) );
    }

    $jobs = decaldesk_get_jobs( $job_ids );

    $formatted = array_map( function ( $job ) {
        return array(
            'id'         => (int) $job['id'],
            'filename'   => $job['filename'],
            'status'     => $job['status'],
            'message'    => $job['message'],
            'product_id' => $job['product_id'] ? (int) $job['product_id'] : null,
            'edit_link'  => $job['product_id'] ? get_edit_post_link( (int) $job['product_id'], '' ) : null,
            'ai_source'  => $job['ai_source'],
            'price'      => $job['price'],
        );
    }, $jobs );

    wp_send_json_success( array( 'jobs' => $formatted ) );
}
add_action( 'wp_ajax_decaldesk_job_status', 'decaldesk_ajax_job_status' );
