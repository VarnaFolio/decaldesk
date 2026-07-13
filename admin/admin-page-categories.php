<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ==========================================================
 * "Категории" - управление на категории + мокъп шаблони + позициониране
 * ==========================================================
 * Всичко тук е AJAX-driven (без голяма форма + submit) - всяко действие
 * (добавяне, преименуване, качване на шаблон, запис на зона, изтриване)
 * се случва веднага, без да чака цялата страница да се презареди.
 *
 * От версия с multi-template поддръжка: всяка категория може да има до
 * DECALDESK_MAX_TEMPLATES_PER_CATEGORY (4) шаблона - полезно напр. за
 * категория "коли", където искаш дизайнът да се покаже върху няколко
 * различни модела, вместо само един генеричен мокъп.
 */

/**
 * Рендер на страницата "Категории"
 */
function decaldesk_render_categories_page() {
    $settings   = get_option( 'decaldesk_settings', array() );
    $categories = isset( $settings['categories'] ) ? $settings['categories'] : array();
    ?>
    <div class="wrap decaldesk-wrap">
        <h1><?php esc_html_e( 'DecalDesk – Categories & Mockup Templates', 'decaldesk' ); ?></h1>
        <p class="description">
            <?php esc_html_e( 'Each category matches the "category" part of the filename (e.g. holiday_50x70_matte_KITCHEN.jpg → category "kitchen"). Here you upload mockup template(s) and set exactly where the design should sit on each one.', 'decaldesk' ); ?>
        </p>

        <div class="decaldesk-add-category-box">
            <h2><?php esc_html_e( 'Add new category', 'decaldesk' ); ?></h2>
            <div class="decaldesk-add-category-fields">
                <input type="text" id="decaldesk-new-category-name" placeholder="<?php esc_attr_e( 'Name (e.g. Kitchen Backsplash)', 'decaldesk' ); ?>" class="regular-text">
                <input type="text" id="decaldesk-new-category-slug" placeholder="<?php esc_attr_e( 'slug (e.g. kitchen)', 'decaldesk' ); ?>" class="regular-text">
                <button type="button" id="decaldesk-add-category-btn" class="button button-primary">
                    <?php esc_html_e( 'Add category', 'decaldesk' ); ?>
                </button>
            </div>
            <p class="description"><?php esc_html_e( 'The slug is suggested automatically from the name, but you can edit it before adding.', 'decaldesk' ); ?></p>
        </div>

        <div id="decaldesk-categories-list" class="decaldesk-categories-list">
            <?php foreach ( $categories as $slug => $name ) : ?>
                <?php decaldesk_render_category_card( $slug, $name ); ?>
            <?php endforeach; ?>
        </div>

        <?php if ( empty( $categories ) ) : ?>
            <p class="decaldesk-empty-state"><?php esc_html_e( 'No categories added yet.', 'decaldesk' ); ?></p>
        <?php endif; ?>
    </div>

    <!-- Шаблони за JS клониране: нова категория (с 1 празен слот) и нов слот в съществуваща категория -->
    <script type="text/template" id="decaldesk-category-card-template">
        <?php decaldesk_render_category_card( '__SLUG__', '__NAME__', true ); ?>
    </script>
    <script type="text/template" id="decaldesk-template-slot-template">
        <?php decaldesk_render_template_slot( '__SLUG__', '__SLOT__', '', array( 'x' => 15, 'y' => 15, 'width' => 70, 'height' => 70 ), false ); ?>
    </script>
    <?php
}

/**
 * Рендерира една "картичка" за категория (преизползва се и от JS темплейт).
 * Съдържа списък от template слотове (1 до 4) + бутон за добавяне на нов.
 *
 * @param string $slug
 * @param string $name
 * @param bool   $is_template Ако е true, генерираме placeholder markup за JS клониране (не за реален изход).
 */
function decaldesk_render_category_card( $slug, $name, $is_template = false ) {
    $slot_count = $is_template ? 1 : max( 1, decaldesk_count_uploaded_template_slots( $slug ) );
    ?>
    <div class="decaldesk-category-card" data-slug="<?php echo esc_attr( $slug ); ?>">
        <div class="decaldesk-category-header">
            <input type="text" class="decaldesk-category-name-input" value="<?php echo esc_attr( $name ); ?>">
            <code class="decaldesk-category-slug"><?php echo esc_html( $slug ); ?></code>
            <button type="button" class="button-link decaldesk-delete-category" title="<?php esc_attr_e( 'Delete category', 'decaldesk' ); ?>">
                <span class="dashicons dashicons-trash"></span>
            </button>
        </div>

        <div class="decaldesk-category-body">
            <div class="decaldesk-template-slots" data-slug="<?php echo esc_attr( $slug ); ?>">
                <?php for ( $slot = 1; $slot <= $slot_count; $slot++ ) : ?>
                    <?php
                    $preview_url = $is_template ? '' : decaldesk_get_uploaded_template_slot_url( $slug, $slot );
                    $zones       = $is_template ? array() : decaldesk_get_template_zones( $slug );
                    $zone        = isset( $zones[ $slot - 1 ] ) ? $zones[ $slot - 1 ] : array( 'x' => 15, 'y' => 15, 'width' => 70, 'height' => 70 );
                    $has_custom  = $is_template ? false : decaldesk_uploaded_slot_exists( $slug, $slot );
                    decaldesk_render_template_slot( $slug, $slot, $preview_url, $zone, $has_custom );
                    ?>
                <?php endfor; ?>
            </div>

            <button type="button" class="button decaldesk-add-template-slot"
                <?php disabled( $slot_count >= decaldesk_max_template_slots() ); ?>>
                <?php
                printf(
                    /* translators: %d: maximum number of templates per category */
                    esc_html__( '+ Add another template (up to %d)', 'decaldesk' ),
                    (int) decaldesk_max_template_slots()
                );
                ?>
            </button>
            <?php if ( ! decaldesk_fs()->can_use_premium_code() ) : ?>
                <p class="decaldesk-zone-hint">
                    <?php esc_html_e( 'Multiple templates per category and the freeform zone editor are Pro features.', 'decaldesk' ); ?>
                    <a href="<?php echo esc_url( decaldesk_fs()->get_upgrade_url() ); ?>"><?php esc_html_e( 'Upgrade to Pro', 'decaldesk' ); ?></a>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/**
 * Рендерира един template слот (шаблон + zone editor + контроли).
 *
 * @param string $slug
 * @param int    $slot        1-базиран номер на слота.
 * @param string $preview_url URL на текущото изображение (или '' ако няма).
 * @param array  $zone        {x,y,width,height} в проценти.
 * @param bool   $has_custom  Дали слотът има реално качен собствен файл (за разлика от fallback preview).
 */
function decaldesk_render_template_slot( $slug, $slot, $preview_url, $zone, $has_custom ) {
    $zone_type = isset( $zone['type'] ) && 'polygon' === $zone['type'] ? 'polygon' : 'rect';
    $points    = ( 'polygon' === $zone_type && ! empty( $zone['points'] ) ) ? $zone['points'] : array();

    // За SVG polygon points атрибута (viewBox 0 0 100 100, стойностите вече са в проценти)
    $svg_points = implode( ' ', array_map( function ( $p ) {
        return $p['x'] . ',' . $p['y'];
    }, $points ) );
    ?>
    <div class="decaldesk-template-slot" data-slug="<?php echo esc_attr( $slug ); ?>" data-slot="<?php echo esc_attr( $slot ); ?>" data-zone-type="<?php echo esc_attr( $zone_type ); ?>">
        <div class="decaldesk-template-slot-header">
            <span class="decaldesk-slot-label"><?php printf( esc_html__( 'Template %d', 'decaldesk' ), (int) $slot ); ?></span>
            <button type="button" class="button-link decaldesk-delete-slot" title="<?php esc_attr_e( 'Delete this template', 'decaldesk' ); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>

        <div class="decaldesk-template-preview-wrap">
            <?php if ( $preview_url ) : ?>
                <img class="decaldesk-template-preview-img" src="<?php echo esc_url( $preview_url ); ?>" alt="">
            <?php else : ?>
                <div class="decaldesk-template-preview-placeholder">
                    <?php esc_html_e( 'No template uploaded for this slot yet', 'decaldesk' ); ?>
                </div>
            <?php endif; ?>

            <!-- Правоъгълна зона (показва се само в режим "Правоъгълник") -->
            <div class="decaldesk-zone-box" style="display:<?php echo 'rect' === $zone_type ? 'block' : 'none'; ?>; left:<?php echo esc_attr( $zone['x'] ?? 15 ); ?>%; top:<?php echo esc_attr( $zone['y'] ?? 15 ); ?>%; width:<?php echo esc_attr( $zone['width'] ?? 70 ); ?>%; height:<?php echo esc_attr( $zone['height'] ?? 70 ); ?>%;">
                <img class="decaldesk-zone-test-preview" src="" alt="" style="display:none;">
                <span class="decaldesk-zone-handle decaldesk-zone-handle-nw"></span>
                <span class="decaldesk-zone-handle decaldesk-zone-handle-ne"></span>
                <span class="decaldesk-zone-handle decaldesk-zone-handle-sw"></span>
                <span class="decaldesk-zone-handle decaldesk-zone-handle-se"></span>
            </div>

            <!-- Полигонална зона (показва се само в режим "Свободна форма") -->
            <div class="decaldesk-zone-polygon-wrap" style="display:<?php echo 'polygon' === $zone_type ? 'block' : 'none'; ?>;">
                <svg class="decaldesk-zone-polygon-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                    <polygon class="decaldesk-zone-polygon-shape" points="<?php echo esc_attr( $svg_points ); ?>"></polygon>
                </svg>
                <img class="decaldesk-zone-polygon-test-preview" src="" alt="" style="display:none;">
                <div class="decaldesk-zone-polygon-points">
                    <?php foreach ( $points as $index => $point ) : ?>
                        <span class="decaldesk-zone-polygon-point" data-index="<?php echo esc_attr( $index ); ?>"
                              style="left:<?php echo esc_attr( $point['x'] ); ?>%; top:<?php echo esc_attr( $point['y'] ); ?>%;"></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="decaldesk-slot-controls">
            <p class="decaldesk-template-status">
                <?php if ( $has_custom ) : ?>
                    <span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span> <?php esc_html_e( 'Custom template uploaded', 'decaldesk' ); ?>
                <?php else : ?>
                    <span class="dashicons dashicons-info" style="color:#dba617;"></span> <?php esc_html_e( 'Using the default template', 'decaldesk' ); ?>
                <?php endif; ?>
            </p>

            <div class="decaldesk-zone-mode-toggle">
                <label>
                    <input type="radio" class="decaldesk-zone-mode-radio" name="decaldesk_zone_mode_<?php echo esc_attr( $slug . '_' . $slot ); ?>" value="rect" <?php checked( 'rect', $zone_type ); ?>>
                    <?php esc_html_e( 'Rectangle', 'decaldesk' ); ?>
                </label>
                <label>
                    <input type="radio" class="decaldesk-zone-mode-radio" name="decaldesk_zone_mode_<?php echo esc_attr( $slug . '_' . $slot ); ?>" value="polygon" <?php checked( 'polygon', $zone_type ); ?> <?php disabled( ! decaldesk_fs()->can_use_premium_code() ); ?>>
                    <?php esc_html_e( 'Freeform', 'decaldesk' ); ?>
                    <?php if ( ! decaldesk_fs()->can_use_premium_code() ) : ?>
                        <span class="decaldesk-pro-badge"><?php esc_html_e( 'Pro', 'decaldesk' ); ?></span>
                    <?php endif; ?>
                </label>
            </div>

            <label class="button">
                <?php esc_html_e( 'Upload template', 'decaldesk' ); ?>
                <input type="file" class="decaldesk-template-upload-input" accept="image/png,image/jpeg,image/webp" hidden>
            </label>

            <label class="button">
                <?php esc_html_e( 'Test with a design', 'decaldesk' ); ?>
                <input type="file" class="decaldesk-zone-test-input" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
            </label>

            <button type="button" class="button decaldesk-save-zone-btn"><?php esc_html_e( 'Save position', 'decaldesk' ); ?></button>
            <button type="button" class="button decaldesk-reset-zone-btn decaldesk-rect-only-control" style="display:<?php echo 'rect' === $zone_type ? 'inline-flex' : 'none'; ?>;"><?php esc_html_e( 'Reset to centered', 'decaldesk' ); ?></button>
            <button type="button" class="button decaldesk-clear-polygon-btn decaldesk-polygon-only-control" style="display:<?php echo 'polygon' === $zone_type ? 'inline-flex' : 'none'; ?>;"><?php esc_html_e( 'Clear points', 'decaldesk' ); ?></button>

            <p class="decaldesk-zone-hint decaldesk-rect-only-control" style="display:<?php echo 'rect' === $zone_type ? 'block' : 'none'; ?>;">
                <?php esc_html_e( 'Drag and resize the frame to set exactly where the design should appear on this template.', 'decaldesk' ); ?>
            </p>
            <p class="decaldesk-zone-hint decaldesk-polygon-only-control" style="display:<?php echo 'polygon' === $zone_type ? 'block' : 'none'; ?>;">
                <?php esc_html_e( 'Click on the template to add a point. Drag existing points to move them. You need at least 3 points.', 'decaldesk' ); ?>
            </p>

            <div class="decaldesk-category-save-status"></div>
        </div>
    </div>
    <?php
}

/**
 * URL за preview на ПЪРВИЯ шаблон на категорията (custom качен, или fallback).
 * Използва се за общи цели (напр. при добавяне на нова категория).
 */
function decaldesk_get_template_preview_url( $category ) {
    $upload_dir = wp_upload_dir();
    $path       = decaldesk_resolve_template_path( $category );

    if ( strpos( $path, $upload_dir['basedir'] ) === 0 ) {
        return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $path ) . '?v=' . filemtime( $path );
    }

    return str_replace( DECALDESK_PATH, DECALDESK_URL, $path );
}

/**
 * URL за preview на КОНКРЕТЕН слот. Ако слотът няма реално качен файл,
 * пада на общия preview (bundled/default fallback), за да не показваме
 * счупена снимка.
 */
function decaldesk_get_uploaded_template_slot_url( $category, $slot ) {
    $upload_dir  = wp_upload_dir();
    $uploads_tpl = $upload_dir['basedir'] . '/decaldesk/templates';

    foreach ( array( 'png', 'jpg', 'jpeg', 'webp' ) as $ext ) {
        $path = $uploads_tpl . '/' . $category . '-' . $slot . '.' . $ext;
        if ( file_exists( $path ) ) {
            return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $path ) . '?v=' . filemtime( $path );
        }
    }

    if ( 1 === (int) $slot ) {
        foreach ( array( 'png', 'jpg', 'jpeg', 'webp' ) as $ext ) {
            $path = $uploads_tpl . '/' . $category . '.' . $ext;
            if ( file_exists( $path ) ) {
                return str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $path ) . '?v=' . filemtime( $path );
            }
        }
    }

    return decaldesk_get_template_preview_url( $category );
}

/**
 * Дали КОНКРЕТЕН слот има реално качен собствен файл.
 */
function decaldesk_uploaded_slot_exists( $category, $slot ) {
    $upload_dir  = wp_upload_dir();
    $uploads_tpl = $upload_dir['basedir'] . '/decaldesk/templates';

    foreach ( array( 'png', 'jpg', 'jpeg', 'webp' ) as $ext ) {
        if ( file_exists( $uploads_tpl . '/' . $category . '-' . $slot . '.' . $ext ) ) {
            return true;
        }
    }

    if ( 1 === (int) $slot ) {
        foreach ( array( 'png', 'jpg', 'jpeg', 'webp' ) as $ext ) {
            if ( file_exists( $uploads_tpl . '/' . $category . '.' . $ext ) ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Брои реално качените (custom) template слотове за категория - НЕ включва
 * bundled/default fallback-и, само истински качени през UI файлове.
 * Legacy единичен файл (без суфикс) се брои като 1 слот.
 *
 * @return int 0 ако няма нито един качен собствен шаблон.
 */
function decaldesk_count_uploaded_template_slots( $category ) {
    $upload_dir  = wp_upload_dir();
    $uploads_tpl = $upload_dir['basedir'] . '/decaldesk/templates';
    $extensions  = array( 'png', 'jpg', 'jpeg', 'webp' );

    $count = 0;
    for ( $slot = 1; $slot <= DECALDESK_MAX_TEMPLATES_PER_CATEGORY; $slot++ ) {
        foreach ( $extensions as $ext ) {
            if ( file_exists( $uploads_tpl . '/' . $category . '-' . $slot . '.' . $ext ) ) {
                $count = $slot;
                break;
            }
        }
    }

    if ( 0 === $count ) {
        foreach ( $extensions as $ext ) {
            if ( file_exists( $uploads_tpl . '/' . $category . '.' . $ext ) ) {
                return 1;
            }
        }
    }

    return $count;
}

/**
 * Дали категорията има собствен качен шаблон (поне един слот) - за разлика
 * от fallback. Пази се за backward compat с евентуален друг код, който я вика.
 */
function decaldesk_has_custom_template( $category ) {
    return decaldesk_count_uploaded_template_slots( $category ) > 0;
}

/**
 * ==========================================================
 * AJAX handlers
 * ==========================================================
 */

function decaldesk_check_categories_permission() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do this.', 'decaldesk' ) ), 403 );
    }
    check_ajax_referer( 'decaldesk_categories_nonce', 'nonce' );
}

/**
 * AJAX: добавя нова категория (slug + име).
 */
function decaldesk_ajax_add_category() {
    decaldesk_check_categories_permission();

    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';

    if ( empty( $name ) || empty( $slug ) ) {
        wp_send_json_error( array( 'message' => __( 'Name and slug are required.', 'decaldesk' ) ), 400 );
    }

    $settings = get_option( 'decaldesk_settings', array() );
    $categories = isset( $settings['categories'] ) ? $settings['categories'] : array();

    if ( isset( $categories[ $slug ] ) ) {
        wp_send_json_error( array( 'message' => __( 'A category with this slug already exists.', 'decaldesk' ) ), 409 );
    }

    $categories[ $slug ] = $name;
    $settings['categories'] = $categories;
    update_option( 'decaldesk_settings', $settings );

    wp_send_json_success( array(
        'slug'        => $slug,
        'name'        => $name,
        'preview_url' => decaldesk_get_template_preview_url( $slug ),
    ) );
}
add_action( 'wp_ajax_decaldesk_add_category', 'decaldesk_ajax_add_category' );

/**
 * AJAX: преименува категория (само display name, slug-ът е неизменяем след създаване).
 */
function decaldesk_ajax_rename_category() {
    decaldesk_check_categories_permission();

    $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

    if ( empty( $slug ) || empty( $name ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid data.', 'decaldesk' ) ), 400 );
    }

    $settings = get_option( 'decaldesk_settings', array() );
    if ( ! isset( $settings['categories'][ $slug ] ) ) {
        wp_send_json_error( array( 'message' => __( 'The category doesn\'t exist.', 'decaldesk' ) ), 404 );
    }

    $settings['categories'][ $slug ] = $name;
    update_option( 'decaldesk_settings', $settings );

    wp_send_json_success();
}
add_action( 'wp_ajax_decaldesk_rename_category', 'decaldesk_ajax_rename_category' );

/**
 * AJAX: изтрива категория от нашия mapping (НЕ пипа реалната WooCommerce
 * категория/термин, ако вече има продукти с нея - само нашия slug->име списък).
 */
function decaldesk_ajax_delete_category() {
    decaldesk_check_categories_permission();

    $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';

    $settings = get_option( 'decaldesk_settings', array() );
    unset( $settings['categories'][ $slug ] );
    unset( $settings['template_zones'][ $slug ] );
    update_option( 'decaldesk_settings', $settings );

    $upload_dir  = wp_upload_dir();
    $uploads_tpl = $upload_dir['basedir'] . '/decaldesk/templates';
    $extensions  = array( 'png', 'jpg', 'jpeg', 'webp' );

    foreach ( $extensions as $ext ) {
        $legacy = $uploads_tpl . '/' . $slug . '.' . $ext;
        if ( file_exists( $legacy ) ) {
            @unlink( $legacy );
        }
    }
    for ( $slot = 1; $slot <= DECALDESK_MAX_TEMPLATES_PER_CATEGORY; $slot++ ) {
        foreach ( $extensions as $ext ) {
            $path = $uploads_tpl . '/' . $slug . '-' . $slot . '.' . $ext;
            if ( file_exists( $path ) ) {
                @unlink( $path );
            }
        }
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_decaldesk_delete_category', 'decaldesk_ajax_delete_category' );

/**
 * AJAX: качва нов мокъп шаблон за конкретен слот на категория.
 */
function decaldesk_ajax_upload_template() {
    decaldesk_check_categories_permission();

    $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
    $slot = isset( $_POST['slot'] ) ? max( 1, min( DECALDESK_MAX_TEMPLATES_PER_CATEGORY, (int) $_POST['slot'] ) ) : 1;

    if ( empty( $slug ) || empty( $_FILES['template'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Missing file or category.', 'decaldesk' ) ), 400 );
    }

    // Сървърна защита: без Pro лиценз, слот 2+ не е позволен, дори ако
    // заявката дойде директно (bypass на UI-то). Отхвърляме изрично, вместо
    // тихо да "clamp"-нем към слот 1 - иначе бихме презаписали слот 1 с
    // файл, предназначен за друг слот.
    if ( $slot > decaldesk_max_template_slots() ) {
        wp_send_json_error( array( 'message' => __( 'Multiple templates per category require a Pro license.', 'decaldesk' ) ), 403 );
    }

    $file = $_FILES['template'];

    if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
        wp_send_json_error( array( 'message' => decaldesk_upload_error_message( $file['error'] ?? -1 ) ), 400 );
    }

    $filetype = wp_check_filetype( $file['name'] );
    $ext      = strtolower( $filetype['ext'] );

    if ( ! in_array( $ext, array( 'png', 'jpg', 'jpeg', 'webp' ), true ) ) {
        wp_send_json_error( array( 'message' => __( 'Allowed template formats: PNG, JPG, WEBP.', 'decaldesk' ) ), 400 );
    }

    $content_check = decaldesk_validate_image_content( $file['tmp_name'] );
    if ( is_wp_error( $content_check ) ) {
        wp_send_json_error( array( 'message' => $content_check->get_error_message() ), 400 );
    }

    $upload_dir  = wp_upload_dir();
    $uploads_tpl = $upload_dir['basedir'] . '/decaldesk/templates';

    if ( ! file_exists( $uploads_tpl ) ) {
        wp_mkdir_p( $uploads_tpl );
    }

    foreach ( array( 'png', 'jpg', 'jpeg', 'webp' ) as $old_ext ) {
        $old_path = $uploads_tpl . '/' . $slug . '-' . $slot . '.' . $old_ext;
        if ( file_exists( $old_path ) ) {
            @unlink( $old_path );
        }
        if ( 1 === $slot ) {
            $legacy_path = $uploads_tpl . '/' . $slug . '.' . $old_ext;
            if ( file_exists( $legacy_path ) ) {
                @unlink( $legacy_path );
            }
        }
    }

    $target_path = $uploads_tpl . '/' . $slug . '-' . $slot . '.' . $ext;

    if ( ! move_uploaded_file( $file['tmp_name'], $target_path ) ) {
        wp_send_json_error( array( 'message' => __( 'Failed to save the template.', 'decaldesk' ) ), 500 );
    }

    wp_send_json_success( array(
        'preview_url' => decaldesk_get_uploaded_template_slot_url( $slug, $slot ),
    ) );
}
add_action( 'wp_ajax_decaldesk_upload_template', 'decaldesk_ajax_upload_template' );

/**
 * AJAX: изтрива конкретен template слот - трие файла и "премества" всички
 * следващи слотове с 1 назад (renumbering), за да няма дупки в номерацията.
 */
function decaldesk_ajax_delete_template_slot() {
    decaldesk_check_categories_permission();

    $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
    $slot = isset( $_POST['slot'] ) ? max( 1, (int) $_POST['slot'] ) : 1;

    if ( empty( $slug ) ) {
        wp_send_json_error( array( 'message' => __( 'Missing category.', 'decaldesk' ) ), 400 );
    }

    $upload_dir  = wp_upload_dir();
    $uploads_tpl = $upload_dir['basedir'] . '/decaldesk/templates';
    $extensions  = array( 'png', 'jpg', 'jpeg', 'webp' );

    $slot_count = decaldesk_count_uploaded_template_slots( $slug );

    if ( $slot_count <= 1 ) {
        foreach ( $extensions as $ext ) {
            foreach ( array( $slug . '-' . $slot . '.' . $ext, $slug . '.' . $ext ) as $filename ) {
                $path = $uploads_tpl . '/' . $filename;
                if ( file_exists( $path ) ) {
                    @unlink( $path );
                }
            }
        }
    } else {
        foreach ( $extensions as $ext ) {
            $path = $uploads_tpl . '/' . $slug . '-' . $slot . '.' . $ext;
            if ( file_exists( $path ) ) {
                @unlink( $path );
            }
        }

        for ( $i = $slot + 1; $i <= $slot_count; $i++ ) {
            foreach ( $extensions as $ext ) {
                $old_path = $uploads_tpl . '/' . $slug . '-' . $i . '.' . $ext;
                if ( file_exists( $old_path ) ) {
                    $new_path = $uploads_tpl . '/' . $slug . '-' . ( $i - 1 ) . '.' . $ext;
                    @rename( $old_path, $new_path );
                }
            }
        }
    }

    $settings = get_option( 'decaldesk_settings', array() );
    if ( isset( $settings['template_zones'][ $slug ] ) && is_array( $settings['template_zones'][ $slug ] ) ) {
        $zones = $settings['template_zones'][ $slug ];

        if ( isset( $zones['x'] ) ) {
            unset( $settings['template_zones'][ $slug ] );
        } else {
            $index = $slot - 1;
            if ( isset( $zones[ $index ] ) ) {
                array_splice( $zones, $index, 1 );
            }
            $settings['template_zones'][ $slug ] = array_values( $zones );
        }

        update_option( 'decaldesk_settings', $settings );
    }

    wp_send_json_success();
}
add_action( 'wp_ajax_decaldesk_delete_template_slot', 'decaldesk_ajax_delete_template_slot' );

/**
 * AJAX: записва зоната на позициониране (x/y/width/height в проценти) за
 * конкретен слот на категория.
 */
function decaldesk_ajax_save_zone() {
    decaldesk_check_categories_permission();

    $slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
    $slot = isset( $_POST['slot'] ) ? max( 1, (int) $_POST['slot'] ) : 1;
    $type = isset( $_POST['type'] ) && 'polygon' === $_POST['type'] ? 'polygon' : 'rect';

    if ( empty( $slug ) ) {
        wp_send_json_error( array( 'message' => __( 'Missing category.', 'decaldesk' ) ), 400 );
    }

    if ( 'polygon' === $type && ! decaldesk_fs()->can_use_premium_code() ) {
        wp_send_json_error( array( 'message' => __( 'The freeform zone editor requires a Pro license.', 'decaldesk' ) ), 403 );
    }

    if ( $slot > decaldesk_max_template_slots() ) {
        wp_send_json_error( array( 'message' => __( 'Multiple templates per category require a Pro license.', 'decaldesk' ) ), 403 );
    }

    $settings = get_option( 'decaldesk_settings', array() );
    if ( ! isset( $settings['template_zones'] ) ) {
        $settings['template_zones'] = array();
    }

    $zones = decaldesk_get_template_zones( $slug );

    if ( 'polygon' === $type ) {
        $raw_points = isset( $_POST['points'] ) ? json_decode( wp_unslash( $_POST['points'] ), true ) : array();

        if ( ! is_array( $raw_points ) || count( $raw_points ) < 3 ) {
            wp_send_json_error( array( 'message' => __( 'At least 3 points are required for a freeform shape.', 'decaldesk' ) ), 400 );
        }

        $clean_points = array();
        foreach ( $raw_points as $point ) {
            if ( ! isset( $point['x'], $point['y'] ) ) {
                continue;
            }
            $clean_points[] = array(
                'x' => round( max( 0, min( 100, (float) $point['x'] ) ), 2 ),
                'y' => round( max( 0, min( 100, (float) $point['y'] ) ), 2 ),
            );
        }

        if ( count( $clean_points ) < 3 ) {
            wp_send_json_error( array( 'message' => __( 'At least 3 valid points are required.', 'decaldesk' ) ), 400 );
        }

        $zones[ $slot - 1 ] = array(
            'type'   => 'polygon',
            'points' => $clean_points,
        );
    } else {
        $x      = isset( $_POST['x'] ) ? (float) $_POST['x'] : 15;
        $y      = isset( $_POST['y'] ) ? (float) $_POST['y'] : 15;
        $width  = isset( $_POST['width'] ) ? (float) $_POST['width'] : 70;
        $height = isset( $_POST['height'] ) ? (float) $_POST['height'] : 70;

        $x      = max( 0, min( 100, $x ) );
        $y      = max( 0, min( 100, $y ) );
        $width  = max( 1, min( 100 - $x, $width ) );
        $height = max( 1, min( 100 - $y, $height ) );

        $zones[ $slot - 1 ] = array(
            'type'   => 'rect',
            'x'      => round( $x, 2 ),
            'y'      => round( $y, 2 ),
            'width'  => round( $width, 2 ),
            'height' => round( $height, 2 ),
        );
    }

    ksort( $zones );
    $settings['template_zones'][ $slug ] = array_values( $zones );

    update_option( 'decaldesk_settings', $settings );

    wp_send_json_success();
}
add_action( 'wp_ajax_decaldesk_save_zone', 'decaldesk_ajax_save_zone' );
