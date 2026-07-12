<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'DECALDESK_MAX_TEMPLATES_PER_CATEGORY' ) ) {
    define( 'DECALDESK_MAX_TEMPLATES_PER_CATEGORY', 4 );
}

/**
 * Генерира мокъп изображение(я), наслагвайки дизайна върху шаблон(и) за категорията.
 *
 * Всяка категория може да има до DECALDESK_MAX_TEMPLATES_PER_CATEGORY (4)
 * шаблона (напр. за "коли" - няколко различни модела, за да изглежда дизайнът
 * по-убедително в различен контекст). По подразбиране се генерира само ЕДИН
 * мокъп (от първия шаблон) - по-бързо. Ако $generate_all е true, се генерира
 * по един мокъп за ВСЕКИ конфигуриран шаблон (по избор на потребителя при
 * качване, тъй като е по-бавно и не винаги е нужно).
 *
 * Приоритет на търсене на шаблоните за слот N:
 * 1. wp-content/uploads/decaldesk/templates/{category}-{N}.{ext} - качен през UI
 * 2. (само за слот 1, ако няма нищо друго) legacy {category}.{ext} - стари категории отпреди multi-template
 * 3. wp-content/plugins/decaldesk/assets/templates/{category}.png - вграден с плъгина
 * 4. wp-content/uploads/decaldesk/templates/default.{ext} - качен default през UI
 * 5. wp-content/plugins/decaldesk/assets/templates/default.png - вграден default
 *
 * @param string $design_path  Пълен път до качения дизайн.
 * @param string $category     Категория (напр. kitchen, wrap, wall, sticker).
 * @param bool   $generate_all Дали да се генерира мокъп за ВСЕКИ шаблон на категорията (по-бавно).
 * @return string[]|WP_Error Списък с пътища до генерираните мокъп файлове (винаги поне 1), или WP_Error при грешка.
 */
function decaldesk_generate_mockup( $design_path, $category, $generate_all = false ) {
    if ( ! file_exists( $design_path ) ) {
        return new WP_Error( 'decaldesk_mockup_error', __( 'The design file doesn\'t exist.', 'decaldesk' ) );
    }

    $template_paths = decaldesk_resolve_template_paths( $category );
    $zones          = decaldesk_get_template_zones( $category );
    $output_config  = decaldesk_get_mockup_output_config();

    if ( ! $generate_all ) {
        $template_paths = array_slice( $template_paths, 0, 1 );
    }

    $upload_dir       = wp_upload_dir();
    $mockup_dir       = $upload_dir['basedir'] . '/decaldesk/mockups';
    $design_basename  = pathinfo( $design_path, PATHINFO_FILENAME );
    $multiple         = count( $template_paths ) > 1;

    $mockup_paths = array();
    $default_zone = array( 'x' => 15, 'y' => 15, 'width' => 70, 'height' => 70 );

    foreach ( $template_paths as $index => $template_path ) {
        $zone = isset( $zones[ $index ] ) ? $zones[ $index ] : $default_zone;

        $suffix      = $multiple ? '-mockup-' . ( $index + 1 ) : '-mockup';
        $mockup_name = $design_basename . $suffix . '.' . $output_config['extension'];
        $mockup_path = $mockup_dir . '/' . $mockup_name;

        if ( class_exists( 'Imagick' ) ) {
            $result = decaldesk_generate_mockup_imagick( $design_path, $template_path, $mockup_path, $zone, $output_config );
        } else {
            $result = decaldesk_generate_mockup_gd( $design_path, $template_path, $mockup_path, $zone, $output_config );
        }

        if ( is_wp_error( $result ) ) {
            // Ако вече имаме поне 1 успешен мокъп, не проваляме ЦЯЛАТА операция
            // заради проблем с ДОПЪЛНИТЕЛЕН шаблон - логваме и продължаваме напред.
            if ( ! empty( $mockup_paths ) ) {
                decaldesk_log( 'Неуспешна генерация на допълнителен мокъп (' . $template_path . '): ' . $result->get_error_message() );
                continue;
            }
            return $result;
        }

        $mockup_paths[] = $mockup_path;
    }

    if ( empty( $mockup_paths ) ) {
        return new WP_Error( 'decaldesk_mockup_error', __( 'Failed to generate any mockup.', 'decaldesk' ) );
    }

    return $mockup_paths;
}

/**
 * Връща конфигурацията за изходния формат на мокъпа от настройките.
 *
 * @return array{format: string, extension: string, quality: int}
 */
function decaldesk_get_mockup_output_config() {
    $settings = get_option( 'decaldesk_settings', array() );

    $format = isset( $settings['mockup_format'] ) && in_array( $settings['mockup_format'], array( 'webp', 'jpeg', 'png' ), true )
        ? $settings['mockup_format']
        : 'webp';

    // Ако е избран WebP, но нито Imagick, нито GD с webp поддръжка са налични -
    // падаме на PNG ОЩЕ ТУК, за да могат разширението на файла и реалния запис
    // да съвпадат винаги (иначе можем да завършим с .webp път, но реално PNG файл).
    if ( 'webp' === $format && ! decaldesk_webp_supported() ) {
        decaldesk_log( 'WebP не се поддържа на този сървър - падаме на PNG за мокъпите.' );
        $format = 'png';
    }

    $quality = isset( $settings['mockup_quality'] ) ? max( 1, min( 100, (int) $settings['mockup_quality'] ) ) : 82;

    // .jpg е по-универсално разпознато разширение от .jpeg (макар и еквивалентно)
    $extension = 'jpeg' === $format ? 'jpg' : $format;

    return array(
        'format'    => $format,
        'extension' => $extension,
        'quality'   => $quality,
    );
}

/**
 * Проверява дали текущият сървър реално може да записва WebP - или чрез
 * Imagick (с webp coder), или чрез GD с imagewebp().
 */
function decaldesk_webp_supported() {
    if ( class_exists( 'Imagick' ) ) {
        $formats = @Imagick::queryFormats( 'WEBP' );
        if ( ! empty( $formats ) ) {
            return true;
        }
    }

    return function_exists( 'imagewebp' );
}

/**
 * Намира до DECALDESK_MAX_TEMPLATES_PER_CATEGORY шаблонни файла за категория,
 * с приоритет: качени през UI (по слотове) > legacy единичен файл > вграден >
 * default качен > default вграден.
 *
 * @param string $category
 * @return string[] Списък с пътища (винаги поне 1 елемент).
 */
function decaldesk_resolve_template_paths( $category ) {
    $upload_dir  = wp_upload_dir();
    $uploads_tpl = $upload_dir['basedir'] . '/decaldesk/templates';
    $bundled_tpl = DECALDESK_PATH . 'assets/templates';
    $extensions  = array( 'png', 'jpg', 'jpeg', 'webp' );

    $paths = array();

    // 1) Качени през UI шаблони по слотове: {category}-1.ext, {category}-2.ext, ...
    for ( $slot = 1; $slot <= DECALDESK_MAX_TEMPLATES_PER_CATEGORY; $slot++ ) {
        foreach ( $extensions as $ext ) {
            $path = $uploads_tpl . '/' . $category . '-' . $slot . '.' . $ext;
            if ( file_exists( $path ) ) {
                $paths[] = $path;
                continue 2;
            }
        }
    }

    // 2) Backward compat: стари категории (отпреди multi-template) имат само
    // {category}.ext без суфикс - третираме като слот 1, само ако горе не е
    // намерено нищо по новия начин на именуване.
    if ( empty( $paths ) ) {
        foreach ( $extensions as $ext ) {
            $path = $uploads_tpl . '/' . $category . '.' . $ext;
            if ( file_exists( $path ) ) {
                $paths[] = $path;
                break;
            }
        }
    }

    // 3) Вграден в плъгина шаблон за тази категория
    if ( empty( $paths ) ) {
        $path = $bundled_tpl . '/' . $category . '.png';
        if ( file_exists( $path ) ) {
            $paths[] = $path;
        }
    }

    // 4) Качен през UI default шаблон
    if ( empty( $paths ) ) {
        foreach ( $extensions as $ext ) {
            $path = $uploads_tpl . '/default.' . $ext;
            if ( file_exists( $path ) ) {
                $paths[] = $path;
                break;
            }
        }
    }

    // 5) Вграден default (последна опция - винаги съществува)
    if ( empty( $paths ) ) {
        $paths[] = $bundled_tpl . '/default.png';
    }

    return $paths;
}

/**
 * Backward-compat: връща само ПЪРВИЯ шаблон. Използва се от кода, писан
 * преди multi-template поддръжката (напр. превюто в списъка с категории).
 *
 * @param string $category
 * @return string
 */
function decaldesk_resolve_template_path( $category ) {
    $paths = decaldesk_resolve_template_paths( $category );
    return $paths[0];
}

/**
 * Връща позициониращи зони за категория - по една зона на всеки конфигуриран
 * шаблон (индексите съвпадат с decaldesk_resolve_template_paths()).
 *
 * Поддържа и стария формат (единичен асоциативен масив с ключ 'x' директно)
 * от преди multi-template поддръжката - той автоматично се третира като
 * зона за слот 1.
 *
 * @param string $category
 * @return array[] Списък със зони {x,y,width,height} (винаги поне 1 елемент).
 */
function decaldesk_get_template_zones( $category ) {
    $settings     = get_option( 'decaldesk_settings', array() );
    $zones_config = isset( $settings['template_zones'][ $category ] ) ? $settings['template_zones'][ $category ] : null;
    $default_zone = array( 'x' => 15, 'y' => 15, 'width' => 70, 'height' => 70 );

    if ( null === $zones_config || ! is_array( $zones_config ) ) {
        return array( $default_zone );
    }

    // Стар формат: единична зона с ключ 'x' директно (не индексиран списък)
    if ( isset( $zones_config['x'] ) ) {
        return array( wp_parse_args( $zones_config, $default_zone ) );
    }

    // Нов формат: индексиран списък от зони, по една на слот
    $result = array();
    foreach ( $zones_config as $zone ) {
        $result[] = wp_parse_args( (array) $zone, $default_zone );
    }

    return ! empty( $result ) ? $result : array( $default_zone );
}

/**
 * Backward-compat: връща само зоната за ПЪРВИЯ шаблон/слот.
 *
 * @param string $category
 * @return array{x: float, y: float, width: float, height: float}
 */
function decaldesk_get_template_zone( $category ) {
    $zones = decaldesk_get_template_zones( $category );
    return $zones[0];
}

/**
 * Изчислява "contain fit" позициониране: смалява дизайна така, че да се
 * побере ЦЯЛ вътре в зададена кутия (запазвайки пропорциите му), центриран
 * вътре в кутията - същата логика като CSS "object-fit: contain".
 *
 * @param float $design_w Реална ширина на дизайна (px).
 * @param float $design_h Реална височина на дизайна (px).
 * @param float $box_w    Ширина на целевата кутия (px).
 * @param float $box_h    Височина на целевата кутия (px).
 * @return array{width: int, height: int, offset_x: int, offset_y: int} Крайни размери и offset СПРЯМО кутията.
 */
function decaldesk_calculate_contain_fit( $design_w, $design_h, $box_w, $box_h ) {
    $scale = min( $box_w / $design_w, $box_h / $design_h );

    $new_w = $design_w * $scale;
    $new_h = $design_h * $scale;

    return array(
        'width'    => (int) round( $new_w ),
        'height'   => (int) round( $new_h ),
        'offset_x' => (int) round( ( $box_w - $new_w ) / 2 ),
        'offset_y' => (int) round( ( $box_h - $new_h ) / 2 ),
    );
}

/**
 * Изчислява "cover fit" позициониране: увеличава дизайна така, че да ЗАПЪЛНИ
 * цялата кутия (запазвайки пропорциите му), центриран - краищата на дизайна
 * може да излязат извън кутията (реалистична "фира", както при истинско
 * рязане на фолио) - обратното на "contain fit". Използва се за полигонални
 * (свободна форма) зони, за да няма видим фон в ъглите на неправилната форма.
 *
 * @param float $design_w Реална ширина на дизайна (px).
 * @param float $design_h Реална височина на дизайна (px).
 * @param float $box_w    Ширина на целевата кутия (px).
 * @param float $box_h    Височина на целевата кутия (px).
 * @return array{width: int, height: int, offset_x: int, offset_y: int} Крайни размери и offset (може да е отрицателен) СПРЯМО кутията.
 */
function decaldesk_calculate_cover_fit( $design_w, $design_h, $box_w, $box_h ) {
    $scale = max( $box_w / $design_w, $box_h / $design_h );

    $new_w = $design_w * $scale;
    $new_h = $design_h * $scale;

    return array(
        'width'    => (int) round( $new_w ),
        'height'   => (int) round( $new_h ),
        'offset_x' => (int) round( ( $box_w - $new_w ) / 2 ),
        'offset_y' => (int) round( ( $box_h - $new_h ) / 2 ),
    );
}

/**
 * Генериране на мокъп чрез Imagick (предпочитан вариант, по-качествен).
 *
 * Поддържа два вида зона:
 * - 'rect' (по подразбиране): правоъгълник x/y/width/height, "contain fit"
 *   (дизайнът се вписва цял, може да остане видим фон в краищата).
 * - 'polygon': произволна форма от точки, "cover fit" (дизайнът запълва
 *   цялата форма, изрязан по контура - реалистична "фира" като при истинско
 *   лепене на фолио върху неправилна повърхност).
 *
 * @param string $design_path
 * @param string $template_path
 * @param string $output_path
 * @param array  $zone          Зона (от decaldesk_get_template_zone) - rect или polygon.
 * @param array  $output_config format/extension/quality (от decaldesk_get_mockup_output_config).
 */
function decaldesk_generate_mockup_imagick( $design_path, $template_path, $output_path, $zone = array(), $output_config = array() ) {
    try {
        $template = new Imagick( $template_path );
        $design   = new Imagick( $design_path );

        $tpl_width  = $template->getImageWidth();
        $tpl_height = $template->getImageHeight();

        $zone_type = isset( $zone['type'] ) && 'polygon' === $zone['type'] ? 'polygon' : 'rect';

        if ( 'polygon' === $zone_type && ! empty( $zone['points'] ) && count( $zone['points'] ) >= 3 ) {
            decaldesk_composite_polygon_imagick( $template, $design, $zone['points'], $tpl_width, $tpl_height );
        } else {
            $zone = wp_parse_args( $zone, array( 'x' => 15, 'y' => 15, 'width' => 70, 'height' => 70 ) );

            // Превръщаме зоната от проценти в пиксели спрямо реалните размери на шаблона
            $box_x = (int) round( $tpl_width * ( $zone['x'] / 100 ) );
            $box_y = (int) round( $tpl_height * ( $zone['y'] / 100 ) );
            $box_w = (int) round( $tpl_width * ( $zone['width'] / 100 ) );
            $box_h = (int) round( $tpl_height * ( $zone['height'] / 100 ) );

            $design_width  = $design->getImageWidth();
            $design_height = $design->getImageHeight();

            // "Contain fit" - дизайнът се смалява да се побере ЦЯЛ в зоната, центриран в нея
            $fit = decaldesk_calculate_contain_fit( $design_width, $design_height, $box_w, $box_h );

            $design->resizeImage( $fit['width'], $fit['height'], Imagick::FILTER_LANCZOS, 1 );

            $x = $box_x + $fit['offset_x'];
            $y = $box_y + $fit['offset_y'];

            $template->compositeImage( $design, Imagick::COMPOSITE_OVER, $x, $y );
        }

        $output_config = wp_parse_args( $output_config, array( 'format' => 'webp', 'quality' => 82 ) );

        // JPEG не поддържа прозрачност - "изравняваме" върху бял фон, за да няма
        // черни артефакти, ако шаблонът по някаква причина има alpha канал.
        if ( 'jpeg' === $output_config['format'] ) {
            $template->setImageBackgroundColor( new ImagickPixel( 'white' ) );
            $template = $template->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
        }

        $template->setImageFormat( $output_config['format'] );

        if ( in_array( $output_config['format'], array( 'webp', 'jpeg' ), true ) ) {
            $template->setImageCompressionQuality( $output_config['quality'] );
        }

        $template->writeImage( $output_path );

        $template->clear();
        $design->clear();

        return true;
    } catch ( Exception $e ) {
        return new WP_Error( 'decaldesk_mockup_error', $e->getMessage() );
    }
}

/**
 * Наслагва дизайна в произволна полигонална форма (Imagick) - "cover fit"
 * (запълва цялата форма, изрязан по контура) + alpha маска по формата.
 *
 * @param Imagick $template   Шаблонът (модифицира се директно, by reference по природа на Imagick обектите).
 * @param Imagick $design     Дизайнът (ще бъде преоразмерен).
 * @param array   $points     Точки на полигона в проценти: [{x,y}, {x,y}, ...].
 * @param int     $tpl_width  Реална ширина на шаблона в px.
 * @param int     $tpl_height Реална височина на шаблона в px.
 */
function decaldesk_composite_polygon_imagick( $template, $design, $points, $tpl_width, $tpl_height ) {
    // Превръщаме точките от проценти в пиксели спрямо реалните размери на шаблона
    $pixel_points = array();
    $min_x = $tpl_width;
    $max_x = 0;
    $min_y = $tpl_height;
    $max_y = 0;

    foreach ( $points as $point ) {
        $px = $point['x'] / 100 * $tpl_width;
        $py = $point['y'] / 100 * $tpl_height;
        $pixel_points[] = array( 'x' => $px, 'y' => $py );

        $min_x = min( $min_x, $px );
        $max_x = max( $max_x, $px );
        $min_y = min( $min_y, $py );
        $max_y = max( $max_y, $py );
    }

    $box_x = (int) round( $min_x );
    $box_y = (int) round( $min_y );
    $box_w = max( 1, (int) round( $max_x - $min_x ) );
    $box_h = max( 1, (int) round( $max_y - $min_y ) );

    $design_width  = $design->getImageWidth();
    $design_height = $design->getImageHeight();

    // "Cover fit" - дизайнът ЗАПЪЛВА цялата кутия (може да отреже краищата му),
    // центриран - реалистична "фира" както при истинско рязане/лепене.
    $fit = decaldesk_calculate_cover_fit( $design_width, $design_height, $box_w, $box_h );
    $design->resizeImage( $fit['width'], $fit['height'], Imagick::FILTER_LANCZOS, 1 );

    // Изграждаме дизайн слой в РАЗМЕРА НА ЦЯЛОТО платно (не само кутията),
    // за да можем после да приложим маска в същите координати като шаблона.
    $design_layer = new Imagick();
    $design_layer->newImage( $tpl_width, $tpl_height, new ImagickPixel( 'transparent' ) );
    $design_layer->setImageFormat( 'png' );

    $paste_x = $box_x + $fit['offset_x'];
    $paste_y = $box_y + $fit['offset_y'];
    $design_layer->compositeImage( $design, Imagick::COMPOSITE_OVER, $paste_x, $paste_y );

    // Маска: бял полигон върху черен фон, в размера на цялото платно
    $mask = new Imagick();
    $mask->newImage( $tpl_width, $tpl_height, new ImagickPixel( 'black' ) );

    $draw = new ImagickDraw();
    $draw->setFillColor( new ImagickPixel( 'white' ) );
    $draw->polygon( $pixel_points );
    $mask->drawImage( $draw );

    // Прилагаме маската като alpha канал на дизайн слоя - остава видимо
    // само това, което е вътре в полигона.
    $design_layer->compositeImage( $mask, Imagick::COMPOSITE_COPYOPACITY, 0, 0 );

    // Слагаме готовия (изрязан по формата) дизайн слой върху шаблона
    $template->compositeImage( $design_layer, Imagick::COMPOSITE_OVER, 0, 0 );

    $design_layer->clear();
    $mask->clear();
}

/**
 * Генериране на мокъп чрез GD (fallback, ако Imagick липсва).
 *
 * @param string $design_path
 * @param string $template_path
 * @param string $output_path
 * @param array  $zone          Зона (rect или polygon) от decaldesk_get_template_zone().
 * @param array  $output_config format/extension/quality (от decaldesk_get_mockup_output_config).
 */
function decaldesk_generate_mockup_gd( $design_path, $template_path, $output_path, $zone = array(), $output_config = array() ) {
    if ( ! function_exists( 'imagecreatefrompng' ) ) {
        return new WP_Error( 'decaldesk_mockup_error', __( 'Neither Imagick nor the GD library is available on the server.', 'decaldesk' ) );
    }

    // Шаблонът вече може да е PNG/JPG/WEBP (качен през UI), а дизайнът също
    // може да е кой да е поддържан формат - зареждаме и двата по съдържание.
    $template_img = decaldesk_gd_load_image( $template_path );
    $design_img   = decaldesk_gd_load_image( $design_path );

    if ( ! $template_img || ! $design_img ) {
        return new WP_Error( 'decaldesk_mockup_error', __( 'Failed to load the images.', 'decaldesk' ) );
    }

    $tpl_width  = imagesx( $template_img );
    $tpl_height = imagesy( $template_img );

    $zone_type = isset( $zone['type'] ) && 'polygon' === $zone['type'] ? 'polygon' : 'rect';

    imagesavealpha( $template_img, true );

    if ( 'polygon' === $zone_type && ! empty( $zone['points'] ) && count( $zone['points'] ) >= 3 ) {
        decaldesk_composite_polygon_gd( $template_img, $design_img, $zone['points'], $tpl_width, $tpl_height );
    } else {
        $zone = wp_parse_args( $zone, array( 'x' => 15, 'y' => 15, 'width' => 70, 'height' => 70 ) );

        $box_x = (int) round( $tpl_width * ( $zone['x'] / 100 ) );
        $box_y = (int) round( $tpl_height * ( $zone['y'] / 100 ) );
        $box_w = (int) round( $tpl_width * ( $zone['width'] / 100 ) );
        $box_h = (int) round( $tpl_height * ( $zone['height'] / 100 ) );

        $design_width  = imagesx( $design_img );
        $design_height = imagesy( $design_img );

        // "Contain fit" - дизайнът се смалява да се побере ЦЯЛ в зоната, центриран в нея
        $fit = decaldesk_calculate_contain_fit( $design_width, $design_height, $box_w, $box_h );

        $resized_design = imagecreatetruecolor( $fit['width'], $fit['height'] );
        imagesavealpha( $resized_design, true );
        $transparent = imagecolorallocatealpha( $resized_design, 0, 0, 0, 127 );
        imagefill( $resized_design, 0, 0, $transparent );

        imagecopyresampled(
            $resized_design, $design_img,
            0, 0, 0, 0,
            $fit['width'], $fit['height'],
            $design_width, $design_height
        );

        $x = $box_x + $fit['offset_x'];
        $y = $box_y + $fit['offset_y'];

        imagecopy( $template_img, $resized_design, $x, $y, 0, 0, $fit['width'], $fit['height'] );
        imagedestroy( $resized_design );
    }

    $output_config = wp_parse_args( $output_config, array( 'format' => 'webp', 'quality' => 82 ) );

    switch ( $output_config['format'] ) {
        case 'jpeg':
            // JPEG няма прозрачност - "изравняваме" върху бял фон, за да няма
            // черни артефакти, ако шаблонът има alpha канал.
            $flattened = imagecreatetruecolor( $tpl_width, $tpl_height );
            $white     = imagecolorallocate( $flattened, 255, 255, 255 );
            imagefill( $flattened, 0, 0, $white );
            imagecopy( $flattened, $template_img, 0, 0, 0, 0, $tpl_width, $tpl_height );
            imagejpeg( $flattened, $output_path, $output_config['quality'] );
            imagedestroy( $flattened );
            break;

        case 'webp':
            if ( function_exists( 'imagewebp' ) ) {
                imagewebp( $template_img, $output_path, $output_config['quality'] );
            } else {
                // Много стари PHP/GD билдове може да нямат WebP поддръжка - fallback на PNG
                decaldesk_log( 'GD няма WebP поддръжка на този сървър - записва се PNG вместо това.' );
                imagepng( $template_img, preg_replace( '/\.webp$/', '.png', $output_path ) );
            }
            break;

        default:
            imagepng( $template_img, $output_path );
            break;
    }

    imagedestroy( $template_img );
    imagedestroy( $design_img );

    return true;
}

/**
 * Наслагва дизайна в произволна полигонална форма (GD) - "cover fit"
 * (запълва цялата форма) + ray-casting точков тест за изрязване по контура.
 * По-бавно от Imagick варианта (пиксел по пиксел в bounding box-а), но
 * работи навсякъде, дори без Imagick разширение.
 *
 * @param resource|GdImage $template_img Шаблонът (модифицира се директно).
 * @param resource|GdImage $design_img   Дизайнът.
 * @param array            $points       Точки на полигона в проценти.
 * @param int              $tpl_width
 * @param int              $tpl_height
 */
function decaldesk_composite_polygon_gd( $template_img, $design_img, $points, $tpl_width, $tpl_height ) {
    $pixel_points = array();
    $min_x = $tpl_width;
    $max_x = 0;
    $min_y = $tpl_height;
    $max_y = 0;

    foreach ( $points as $point ) {
        $px = $point['x'] / 100 * $tpl_width;
        $py = $point['y'] / 100 * $tpl_height;
        $pixel_points[] = array( 'x' => $px, 'y' => $py );

        $min_x = min( $min_x, $px );
        $max_x = max( $max_x, $px );
        $min_y = min( $min_y, $py );
        $max_y = max( $max_y, $py );
    }

    $box_x = max( 0, (int) round( $min_x ) );
    $box_y = max( 0, (int) round( $min_y ) );
    $box_w = max( 1, (int) round( $max_x - $min_x ) );
    $box_h = max( 1, (int) round( $max_y - $min_y ) );

    $design_width  = imagesx( $design_img );
    $design_height = imagesy( $design_img );

    // "Cover fit" - дизайнът ЗАПЪЛВА цялата кутия (реалистична "фира")
    $fit = decaldesk_calculate_cover_fit( $design_width, $design_height, $box_w, $box_h );

    $resized_design = imagecreatetruecolor( $fit['width'], $fit['height'] );
    imagesavealpha( $resized_design, true );
    $transparent = imagecolorallocatealpha( $resized_design, 0, 0, 0, 127 );
    imagefill( $resized_design, 0, 0, $transparent );

    imagecopyresampled(
        $resized_design, $design_img,
        0, 0, 0, 0,
        $fit['width'], $fit['height'],
        $design_width, $design_height
    );

    $paste_x = $box_x + $fit['offset_x'];
    $paste_y = $box_y + $fit['offset_y'];

    imagealphablending( $template_img, false );

    // Точков тест за всеки пиксел в bounding box-а - копираме само пикселите,
    // които реално попадат вътре в полигона (ray-casting алгоритъм).
    for ( $py = $box_y; $py < $box_y + $box_h && $py < $tpl_height; $py++ ) {
        for ( $px = $box_x; $px < $box_x + $box_w && $px < $tpl_width; $px++ ) {
            if ( ! decaldesk_point_in_polygon( $px + 0.5, $py + 0.5, $pixel_points ) ) {
                continue;
            }

            $src_x = $px - $paste_x;
            $src_y = $py - $paste_y;

            if ( $src_x < 0 || $src_y < 0 || $src_x >= $fit['width'] || $src_y >= $fit['height'] ) {
                continue;
            }

            $color = imagecolorat( $resized_design, $src_x, $src_y );
            $alpha = ( $color >> 24 ) & 0x7F;

            if ( $alpha >= 127 ) {
                continue; // напълно прозрачен пиксел на дизайна - оставяме шаблона както си е
            }

            imagesetpixel( $template_img, $px, $py, $color );
        }
    }

    imagealphablending( $template_img, true );
    imagedestroy( $resized_design );
}

/**
 * Ray-casting тест дали точка (x,y) е вътре в полигон от точки.
 * Стандартен алгоритъм - брои пресичания на хоризонтален лъч с ръбовете
 * на полигона; нечетен брой пресичания = точката е вътре.
 *
 * @param float $x
 * @param float $y
 * @param array $points Списък от {x,y} точки (в същите единици като x/y).
 * @return bool
 */
function decaldesk_point_in_polygon( $x, $y, $points ) {
    $inside = false;
    $count  = count( $points );
    $j      = $count - 1;

    for ( $i = 0; $i < $count; $i++ ) {
        $xi = $points[ $i ]['x'];
        $yi = $points[ $i ]['y'];
        $xj = $points[ $j ]['x'];
        $yj = $points[ $j ]['y'];

        if ( ( $yi > $y ) !== ( $yj > $y ) &&
             ( $x < ( $xj - $xi ) * ( $y - $yi ) / ( $yj - $yi ) + $xi ) ) {
            $inside = ! $inside;
        }

        $j = $i;
    }

    return $inside;
}

/**
 * Вгражда текстови метаданни (заглавие, описание, автор) в изображение,
 * за да носи SEO/ALT информация дори при директно отваряне на снимката
 * (Google Images, споделяне, десктоп файлов мениджър и т.н.).
 *
 * Работи с PNG/JPEG/WebP (Imagick сам разпознава формата на файла).
 * PNG има най-надеждна поддръжка (tEXt chunks); при JPEG/WebP част от
 * инструментите може да не показват метаданните еднакво добре - това е
 * best-effort слой, не единствената защита (ALT текст и SEO мета описание
 * се задават и отделно чрез стандартните WordPress/Yoast/RankMath полета).
 *
 * Работи само с Imagick. Ако Imagick липсва, тихо се прескача - продуктът
 * пак ще има ALT текст и мета описание в WordPress.
 *
 * @param string $image_path  Пълен път до PNG файла (обновява се на място).
 * @param string $title       Заглавие (напр. име на продукта).
 * @param string $description Описание, което да се вгради (напр. meta_description).
 * @return bool true при успех, false ако не е могло да се вгради.
 */
function decaldesk_embed_image_metadata( $image_path, $title, $description ) {
    if ( ! class_exists( 'Imagick' ) || ! file_exists( $image_path ) ) {
        return false;
    }

    try {
        $image = new Imagick( $image_path );

        // Стандартни PNG tEXt ключови думи, разпознавани от повечето инструменти
        $image->setImageProperty( 'Title', wp_strip_all_tags( $title ) );
        $image->setImageProperty( 'Description', wp_strip_all_tags( $description ) );
        $image->setImageProperty( 'Comment', wp_strip_all_tags( $description ) );
        $image->setImageProperty( 'Author', get_bloginfo( 'name' ) );
        $image->setImageProperty( 'Software', 'DecalDesk' );

        $image->writeImage( $image_path );
        $image->clear();
        $image->destroy();

        return true;
    } catch ( Exception $e ) {
        decaldesk_log( 'Неуспешно вграждане на метаданни в изображение: ' . $e->getMessage() );
        return false;
    }
}
