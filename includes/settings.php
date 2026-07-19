<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Регистрира настройките чрез WordPress Settings API
 */
function decaldesk_register_settings() {
    register_setting( 'decaldesk_settings_group', 'decaldesk_settings', 'decaldesk_sanitize_settings' );
}
add_action( 'admin_init', 'decaldesk_register_settings' );

/*! <fs_premium_only> */
/**
 * Санитизира текст с размери "WxH", по един на ред, в чист масив от низове.
 * Невалидни редове (не съвпадащи с шаблона) се пропускат тихо.
 *
 * @param string $raw Суров текст от textarea, напр. "30x40\n50x70\n70x100"
 * @return string[] Списък с валидни "WxH" низове, без дубликати.
 */
function decaldesk_sanitize_size_list( $raw ) {
    // Ако вече е масив (idempotent round-trip - друго AJAX действие е
    // прочело целите настройки и ги записва обратно непроменени), само
    // валидираме елементите, вместо да се опитваме да го третираме като
    // суров многоредов текст (би дало "Array to string conversion" грешка).
    if ( is_array( $raw ) ) {
        $sizes = array();
        foreach ( $raw as $item ) {
            if ( is_string( $item ) && preg_match( '/^\d+x\d+$/', $item ) ) {
                $sizes[] = $item;
            }
        }
        return array_values( array_unique( $sizes ) );
    }

    $lines = preg_split( '/[\r\n]+/', (string) $raw );
    $sizes = array();

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( preg_match( '/^(\d+)\s*[xX×]\s*(\d+)$/u', $line, $m ) ) {
            $width  = (int) $m[1];
            $height = (int) $m[2];
            if ( $width > 0 && $height > 0 ) {
                $sizes[] = $width . 'x' . $height;
            }
        }
    }

    return array_values( array_unique( $sizes ) );
}

/**
 * Санитизира comma-separated списък (материали/цветове) в чист масив от низове.
 *
 * @param string $raw Суров текст, напр. "матово, гланц, прозрачно"
 * @return string[] Списък с чисти стойности, без празни/дубликати.
 */
function decaldesk_sanitize_csv_list( $raw ) {
    // Идемпотентен round-trip (вижте коментара в decaldesk_sanitize_size_list)
    if ( is_array( $raw ) ) {
        $clean = array();
        foreach ( $raw as $item ) {
            $item = trim( sanitize_text_field( (string) $item ) );
            if ( '' !== $item ) {
                $clean[] = $item;
            }
        }
        return array_values( array_unique( $clean ) );
    }

    $parts = explode( ',', (string) $raw );
    $clean = array();

    foreach ( $parts as $part ) {
        $part = trim( sanitize_text_field( $part ) );
        if ( '' !== $part ) {
            $clean[] = $part;
        }
    }

    return array_values( array_unique( $clean ) );
}
/*! </fs_premium_only> */

/**
 * AJAX: тества връзката с избрания AI доставчик с фиктивни данни и връща
 * или суровия генериран текст, или точното съобщение за грешка от API-то.
 * Използва се от бутона "Тествай връзката" в настройките - за диагностика
 * без да се налага да се качва реален файл или да се рови в лог файлове.
 */
function decaldesk_ajax_test_ai_connection() {
    check_ajax_referer( 'decaldesk_test_ai_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'You don\'t have permission to do this.', 'decaldesk' ) ), 403 );
    }

    /*! <fs_premium_only> */
    if ( ! decaldesk_fs()->can_use_premium_code() ) {
        wp_send_json_error( array( 'message' => __( 'AI descriptions require a Pro license.', 'decaldesk' ) ), 403 );
    }

    $settings = wp_parse_args( get_option( 'decaldesk_settings', array() ), array(
        'ai_provider' => 'none',
        'ai_model'    => 'claude-sonnet-4-6',
    ) );

    $dummy_parsed = array(
        'name'     => 'Тестов дизайн',
        'width'    => 50,
        'height'   => 70,
        'material' => 'matte',
        'category' => 'sticker',
    );

    if ( 'free_gemini' === $settings['ai_provider'] ) {
        $key = decaldesk_get_gemini_api_key();
        if ( empty( $key ) ) {
            wp_send_json_error( array( 'message' => __( 'No Google Gemini API key set.', 'decaldesk' ) ) );
        }
        $result = decaldesk_call_gemini_api_raw( $dummy_parsed, $key );
    } elseif ( 'claude' === $settings['ai_provider'] ) {
        $key = decaldesk_get_ai_api_key();
        if ( empty( $key ) ) {
            wp_send_json_error( array( 'message' => __( 'No Anthropic API key set.', 'decaldesk' ) ) );
        }
        $result = decaldesk_call_claude_api_raw( $dummy_parsed, $key, $settings['ai_model'] );
    } else {
        wp_send_json_error( array( 'message' => __( 'The AI provider is disabled (set to "Disabled").', 'decaldesk' ) ) );
    }

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success( array( 'raw' => $result ) );
    /*! </fs_premium_only> */
    // AI описанията (Google Gemini / Anthropic Claude) са налични в DecalDesk Pro.
    wp_send_json_error( array( 'message' => __( 'AI-generated descriptions are available in DecalDesk Pro.', 'decaldesk' ) ), 403 );
}
add_action( 'wp_ajax_decaldesk_test_ai_connection', 'decaldesk_ajax_test_ai_connection' );

/**
 * Санитизира масива от категории (slug => display name).
 *
 * @param mixed $raw
 * @return array<string,string>
 */
function decaldesk_sanitize_categories( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }

    $clean = array();
    foreach ( $raw as $slug => $name ) {
        $slug = sanitize_key( (string) $slug );
        if ( '' === $slug ) {
            continue;
        }
        $clean[ $slug ] = sanitize_text_field( (string) $name );
    }

    return $clean;
}

/**
 * Санитизира една зона (rect или polygon) - връща null ако не е валидна структура.
 *
 * @param mixed $zone
 * @return array|null
 */
function decaldesk_sanitize_single_zone( $zone ) {
    if ( ! is_array( $zone ) ) {
        return null;
    }

    if ( isset( $zone['type'] ) && 'polygon' === $zone['type'] ) {
        if ( empty( $zone['points'] ) || ! is_array( $zone['points'] ) ) {
            return null;
        }

        $points = array();
        foreach ( $zone['points'] as $point ) {
            if ( ! is_array( $point ) || ! isset( $point['x'], $point['y'] ) ) {
                continue;
            }
            $points[] = array(
                'x' => round( max( 0, min( 100, (float) $point['x'] ) ), 2 ),
                'y' => round( max( 0, min( 100, (float) $point['y'] ) ), 2 ),
            );
        }

        if ( count( $points ) < 3 ) {
            return null;
        }

        return array(
            'type'   => 'polygon',
            'points' => $points,
        );
    }

    $x      = isset( $zone['x'] ) ? max( 0, min( 100, (float) $zone['x'] ) ) : 15;
    $y      = isset( $zone['y'] ) ? max( 0, min( 100, (float) $zone['y'] ) ) : 15;
    $width  = isset( $zone['width'] ) ? max( 1, min( 100 - $x, (float) $zone['width'] ) ) : 70;
    $height = isset( $zone['height'] ) ? max( 1, min( 100 - $y, (float) $zone['height'] ) ) : 70;

    return array(
        'type'   => 'rect',
        'x'      => round( $x, 2 ),
        'y'      => round( $y, 2 ),
        'width'  => round( $width, 2 ),
        'height' => round( $height, 2 ),
    );
}

/**
 * Санитизира целия template_zones масив (category slug => зона или списък от зони).
 *
 * @param mixed $raw
 * @return array
 */
function decaldesk_sanitize_template_zones( $raw ) {
    if ( ! is_array( $raw ) ) {
        return array();
    }

    $clean = array();
    foreach ( $raw as $slug => $zones_config ) {
        $slug = sanitize_key( (string) $slug );
        if ( '' === $slug || ! is_array( $zones_config ) ) {
            continue;
        }

        // Стар формат: единична зона directly (ключ 'x' или 'type' на това ниво)
        if ( isset( $zones_config['x'] ) || isset( $zones_config['type'] ) ) {
            $zone = decaldesk_sanitize_single_zone( $zones_config );
            if ( null !== $zone ) {
                $clean[ $slug ] = $zone;
            }
            continue;
        }

        // Нов формат: индексиран списък от зони, по една на слот
        $zone_list = array();
        foreach ( $zones_config as $zone ) {
            $sanitized = decaldesk_sanitize_single_zone( $zone );
            if ( null !== $sanitized ) {
                $zone_list[] = $sanitized;
            }
        }

        if ( ! empty( $zone_list ) ) {
            $clean[ $slug ] = $zone_list;
        }
    }

    return $clean;
}

/**
 * Санитизира въведените настройки преди запис.
 */
function decaldesk_sanitize_settings( $input ) {
    $output = array();

    // ВАЖНО: дефинираме $existing НАЙ-ОТГОРЕ, преди каквато и да е употреба -
    // намерих реален бъг тук (undefined variable по-долу във функцията заради
    // предишно дублирано обявяване по-надолу), който тихо чупеше fallback-а
    // за categories/template_zones при round-trip през несвързани AJAX
    // действия. Сега има само ЕДНО обявяване, най-рано възможното.
    $existing = get_option( 'decaldesk_settings', array() );

    $output['price_per_sqm'] = isset( $input['price_per_sqm'] ) ? (float) $input['price_per_sqm'] : 60;
    $output['min_price']     = isset( $input['min_price'] ) ? (float) $input['min_price'] : 15;
    $output['max_dimension_cm'] = isset( $input['max_dimension_cm'] ) ? max( 1, (int) $input['max_dimension_cm'] ) : 1000;
    $output['custom_footer_text'] = isset( $input['custom_footer_text'] ) ? wp_kses_post( wp_unslash( $input['custom_footer_text'] ) ) : '';

    /*! <fs_premium_only> */
    $allowed_formats = array( 'webp', 'jpeg', 'png' );
    $output['mockup_format']  = isset( $input['mockup_format'] ) && in_array( $input['mockup_format'], $allowed_formats, true )
        ? $input['mockup_format']
        : 'webp';
    $output['mockup_quality'] = isset( $input['mockup_quality'] ) ? max( 1, min( 100, (int) $input['mockup_quality'] ) ) : 82;
    /*! </fs_premium_only> */

    /*! <fs_premium_only> */
    // Размерни варианти (за Variable Products) - размери задължителни за да
    // работи функцията, материал/цвят са изрично незадължителни списъци.
    // ВАЖНО: пазим НОВИТЕ данни ако $input наистина ги носи (независимо дали
    // като суров текст от формата, или вече като масив от друго AJAX действие,
    // което просто препраща цялото settings-масив без промяна на това поле),
    // и падаме на старите САМО ако $input изобщо няма тези ключове - същия
    // модел, който приложихме за categories/template_zones по-рано.
    $output['variant_sizes'] = isset( $input['variant_sizes'] )
        ? decaldesk_sanitize_size_list( $input['variant_sizes'] )
        : ( isset( $existing['variant_sizes'] ) ? $existing['variant_sizes'] : array() );
    $output['variant_materials'] = isset( $input['variant_materials'] )
        ? decaldesk_sanitize_csv_list( $input['variant_materials'] )
        : ( isset( $existing['variant_materials'] ) ? $existing['variant_materials'] : array() );
    $output['variant_colors'] = isset( $input['variant_colors'] )
        ? decaldesk_sanitize_csv_list( $input['variant_colors'] )
        : ( isset( $existing['variant_colors'] ) ? $existing['variant_colors'] : array() );

    $allowed_providers   = array( 'none', 'free_gemini', 'claude' );
    $output['ai_provider'] = isset( $input['ai_provider'] ) && in_array( $input['ai_provider'], $allowed_providers, true )
        ? $input['ai_provider']
        : 'none';

    $output['ai_model']          = isset( $input['ai_model'] ) ? sanitize_text_field( $input['ai_model'] ) : 'claude-sonnet-4-6';
    $output['gemini_daily_limit'] = isset( $input['gemini_daily_limit'] ) ? max( 1, (int) $input['gemini_daily_limit'] ) : 10;
    $output['ai_use_vision']      = ! empty( $input['ai_use_vision'] );
    /*! </fs_premium_only> */

    // Език на AI-генерираното продуктово съдържание - НЕ на admin панела.
    // Ползва се и от статичния fallback шаблон (виж decaldesk_build_fallback_content()),
    // затова остава конфигурируем и в тази версия, независимо от AI provider-а.
    // ВАЖНО: ако $input носи ВЕЧЕ САНИТИЗИРАНА custom стойност (напр. "Czech"
    // от предишен запис, върната обратно през round-trip от СЪВСЕМ ДРУГО
    // AJAX действие - виж бележката за register_setting() по-горе), не я
    // отхвърляме само защото не е в preset списъка - същия клас бъг, който
    // фиксирахме за categories/template_zones по-рано.
    if ( isset( $input['ai_content_language'] ) && 'custom' === $input['ai_content_language'] ) {
        $custom = isset( $input['ai_content_language_custom'] ) ? sanitize_text_field( $input['ai_content_language_custom'] ) : '';
        $output['ai_content_language'] = '' !== $custom ? $custom : 'Bulgarian';
    } elseif ( isset( $input['ai_content_language'] ) && '' !== trim( (string) $input['ai_content_language'] ) ) {
        $output['ai_content_language'] = sanitize_text_field( $input['ai_content_language'] );
    } else {
        $output['ai_content_language'] = isset( $existing['ai_content_language'] ) ? $existing['ai_content_language'] : 'Bulgarian';
    }

    // По подразбиране ИЗКЛЮЧЕНО (безопасно) - изисква се съзнателно съгласие,
    // за да не се изтрият случайно данни при бъдещо преинсталиране на плъгина.
    $output['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );

    /*! <fs_premium_only> */
    // API ключовете се запазват само ако е въведен нов; иначе пазим стария (за да не се налага
    // да се въвежда наново при всеки Save, ако вече е зададен през wp-config.php константа).
    if ( ! empty( $input['ai_api_key'] ) ) {
        $output['ai_api_key'] = sanitize_text_field( $input['ai_api_key'] );
    } else {
        $output['ai_api_key'] = isset( $existing['ai_api_key'] ) ? $existing['ai_api_key'] : '';
    }

    if ( ! empty( $input['gemini_api_key'] ) ) {
        $output['gemini_api_key'] = sanitize_text_field( $input['gemini_api_key'] );
    } else {
        $output['gemini_api_key'] = isset( $existing['gemini_api_key'] ) ? $existing['gemini_api_key'] : '';
    }
    /*! </fs_premium_only> */

    // Категориите и зоните за позициониране се управляват от отделна
    // страница (DecalDesk → Категории) чрез AJAX. ВАЖНО: тъй като
    // register_setting() кара WordPress да пуска ВСЯКО извикване на
    // update_option('decaldesk_settings', ...) - включително от AJAX
    // handler-ите в Категории, не само от тази форма - през тази функция,
    // трябва да пазим НОВИТЕ данни, ако $input наистина ги носи (AJAX
    // случай, където $input е цялото обновено settings-масив), и да
    // падаме на старите САМО ако $input изобщо няма тези ключове
    // (истинско save на Settings формата, която няма тези полета).
    $output['categories']     = isset( $input['categories'] )
        ? decaldesk_sanitize_categories( $input['categories'] )
        : ( isset( $existing['categories'] ) ? $existing['categories'] : array() );
    $output['template_zones'] = isset( $input['template_zones'] )
        ? decaldesk_sanitize_template_zones( $input['template_zones'] )
        : ( isset( $existing['template_zones'] ) ? $existing['template_zones'] : array() );

    return $output;
}

/**
 * Рендер на страницата с настройки
 */
function decaldesk_render_settings_page() {
    $settings = wp_parse_args( get_option( 'decaldesk_settings', array() ), array(
        'price_per_sqm'      => 60,
        'min_price'          => 15,
        'max_dimension_cm'   => 1000,
        'custom_footer_text' => '',
        'categories'         => array(),
        'ai_content_language' => 'Bulgarian',
        'delete_data_on_uninstall' => false,
        /*! <fs_premium_only> */
        'mockup_format'      => 'webp',
        'mockup_quality'     => 82,
        'variant_sizes'      => array(),
        'variant_materials'  => array(),
        'variant_colors'     => array(),
        'ai_provider'        => 'none',
        'ai_api_key'         => '',
        'ai_model'           => 'claude-sonnet-4-6',
        'gemini_api_key'     => '',
        'gemini_daily_limit' => 10,
        'ai_use_vision'      => false,
        /*! </fs_premium_only> */
    ) );

    /*! <fs_premium_only> */
    $preset_languages = array( 'Bulgarian', 'English', 'German', 'French', 'Spanish', 'Italian', 'Romanian', 'Polish', 'Dutch', 'Portuguese', 'Greek', 'Turkish' );
    $is_custom_language = ! in_array( $settings['ai_content_language'], $preset_languages, true );
    /*! </fs_premium_only> */
    ?>
    <div class="wrap decaldesk-wrap">
        <h1><?php esc_html_e( 'DecalDesk – Settings', 'decaldesk' ); ?></h1>

        <form method="post" action="options.php">
            <?php settings_fields( 'decaldesk_settings_group' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="decaldesk_price_per_sqm"><?php esc_html_e( 'Price per m² (€)', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <input type="number" step="0.01" min="0" id="decaldesk_price_per_sqm"
                               name="decaldesk_settings[price_per_sqm]"
                               value="<?php echo esc_attr( $settings['price_per_sqm'] ); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="decaldesk_min_price"><?php esc_html_e( 'Minimum price (€)', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <input type="number" step="0.01" min="0" id="decaldesk_min_price"
                               name="decaldesk_settings[min_price]"
                               value="<?php echo esc_attr( $settings['min_price'] ); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="decaldesk_max_dimension_cm"><?php esc_html_e( 'Maximum size per side (cm)', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <input type="number" step="1" min="1" id="decaldesk_max_dimension_cm"
                               name="decaldesk_settings[max_dimension_cm]"
                               value="<?php echo esc_attr( $settings['max_dimension_cm'] ); ?>" class="regular-text">
                        <p class="description">
                            <?php esc_html_e( 'Protects against typos in the filename (e.g. an extra accidental zero). A file larger than this on either side will be rejected with a clear message.', 'decaldesk' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Extra text in the description', 'decaldesk' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="decaldesk_custom_footer_text"><?php esc_html_e( 'Text at the end of every description', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <textarea id="decaldesk_custom_footer_text" name="decaldesk_settings[custom_footer_text]"
                                  rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. For a different size, please request a quote by contacting us.', 'decaldesk' ); ?>"><?php echo esc_textarea( $settings['custom_footer_text'] ); ?></textarea>
                        <p class="description">
                            <?php esc_html_e( 'Optional. If filled in, it\'s automatically appended to the bottom of the description on EVERY new product (whether the description came from AI or the static template). Leave blank = nothing gets added.', 'decaldesk' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e( 'Mockup Image Optimization', 'decaldesk' ); ?></h2>
            <?php /*! <fs_premium_only> */ if ( decaldesk_fs()->can_use_premium_code() ) : ?>
            <p class="description">
                <?php esc_html_e( 'Mockups contain a photographic background (the template), so PNG usually ends up needlessly heavy. WebP keeps nearly the same quality at a much smaller file size — a faster site.', 'decaldesk' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="decaldesk_mockup_format"><?php esc_html_e( 'Mockup format', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <select id="decaldesk_mockup_format" name="decaldesk_settings[mockup_format]">
                            <option value="webp" <?php selected( $settings['mockup_format'], 'webp' ); ?>>
                                WebP (<?php esc_html_e( 'recommended — smallest size, good quality', 'decaldesk' ); ?>)
                            </option>
                            <option value="jpeg" <?php selected( $settings['mockup_format'], 'jpeg' ); ?>>
                                JPEG (<?php esc_html_e( 'wide compatibility', 'decaldesk' ); ?>)
                            </option>
                            <option value="png" <?php selected( $settings['mockup_format'], 'png' ); ?>>
                                PNG (<?php esc_html_e( 'lossless, but the heaviest file', 'decaldesk' ); ?>)
                            </option>
                        </select>
                    </td>
                </tr>
                <tr class="decaldesk-mockup-quality-row">
                    <th scope="row">
                        <label for="decaldesk_mockup_quality"><?php esc_html_e( 'Compression quality', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <input type="number" min="1" max="100" id="decaldesk_mockup_quality"
                               name="decaldesk_settings[mockup_quality]"
                               value="<?php echo esc_attr( $settings['mockup_quality'] ); ?>" class="small-text"> / 100
                        <p class="description">
                            <?php esc_html_e( 'Only applies to WebP/JPEG (PNG is always lossless, ignored here). 80-85 is a reasonable balance between quality and file size.', 'decaldesk' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php
            wp_add_inline_script( 'decaldesk-uploader', "
            (function ($) {
                $(function () {
                    function toggleQualityRow() {
                        var isPng = $('#decaldesk_mockup_format').val() === 'png';
                        $('.decaldesk-mockup-quality-row').toggle(!isPng);
                    }
                    toggleQualityRow();
                    $('#decaldesk_mockup_format').on('change', toggleQualityRow);
                });
            })(jQuery);
            " );
            ?>
            <?php else : /*! </fs_premium_only> */ ?>
            <p class="description">
                <span class="decaldesk-pro-badge"><?php esc_html_e( 'DecalDesk Pro', 'decaldesk' ); ?></span>
                <?php esc_html_e( 'Mockups are always saved as PNG in this version. WebP/JPEG compression (smaller, faster-loading files) is available in DecalDesk Pro.', 'decaldesk' ); ?>
                <a href="https://decaldesk.com/#pricing-calc" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'decaldesk' ); ?></a>
            </p>
            <?php /*! <fs_premium_only> */ endif; /*! </fs_premium_only> */ ?>

            <?php /*! <fs_premium_only> */ if ( decaldesk_fs()->can_use_premium_code() ) : ?>
            <p class="description">
                <?php
                printf(
                    wp_kses(
                        /* translators: %s: link to the Upload page */
                        __( 'The variant sizing setup (Variable Products) now lives in <a href="%s">DecalDesk → Upload</a>, right next to the variants option — easier to edit there while you upload.', 'decaldesk' ),
                        array( 'a' => array( 'href' => array() ) )
                    ),
                    esc_url( admin_url( 'admin.php?page=decaldesk' ) )
                );
                ?>
            </p>
            <?php else : /*! </fs_premium_only> */ ?>
            <p class="description">
                <?php esc_html_e( 'Every uploaded design is created as a Simple Product in this version. Selectable size/material/color variants (Variable Products) are available in DecalDesk Pro.', 'decaldesk' ); ?>
            </p>
            <?php /*! <fs_premium_only> */ endif; /*! </fs_premium_only> */ ?>

            <h2><?php esc_html_e( 'AI-generated descriptions', 'decaldesk' ); ?></h2>
            <?php /*! <fs_premium_only> */ if ( decaldesk_fs()->can_use_premium_code() ) : ?>
            <p class="description">
                <?php esc_html_e( 'Generates longer, sales-focused descriptions instead of the static template. Choose a free provider (Google Gemini, with a daily limit) or a paid one (Anthropic Claude, no limit).', 'decaldesk' ); ?>
            </p>

            <?php $quota = decaldesk_get_remaining_daily_quota(); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'AI provider', 'decaldesk' ); ?></th>
                    <td>
                        <label style="display:block; margin-bottom:8px;">
                            <input type="radio" name="decaldesk_settings[ai_provider]" value="none"
                                <?php checked( $settings['ai_provider'], 'none' ); ?>>
                            <?php esc_html_e( 'Disabled (use the static template)', 'decaldesk' ); ?>
                        </label>
                        <label style="display:block; margin-bottom:8px;">
                            <input type="radio" name="decaldesk_settings[ai_provider]" value="free_gemini"
                                <?php checked( $settings['ai_provider'], 'free_gemini' ); ?>>
                            <?php esc_html_e( 'Free — Google Gemini (daily limit, see below)', 'decaldesk' ); ?>
                        </label>
                        <label style="display:block;">
                            <input type="radio" name="decaldesk_settings[ai_provider]" value="claude"
                                <?php checked( $settings['ai_provider'], 'claude' ); ?>>
                            <?php esc_html_e( 'Paid — Anthropic Claude (no built-in limit)', 'decaldesk' ); ?>
                        </label>

                        <p style="margin-top:14px;">
                            <button type="button" id="decaldesk-test-ai-btn" class="button">
                                <?php esc_html_e( 'Test connection with AI provider', 'decaldesk' ); ?>
                            </button>
                            <span id="decaldesk-test-ai-spinner" class="spinner" style="float:none; vertical-align:middle;"></span>
                        </p>
                        <div id="decaldesk-test-ai-result" style="display:none; margin-top:10px; padding:10px 14px; border-radius:4px; max-width:700px;"></div>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="decaldesk_ai_content_language"><?php esc_html_e( 'AI content language', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <select id="decaldesk_ai_content_language" name="decaldesk_settings[ai_content_language]">
                            <?php foreach ( $preset_languages as $lang ) : ?>
                                <option value="<?php echo esc_attr( $lang ); ?>" <?php selected( ! $is_custom_language && $settings['ai_content_language'] === $lang ); ?>>
                                    <?php echo esc_html( $lang ); ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="custom" <?php selected( $is_custom_language ); ?>>
                                <?php esc_html_e( 'Other (type below)', 'decaldesk' ); ?>
                            </option>
                        </select>
                        <input type="text" id="decaldesk_ai_content_language_custom" name="decaldesk_settings[ai_content_language_custom]"
                               value="<?php echo $is_custom_language ? esc_attr( $settings['ai_content_language'] ) : ''; ?>"
                               placeholder="<?php esc_attr_e( 'e.g. Czech, Hungarian, Swedish...', 'decaldesk' ); ?>"
                               class="regular-text" style="display:<?php echo $is_custom_language ? 'inline-block' : 'none'; ?>; margin-left:8px;">
                        <p class="description">
                            <?php esc_html_e( 'The language for AI-generated product descriptions and tags shown to your store customers — independent of the admin panel language. This does not need to match the language your store operates in unless you want it to.', 'decaldesk' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php
            wp_add_inline_script( 'decaldesk-uploader', "
            (function ($) {
                $(function () {
                    var \$langSelect = $('#decaldesk_ai_content_language');
                    var \$langCustom = $('#decaldesk_ai_content_language_custom');

                    \$langSelect.on('change', function () {
                        \$langCustom.toggle($(this).val() === 'custom');
                    });
                });
            })(jQuery);
            " );

            wp_add_inline_script( 'decaldesk-uploader', "
            (function ($) {
                $(function () {
                    $('#decaldesk-test-ai-btn').on('click', function (e) {
                        e.preventDefault();
                        var \$btn = $(this);
                        var \$spinner = $('#decaldesk-test-ai-spinner');
                        var \$result = $('#decaldesk-test-ai-result');

                        \$btn.prop('disabled', true);
                        \$spinner.addClass('is-active');
                        \$result.hide().removeClass('notice-success notice-error').empty();

                        $.post(ajaxurl, {
                            action: 'decaldesk_test_ai_connection',
                            nonce: '" . esc_js( wp_create_nonce( 'decaldesk_test_ai_nonce' ) ) . "'
                        }).done(function (response) {
                            \$spinner.removeClass('is-active');
                            \$btn.prop('disabled', false);

                            if (response.success) {
                                \$result
                                    .css({ background: '#edfaef', borderLeft: '4px solid #00a32a', color: '#00450c' })
                                    .html('<strong>Success!</strong> The AI responded correctly.<br><pre style=\"white-space:pre-wrap; margin-top:8px; font-size:12px;\">' +
                                        $('<div>').text(response.data.raw).html() + '</pre>')
                                    .show();
                            } else {
                                \$result
                                    .css({ background: '#fcf0f1', borderLeft: '4px solid #d63638', color: '#5a0a0d' })
                                    .html('<strong>Error:</strong> ' + $('<div>').text(response.data.message).html())
                                    .show();
                            }
                        }).fail(function () {
                            \$spinner.removeClass('is-active');
                            \$btn.prop('disabled', false);
                            \$result
                                .css({ background: '#fcf0f1', borderLeft: '4px solid #d63638', color: '#5a0a0d' })
                                .text('The request failed (network error).')
                                .show();
                        });
                    });
                });
            })(jQuery);
            " );
            ?>

            <h3><?php esc_html_e( 'AI Vision — image analysis', 'decaldesk' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Look at the design itself', 'decaldesk' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="decaldesk_settings[ai_use_vision]" value="1"
                                <?php checked( ! empty( $settings['ai_use_vision'] ) ); ?>>
                            <?php esc_html_e( 'Let the AI look at the actual image (not just the filename) when writing the description', 'decaldesk' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Both providers (Gemini and Claude) support image recognition. The design is automatically downscaled before sending, to save bandwidth and tokens. The result is a much more accurate, specific description, instead of a generic one based only on size/material/category.', 'decaldesk' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Free provider — Google Gemini', 'decaldesk' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php esc_html_e( 'Today\'s usage', 'decaldesk' ); ?>
                    </th>
                    <td>
                        <strong><?php echo (int) $quota['used']; ?> / <?php echo (int) $quota['limit']; ?></strong>
                        <?php esc_html_e( 'free AI descriptions used today.', 'decaldesk' ); ?>
                        <?php if ( 0 === $quota['remaining'] ) : ?>
                            <span style="color:#d63638;"><?php esc_html_e( 'The limit has been reached — the static template will be used until tomorrow.', 'decaldesk' ); ?></span>
                        <?php endif; ?>
                        <p class="description"><?php esc_html_e( 'The counter resets automatically at midnight. Google itself allows far more (hundreds per day) — this limit is just our own safeguard.', 'decaldesk' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="decaldesk_gemini_daily_limit"><?php esc_html_e( 'Daily limit', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <input type="number" min="1" max="500" id="decaldesk_gemini_daily_limit"
                               name="decaldesk_settings[gemini_daily_limit]"
                               value="<?php echo esc_attr( $settings['gemini_daily_limit'] ); ?>" class="small-text">
                        <p class="description"><?php esc_html_e( 'How many products per day should get an AI description before switching to the template.', 'decaldesk' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="decaldesk_gemini_api_key"><?php esc_html_e( 'Google Gemini API key', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <?php if ( defined( 'DECALDESK_GEMINI_API_KEY' ) && DECALDESK_GEMINI_API_KEY ) : ?>
                            <input type="text" value="<?php esc_attr_e( 'Set via the DECALDESK_GEMINI_API_KEY constant in wp-config.php', 'decaldesk' ); ?>" class="regular-text" disabled>
                        <?php else : ?>
                            <input type="password" id="decaldesk_gemini_api_key" name="decaldesk_settings[gemini_api_key]"
                                   value="" placeholder="<?php echo ! empty( $settings['gemini_api_key'] ) ? esc_attr__( '•••••••••••••••••••• (saved, leave blank to keep it)', 'decaldesk' ) : 'AIza...'; ?>"
                                   class="regular-text" autocomplete="off">
                        <?php endif; ?>
                        <p class="description">
                            <?php esc_html_e( 'Get a free key from Google AI Studio (no card required):', 'decaldesk' ); ?>
                            <a href="https://aistudio.google.com/app/apikey" target="_blank">aistudio.google.com/app/apikey</a><br>
                            <?php esc_html_e( 'For a more secure setup, define it in wp-config.php:', 'decaldesk' ); ?>
                            <code>define( 'DECALDESK_GEMINI_API_KEY', 'AIza...' );</code>
                        </p>
                    </td>
                </tr>
            </table>

            <h3><?php esc_html_e( 'Paid provider — Anthropic Claude', 'decaldesk' ); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="decaldesk_ai_api_key"><?php esc_html_e( 'Anthropic API key', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <?php if ( defined( 'DECALDESK_AI_API_KEY' ) && DECALDESK_AI_API_KEY ) : ?>
                            <input type="text" value="<?php esc_attr_e( 'Set via the DECALDESK_AI_API_KEY constant in wp-config.php', 'decaldesk' ); ?>" class="regular-text" disabled>
                            <p class="description"><?php esc_html_e( 'The field below is ignored while the constant is defined — this is the more secure approach.', 'decaldesk' ); ?></p>
                        <?php else : ?>
                            <input type="password" id="decaldesk_ai_api_key" name="decaldesk_settings[ai_api_key]"
                                   value="" placeholder="<?php echo ! empty( $settings['ai_api_key'] ) ? esc_attr__( '•••••••••••••••••••• (saved, leave blank to keep it)', 'decaldesk' ) : 'sk-ant-...'; ?>"
                                   class="regular-text" autocomplete="off">
                            <p class="description">
                                <?php esc_html_e( 'The key is stored in the database. For a more secure setup, define it in wp-config.php instead:', 'decaldesk' ); ?><br>
                                <code>define( 'DECALDESK_AI_API_KEY', 'sk-ant-...' );</code>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="decaldesk_ai_model"><?php esc_html_e( 'Model', 'decaldesk' ); ?></label>
                    </th>
                    <td>
                        <select id="decaldesk_ai_model" name="decaldesk_settings[ai_model]">
                            <option value="claude-haiku-4-5-20251001" <?php selected( $settings['ai_model'], 'claude-haiku-4-5-20251001' ); ?>>
                                Claude Haiku 4.5 (<?php esc_html_e( 'fastest and cheapest', 'decaldesk' ); ?>)
                            </option>
                            <option value="claude-sonnet-4-6" <?php selected( $settings['ai_model'], 'claude-sonnet-4-6' ); ?>>
                                Claude Sonnet 4.6 (<?php esc_html_e( 'recommended balance', 'decaldesk' ); ?>)
                            </option>
                            <option value="claude-opus-4-8" <?php selected( $settings['ai_model'], 'claude-opus-4-8' ); ?>>
                                Claude Opus 4.8 (<?php esc_html_e( 'highest quality, more expensive', 'decaldesk' ); ?>)
                            </option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php else : /*! </fs_premium_only> */ ?>
            <p class="description">
                <span class="decaldesk-pro-badge"><?php esc_html_e( 'DecalDesk Pro', 'decaldesk' ); ?></span>
                <?php esc_html_e( 'This version always uses the static description template. AI-generated descriptions (Google Gemini or Anthropic Claude) are available in DecalDesk Pro.', 'decaldesk' ); ?>
                <a href="https://decaldesk.com/#pricing-calc" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn more', 'decaldesk' ); ?></a>
            </p>
            <?php /*! <fs_premium_only> */ endif; /*! </fs_premium_only> */ ?>

            <h2><?php esc_html_e( 'Categories & Mockup Templates', 'decaldesk' ); ?></h2>
            <p class="description">
                <?php
                printf(
                    wp_kses(
                        /* translators: %s: link to the Categories page */
                        __( 'Category management (slug/name), mockup templates, and design positioning now live on a separate page: <a href="%s">DecalDesk → Categories</a>.', 'decaldesk' ),
                        array( 'a' => array( 'href' => array() ) )
                    ),
                    esc_url( admin_url( 'admin.php?page=decaldesk-categories' ) )
                );
                ?>
            </p>

            <h2><?php esc_html_e( 'On plugin deletion', 'decaldesk' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Full cleanup', 'decaldesk' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="decaldesk_settings[delete_data_on_uninstall]" value="1"
                                <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>>
                            <?php esc_html_e( 'Delete all plugin data (settings, daily quota, log files, temporary files in incoming/mockups) when the plugin is fully removed', 'decaldesk' ); ?>
                        </label>
                        <p class="description">
                            <strong><?php esc_html_e( 'OFF by default (safer)', 'decaldesk' ); ?></strong> —
                            <?php esc_html_e( 'if you just deactivate the plugin, or delete it to upload a new version, your data stays intact.', 'decaldesk' ); ?><br>
                            <?php esc_html_e( 'Only check this if you\'re removing the plugin for good and want a clean wipe.', 'decaldesk' ); ?><br><br>
                            <?php esc_html_e( 'Important: WooCommerce products you\'ve already created with the plugin are NEVER deleted automatically — this only affects DecalDesk\'s own internal data.', 'decaldesk' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save settings', 'decaldesk' ) ); ?>
        </form>
    </div>
    <?php
}
