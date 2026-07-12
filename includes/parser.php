<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Парсва име на файл във формат:
 * name_widthxheight_material_category.разширение
 * (разширението е без значение за парсването - поддържат се PNG, JPG/JPEG, WEBP, GIF)
 *
 * Пример: koleda_50x70_matte_kitchen.jpg
 *
 * @param string $filename Оригиналното име на файла.
 * @return array|WP_Error {
 *     @type string $name     Име на дизайна (с интервали вместо тирета/долни черти).
 *     @type int    $width    Ширина в см.
 *     @type int    $height   Височина в см.
 *     @type string $material Материал (напр. matte, gloss, transparent).
 *     @type string $category Категория (напр. kitchen, wrap, wall, sticker).
 * }
 */
function decaldesk_parse_filename( $filename ) {
    // Премахваме разширението (без значение кое е - png/jpg/webp/gif)
    $base = pathinfo( $filename, PATHINFO_FILENAME );

    // Очакван шаблон: name_WIDTHxHEIGHT_material_category
    $pattern = '/^(?P<name>.+)_(?P<width>\d+)x(?P<height>\d+)_(?P<material>[a-zA-Z0-9]+)_(?P<category>[a-zA-Z0-9\-]+)$/u';

    if ( ! preg_match( $pattern, $base, $matches ) ) {
        return new WP_Error(
            'decaldesk_parse_error',
            sprintf(
                /* translators: %s: the filename */
                __( 'The name "%s" doesn\'t match the format name_widthxheight_material_category.extension', 'decaldesk' ),
                $filename
            )
        );
    }

    $width  = (int) $matches['width'];
    $height = (int) $matches['height'];

    if ( $width <= 0 || $height <= 0 ) {
        return new WP_Error(
            'decaldesk_parse_error',
            __( 'Dimensions must be positive numbers.', 'decaldesk' )
        );
    }

    // Горна граница на размерите (защита срещу грешка при преименуване -
    // напр. случайно поставена допълнителна нула: 5000 вместо 500 см)
    $settings   = get_option( 'decaldesk_settings', array() );
    $max_cm     = ! empty( $settings['max_dimension_cm'] ) ? (int) $settings['max_dimension_cm'] : 1000;

    if ( $width > $max_cm || $height > $max_cm ) {
        return new WP_Error(
            'decaldesk_parse_error',
            sprintf(
                /* translators: 1: given width, 2: given height, 3: maximum allowed value in cm */
                __( 'The dimensions %1$d x %2$d cm look unrealistically large (maximum %3$d cm per side). Check for a typo in the filename.', 'decaldesk' ),
                $width,
                $height,
                $max_cm
            )
        );
    }

    // Разкрасяваме името: заменяме тирета/долни черти с интервал и главна буква
    $pretty_name = str_replace( array( '_', '-' ), ' ', $matches['name'] );
    $pretty_name = ucfirst( trim( $pretty_name ) );

    return array(
        'name'     => $pretty_name,
        'width'    => $width,
        'height'   => $height,
        'material' => strtolower( $matches['material'] ),
        'category' => strtolower( $matches['category'] ),
        'raw_name' => $matches['name'],
    );
}

/**
 * ==========================================================
 * Валидация на СЪДЪРЖАНИЕТО на качения файл (не само разширението)
 * ==========================================================
 */

/**
 * Проверява, че файлът реално е валидно изображение от позволен тип,
 * четейки истинските image headers (не по разширение или Content-Type,
 * които лесно се подправят). Използва вградената getimagesize() - тя чете
 * бинарната сигнатура на файла директно и не изисква GD/Imagick.
 *
 * Спира и абсурдно големи резолюции (защита срещу "decompression bomb"
 * файлове - малки на диск, но с огромни пикселни размери, които могат
 * да източат паметта на сървъра при последващата обработка).
 *
 * @param string $tmp_path Път до временния файл ($_FILES[...]['tmp_name']).
 * @return true|WP_Error true при валиден файл, WP_Error с описание при проблем.
 */
function decaldesk_validate_image_content( $tmp_path ) {
    if ( ! file_exists( $tmp_path ) || 0 === filesize( $tmp_path ) ) {
        return new WP_Error( 'decaldesk_invalid_image', __( 'The file is empty or corrupted.', 'decaldesk' ) );
    }

    $info = @getimagesize( $tmp_path );

    if ( false === $info ) {
        return new WP_Error(
            'decaldesk_invalid_image',
            __( 'The file wasn\'t recognized as a valid image (content check failed). It may be corrupted, or not actually an image despite its extension.', 'decaldesk' )
        );
    }

    // IMAGETYPE_* константи, съответстващи на нашите позволени формати
    $allowed_types = array(
        IMAGETYPE_PNG,
        IMAGETYPE_JPEG,
        IMAGETYPE_GIF,
    );
    if ( defined( 'IMAGETYPE_WEBP' ) ) {
        $allowed_types[] = IMAGETYPE_WEBP;
    }

    if ( ! in_array( $info[2], $allowed_types, true ) ) {
        return new WP_Error(
            'decaldesk_invalid_image',
            sprintf(
                /* translators: %s: the file's actually detected MIME type */
                __( 'The file was recognized as "%s", which isn\'t an allowed design format.', 'decaldesk' ),
                isset( $info['mime'] ) ? $info['mime'] : __( 'unknown type', 'decaldesk' )
            )
        );
    }

    list( $width, $height ) = $info;

    if ( $width < 1 || $height < 1 ) {
        return new WP_Error( 'decaldesk_invalid_image', __( 'The image has invalid (zero) pixel dimensions.', 'decaldesk' ) );
    }

    // Защита срещу decompression bomb - разумен таван за печатни дизайни
    $max_pixel_dimension = 12000;
    if ( $width > $max_pixel_dimension || $height > $max_pixel_dimension ) {
        return new WP_Error(
            'decaldesk_invalid_image',
            sprintf(
                /* translators: 1: actual width in px, 2: actual height in px, 3: maximum allowed value */
                __( 'The image resolution is too high (%1$d x %2$d px). The maximum is %3$d px on the longer side.', 'decaldesk' ),
                $width,
                $height,
                $max_pixel_dimension
            )
        );
    }

    return true;
}

/**
 * Превръща PHP UPLOAD_ERR_* кода в разбираемо съобщение на български.
 *
 * @param int $error_code Стойност от $_FILES[...]['error'].
 * @return string Съобщение за грешка.
 */
function decaldesk_upload_error_message( $error_code ) {
    switch ( $error_code ) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return __( 'The file is too large (exceeds the server\'s upload limit).', 'decaldesk' );
        case UPLOAD_ERR_PARTIAL:
            return __( 'The file was only partially uploaded — try again.', 'decaldesk' );
        case UPLOAD_ERR_NO_FILE:
            return __( 'No file selected.', 'decaldesk' );
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return __( 'Server error during upload — check your hosting configuration.', 'decaldesk' );
        default:
            return __( 'Upload failed (unknown error).', 'decaldesk' );
    }
}
