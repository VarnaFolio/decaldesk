<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Рендер на екрана за качване (drag & drop + опции чернова/публикуване)
 */
function decaldesk_render_upload_page() {
    $settings = get_option( 'decaldesk_settings', array() );
    ?>
    <div class="wrap decaldesk-wrap">
        <h1><?php esc_html_e( 'DecalDesk – Upload Designs', 'decaldesk' ); ?></h1>

        <div class="decaldesk-upload-box">
            <div id="decaldesk-dropzone" class="decaldesk-dropzone" tabindex="0" role="button"
                 aria-label="<?php esc_attr_e( 'Drop files here or press Enter to browse for files', 'decaldesk' ); ?>">
                <p><?php esc_html_e( 'Drop files here or click to browse', 'decaldesk' ); ?></p>
                <p class="decaldesk-hint">
                    <?php esc_html_e( 'Filename format: name_widthxheight_material_category.extension', 'decaldesk' ); ?><br>
                    <?php esc_html_e( 'Example: holiday_50x70_matte_kitchen.jpg', 'decaldesk' ); ?><br>
                    <?php esc_html_e( 'Allowed formats: PNG, JPG/JPEG, WEBP, GIF', 'decaldesk' ); ?><br>
                    <?php
                    printf(
                        /* translators: %d: maximum number of files at once */
                        esc_html__( 'Maximum %d files at once', 'decaldesk' ),
                        (int) DECALDESK_MAX_BATCH_FILES
                    );
                    ?>
                </p>
            </div>

            <input type="file" id="decaldesk-file-input" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden>

            <div id="decaldesk-file-summary" class="decaldesk-file-summary" style="display:none;">
                <span id="decaldesk-file-count"></span>
                <button type="button" id="decaldesk-clear-files" class="button-link">
                    <?php esc_html_e( 'Clear all', 'decaldesk' ); ?>
                </button>
            </div>

            <div id="decaldesk-file-list" class="decaldesk-file-list"></div>

            <div class="decaldesk-options">
                <label>
                    <input type="radio" name="decaldesk_status" value="draft" checked>
                    <?php esc_html_e( 'Save as draft', 'decaldesk' ); ?>
                </label>
                <label>
                    <input type="radio" name="decaldesk_status" value="publish">
                    <?php esc_html_e( 'Publish immediately', 'decaldesk' ); ?>
                </label>
            </div>

            <?php /*! <fs_premium_only> */ if ( true ) : ?>
            <?php
            $variant_sizes     = isset( $settings['variant_sizes'] ) ? $settings['variant_sizes'] : array();
            $variant_materials = isset( $settings['variant_materials'] ) ? $settings['variant_materials'] : array();
            $variant_colors    = isset( $settings['variant_colors'] ) ? $settings['variant_colors'] : array();
            ?>
            <?php $decaldesk_variants_is_pro = decaldesk_fs()->can_use_premium_code(); ?>
            <div class="decaldesk-options decaldesk-variants-option">
                <label>
                    <input type="checkbox" id="decaldesk-use-variants" name="decaldesk_use_variants" value="1"
                        <?php disabled( empty( $variant_sizes ) || ! $decaldesk_variants_is_pro ); ?>>
                    <?php esc_html_e( 'Create with selectable variants (size/material/color)', 'decaldesk' ); ?>
                    <?php if ( ! $decaldesk_variants_is_pro ) : ?>
                        <span class="decaldesk-pro-badge"><?php esc_html_e( 'Pro', 'decaldesk' ); ?></span>
                    <?php endif; ?>
                </label>

                <p class="description" id="decaldesk-variants-summary">
                    <?php if ( ! $decaldesk_variants_is_pro ) : ?>
                        <?php esc_html_e( 'Selectable size/material/color variants require a Pro license.', 'decaldesk' ); ?>
                        <a href="<?php echo esc_url( decaldesk_fs()->get_upgrade_url() ); ?>"><?php esc_html_e( 'Upgrade to Pro', 'decaldesk' ); ?></a>
                    <?php elseif ( empty( $variant_sizes ) ) : ?>
                        <?php esc_html_e( 'No variant sizes configured yet — add at least one below to enable this option.', 'decaldesk' ); ?>
                    <?php else : ?>
                        <?php
                        printf(
                            /* translators: %s: list of configured sizes */
                            esc_html__( 'Every uploaded design will become one product with a choice of: %s', 'decaldesk' ),
                            esc_html( implode( ', ', $variant_sizes ) . ' cm' )
                        );
                        ?>
                    <?php endif; ?>
                </p>

                <button type="button" id="decaldesk-toggle-variant-config" class="button-link" <?php disabled( ! $decaldesk_variants_is_pro ); ?>>
                    <?php esc_html_e( 'Configure sizes / materials / colors ▾', 'decaldesk' ); ?>
                </button>

                <div id="decaldesk-variant-config-panel" style="display:none;">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="decaldesk-variant-sizes-input"><?php esc_html_e( 'Sizes (required)', 'decaldesk' ); ?></label>
                            </th>
                            <td>
                                <textarea id="decaldesk-variant-sizes-input" rows="4" class="regular-text"
                                          placeholder="30x40&#10;50x70&#10;70x100"><?php echo esc_textarea( implode( "\n", $variant_sizes ) ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'One size per line, format "widthxheight" in cm.', 'decaldesk' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="decaldesk-variant-materials-input"><?php esc_html_e( 'Materials (optional)', 'decaldesk' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="decaldesk-variant-materials-input" class="large-text"
                                       value="<?php echo esc_attr( implode( ', ', $variant_materials ) ); ?>"
                                       placeholder="<?php esc_attr_e( 'e.g. matte, gloss, clear', 'decaldesk' ); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="decaldesk-variant-colors-input"><?php esc_html_e( 'Colors (optional)', 'decaldesk' ); ?></label>
                            </th>
                            <td>
                                <input type="text" id="decaldesk-variant-colors-input" class="large-text"
                                       value="<?php echo esc_attr( implode( ', ', $variant_colors ) ); ?>"
                                       placeholder="<?php esc_attr_e( 'e.g. white, black, clear film', 'decaldesk' ); ?>">
                            </td>
                        </tr>
                    </table>
                    <button type="button" id="decaldesk-save-variant-config-btn" class="button button-primary">
                        <?php esc_html_e( 'Save variants', 'decaldesk' ); ?>
                    </button>
                    <span id="decaldesk-variant-config-status"></span>
                </div>
            </div>

            <div class="decaldesk-options decaldesk-multi-mockup-option">
                <label>
                    <input type="checkbox" id="decaldesk-generate-all-mockups" name="decaldesk_generate_all_mockups" value="1" <?php disabled( ! decaldesk_fs()->can_use_premium_code() ); ?>>
                    <?php esc_html_e( 'Generate mockups from all templates in the category (up to 4)', 'decaldesk' ); ?>
                    <?php if ( ! decaldesk_fs()->can_use_premium_code() ) : ?>
                        <span class="decaldesk-pro-badge"><?php esc_html_e( 'Pro', 'decaldesk' ); ?></span>
                    <?php endif; ?>
                </label>
                <p class="description">
                    <?php if ( ! decaldesk_fs()->can_use_premium_code() ) : ?>
                        <?php esc_html_e( 'Multiple mockup templates per category require a Pro license.', 'decaldesk' ); ?>
                        <a href="<?php echo esc_url( decaldesk_fs()->get_upgrade_url() ); ?>"><?php esc_html_e( 'Upgrade to Pro', 'decaldesk' ); ?></a>
                    <?php else : ?>
                        <?php esc_html_e( 'Useful for categories with several templates (e.g. "cars" — show the design on a few different models). Slower processing, so it\'s off by default — turn it on only when you actually need it for a specific batch.', 'decaldesk' ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <?php else : /*! </fs_premium_only> */ ?>
            <div class="decaldesk-options decaldesk-variants-option">
                <p class="description">
                    <span class="decaldesk-pro-badge"><?php esc_html_e( 'DecalDesk Pro', 'decaldesk' ); ?></span>
                    <?php esc_html_e( 'Selectable size/material/color variants (Variable Products) are available in DecalDesk Pro. Every design uploaded here is created as a Simple Product.', 'decaldesk' ); ?>
                    <a href="https://decaldesk.com/#pricing-calc" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'decaldesk' ); ?></a>
                </p>
            </div>
            <?php /*! <fs_premium_only> */ endif; /*! </fs_premium_only> */ ?>

            <button id="decaldesk-upload-btn" class="button button-primary button-hero">
                <?php esc_html_e( 'Upload files', 'decaldesk' ); ?>
            </button>

            <div id="decaldesk-progress" class="decaldesk-progress" style="display:none;">
                <div class="decaldesk-progress-bar"></div>
                <span id="decaldesk-progress-label" class="decaldesk-progress-label"></span>
            </div>

            <div id="decaldesk-summary" class="decaldesk-summary" style="display:none;"></div>

            <div id="decaldesk-results" class="decaldesk-results"></div>
        </div>
    </div>
    <?php
}

/**
 * AJAX handler: обработва качения PNG файл
 */
function decaldesk_handle_upload() {
    check_ajax_referer( 'decaldesk_upload_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do this.', 'decaldesk' ) ), 403 );
    }

    if ( empty( $_FILES['file'] ) ) {
        wp_send_json_error( array( 'message' => __( 'No file received.', 'decaldesk' ) ), 400 );
    }

    $file = $_FILES['file'];
    // Sanitize веднага, преди файловото име да се ползва за parsing, съобщения
    // или съхранение - $_FILES['name'] идва директно от клиента, непроверено.
    $file['name'] = sanitize_file_name( $file['name'] );
    $status = isset( $_POST['status'] ) && 'publish' === $_POST['status'] ? 'publish' : 'draft';
    $use_variants = ! empty( $_POST['use_variants'] );
    $generate_all_mockups = ! empty( $_POST['generate_all_mockups'] );

    // Проверка дали самото качване е минало без грешка (прекъснат ъплоуд, превишен размер и т.н.)
    if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
        wp_send_json_error( array( 'message' => decaldesk_upload_error_message( $file['error'] ?? -1 ) ), 400 );
    }

    // Валидация на разширението (бърза, повърхностна проверка - позволени: PNG, JPG/JPEG, WEBP, GIF)
    $filetype = wp_check_filetype( $file['name'] );
    $ext      = strtolower( $filetype['ext'] );

    if ( ! in_array( $ext, DECALDESK_ALLOWED_EXTENSIONS, true ) ) {
        wp_send_json_error( array(
            'message' => sprintf(
                /* translators: %s: list of allowed extensions */
                __( 'Allowed formats: %s.', 'decaldesk' ),
                strtoupper( implode( ', ', DECALDESK_ALLOWED_EXTENSIONS ) )
            ),
        ), 400 );
    }

    // Реална проверка на СЪДЪРЖАНИЕТО на файла (не само разширението) - защитава
    // срещу файл, преименуван на .png, който всъщност не е валидно изображение.
    $content_check = decaldesk_validate_image_content( $file['tmp_name'] );
    if ( is_wp_error( $content_check ) ) {
        wp_send_json_error( array( 'message' => $content_check->get_error_message() ), 400 );
    }

    // Detection на дубликат - хешираме съдържанието и проверяваме дали този
    // точен файл вече е бил качен (и не е паднал с грешка). Пази от случайно
    // качване на един и същ дизайн два пъти -> два отделни продукта.
    $file_hash = hash_file( 'sha256', $file['tmp_name'] );
    $duplicate = $file_hash ? decaldesk_find_duplicate_job( $file_hash ) : null;

    if ( $duplicate ) {
        $duplicate_message = sprintf(
            /* translators: %s: the original filename from the previous upload */
            __( 'This design has already been uploaded before (as "%s").', 'decaldesk' ),
            $duplicate['filename']
        );

        $duplicate_edit_link = null;

        if ( 'done' === $duplicate['status'] && $duplicate['product_id'] ) {
            $duplicate_message  .= ' ' . __( 'See the existing product below.', 'decaldesk' );
            $duplicate_edit_link = get_edit_post_link( (int) $duplicate['product_id'], '' );
        } else {
            $duplicate_message .= ' ' . __( 'It\'s currently being processed.', 'decaldesk' );
        }

        wp_send_json_error( array(
            'message'   => $duplicate_message,
            'edit_link' => $duplicate_edit_link,
        ), 409 );
    }

    // 1) Парсваме името на файла (валидираме формата ВЕДНАГА - бърз fail, не хаби queue slot)
    $parsed = decaldesk_parse_filename( $file['name'] );
    if ( is_wp_error( $parsed ) ) {
        wp_send_json_error( array( 'message' => $parsed->get_error_message() ), 400 );
    }

    // 2) Преместваме файла в incoming/ - през стандартната WordPress upload
    //    обработка (не с директно преместване на temp файла), после
    //    релокираме вече валидирания файл в нашата собствена incoming/ структура.
    $upload_dir   = wp_upload_dir();
    $incoming_dir = $upload_dir['basedir'] . '/decaldesk/incoming';
    $target_path  = $incoming_dir . '/' . sanitize_file_name( $file['name'] );

    require_once ABSPATH . 'wp-admin/includes/file.php';

    $handled = wp_handle_upload( $file, array( 'test_form' => false ) );

    if ( isset( $handled['error'] ) ) {
        wp_send_json_error( array( 'message' => $handled['error'] ) );
    }

    if ( ! @rename( $handled['file'], $target_path ) ) {
        wp_delete_file( $handled['file'] );
        wp_send_json_error( array( 'message' => __( 'Failed to save the file to the server.', 'decaldesk' ) ), 500 );
    }

    // 3) Слагаме дизайна в опашката за фонова обработка (AI + мокъп + продукт).
    //    Обработката продължава дори табът да бъде затворен веднага след това.
    $job_id = decaldesk_queue_design_job( $target_path, $file['name'], $status, $file_hash, $use_variants, $generate_all_mockups );

    wp_send_json_success( array(
        'message' => sprintf(
            /* translators: %s: the filename */
            __( '"%s" has been uploaded and queued for processing.', 'decaldesk' ),
            $file['name']
        ),
        'job_id'  => $job_id,
        'queued'  => true,
    ) );
}
add_action( 'wp_ajax_decaldesk_upload', 'decaldesk_handle_upload' );

/*! <fs_premium_only> */
/**
 * AJAX handler: запазва конфигурацията за размерни варианти (размери/материали/
 * цветове) директно от екрана "Качване" - вместо да се налага да се ходи до
 * отделна страница с настройки.
 */
function decaldesk_ajax_save_variant_config() {
    check_ajax_referer( 'decaldesk_upload_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do this.', 'decaldesk' ) ), 403 );
    }

    $sizes     = isset( $_POST['sizes'] ) ? wp_unslash( $_POST['sizes'] ) : '';
    $materials = isset( $_POST['materials'] ) ? wp_unslash( $_POST['materials'] ) : '';
    $colors    = isset( $_POST['colors'] ) ? wp_unslash( $_POST['colors'] ) : '';

    $settings = get_option( 'decaldesk_settings', array() );
    $settings['variant_sizes']     = decaldesk_sanitize_size_list( $sizes );
    $settings['variant_materials'] = decaldesk_sanitize_csv_list( $materials );
    $settings['variant_colors']    = decaldesk_sanitize_csv_list( $colors );

    update_option( 'decaldesk_settings', $settings );

    // Препрочитаме обратно от базата, за да върнем реално запазените (вече
    // санитизирани) стойности - UI-ът отразява точно това, което е записано.
    $saved = get_option( 'decaldesk_settings', array() );

    wp_send_json_success( array(
        'sizes'     => isset( $saved['variant_sizes'] ) ? $saved['variant_sizes'] : array(),
        'materials' => isset( $saved['variant_materials'] ) ? $saved['variant_materials'] : array(),
        'colors'    => isset( $saved['variant_colors'] ) ? $saved['variant_colors'] : array(),
    ) );
}
add_action( 'wp_ajax_decaldesk_save_variant_config', 'decaldesk_ajax_save_variant_config' );
/*! </fs_premium_only> */
