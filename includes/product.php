<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Прикачва списък от мокъп изображения + оригиналния дизайн към продукт.
 * Първият мокъп става главна снимка (featured image), останалите мокъпи и
 * оригиналният дизайн отиват в галерията. Преизползва се от Simple и
 * Variable Product създаването, за да не се дублира логиката.
 *
 * @param int    $product_id
 * @param array  $mockup_paths Списък с пътища до генерираните мокъпи (поне 1).
 * @param string $design_path  Път до оригиналния дизайн (по желание).
 * @param array  $parsed       Резултат от decaldesk_parse_filename().
 * @param array  $ai_content   Резултат от decaldesk_generate_ai_content().
 * @param string $description  Финалното описание (с включен footer текст), за caption/content на featured снимката.
 */
function decaldesk_attach_mockups_and_design( $product_id, $mockup_paths, $design_path, $parsed, $ai_content, $description ) {
	$mockup_paths = array_values( (array) $mockup_paths );
	$alt_text     = ! empty( $ai_content['meta_description'] ) ? $ai_content['meta_description'] : $parsed['name'];
	$multiple     = count( $mockup_paths ) > 1;

	foreach ( $mockup_paths as $index => $mockup_path ) {
		if ( ! file_exists( $mockup_path ) ) {
			continue;
		}

		$label = $multiple
			? sprintf(
				/* translators: 1: product name, 2: mockup number */
				__( '%1$s – mockup %2$d', 'decaldesk' ),
				$parsed['name'],
				$index + 1
			)
			: $parsed['name'] . ' – ' . __( 'mockup', 'decaldesk' );

		$attach_id = decaldesk_attach_product_image(
			$product_id,
			$mockup_path,
			$label,
			$alt_text,
			0 === $index ? ( $ai_content['short_description'] ?? '' ) : '',
			0 === $index ? $description : ''
		);

		if ( ! $attach_id ) {
			continue;
		}

		if ( 0 === $index ) {
			set_post_thumbnail( $product_id, $attach_id );
		} else {
			decaldesk_add_to_product_gallery( $product_id, $attach_id );
		}
	}

	if ( $design_path && file_exists( $design_path ) && ! in_array( $design_path, $mockup_paths, true ) ) {
		$design_attach_id = decaldesk_attach_product_image(
			$product_id,
			$design_path,
			$parsed['name'] . ' – ' . __( 'original design', 'decaldesk' ),
			$parsed['name'] . ' - ' . __( 'original design file', 'decaldesk' ),
			'',
			''
		);

		if ( $design_attach_id ) {
			decaldesk_add_to_product_gallery( $product_id, $design_attach_id );
		}
	}
}

/**
 * Дали е конфигуриран поне един размер за варианти (задължително условие,
 * за да може изобщо да се създаде Variable Product).
 */
function decaldesk_variants_configured() {
	/*! <fs_premium_only> */
	// Variable Products (размерни варианти) е Pro функция.
	if ( ! decaldesk_fs()->can_use_premium_code() ) {
		return false;
	}

	$settings = get_option( 'decaldesk_settings', array() );
	return ! empty( $settings['variant_sizes'] );
	/*! </fs_premium_only> */
	return false;
}

/*! <fs_premium_only> */
/**
 * Изчислява реалната ширина/височина (в см) на един размерен вариант,
 * запазвайки пропорциите на КОНКРЕТНИЯ качен дизайн. Потребителят конфигурира
 * само целева ширина в Настройки - височината никога не се въвежда ръчно,
 * за да не се разминава с реалните пропорции на файла (напр. потребителски
 * въведено "50x70" върху дизайн с пропорции 60x40 би дало подвеждащ вариант:
 * етикетът и цената да не съответстват визуално на споделения мокъп).
 *
 * @param int $target_width Целева ширина в см (от конфигурацията).
 * @param int $base_width   Реална ширина на качения дизайн (от името на файла).
 * @param int $base_height  Реална височина на качения дизайн (от името на файла).
 * @return array{0:int,1:int} [ width, height ] в см, височина закръглена до цяло, минимум 1.
 */
function decaldesk_compute_variant_dimensions( $target_width, $base_width, $base_height ) {
	$target_width = max( 1, (int) $target_width );

	if ( $base_width <= 0 || $base_height <= 0 ) {
		return array( $target_width, $target_width );
	}

	$height = (int) round( $target_width * ( $base_height / $base_width ) );

	return array( $target_width, max( 1, $height ) );
}

/**
 * Създава WooCommerce Variable Product с избираем размер (задължително) и
 * незадължителни материал/цвят - вместо отделен Simple Product на файл.
 * Всяка вариация получава собствена автоматично изчислена цена по формулата
 * €/м² според конкретния размер на вариацията. Височината на всеки размерен
 * вариант се изчислява пропорционално спрямо реалните пропорции на ТОЗИ
 * качен дизайн (виж decaldesk_compute_variant_dimensions()) - потребителят
 * конфигурира само списък от целеви ширини, не пълни WxH двойки.
 *
 * @param array    $parsed      Резултат от decaldesk_parse_filename() - размерите му
 *                               (width/height) определят пропорциите за всички варианти.
 * @param string[] $mockup_paths Списък с пътища до генерираните мокъп файлове (поне 1).
 * @param string   $status      'draft' или 'publish'.
 * @param array    $ai_content  Резултат от decaldesk_generate_ai_content().
 * @param string   $design_path Път до оригиналния дизайн (за галерията).
 * @return int|WP_Error ID на родителския продукт, или WP_Error при грешка.
 */
function decaldesk_create_variable_product( $parsed, $mockup_paths, $status = 'draft', $ai_content = array(), $design_path = '' ) {
	if ( ! class_exists( 'WC_Product_Variable' ) ) {
		return new WP_Error( 'decaldesk_product_error', __( 'WooCommerce is not active.', 'decaldesk' ) );
	}

	$settings       = get_option( 'decaldesk_settings', array() );
	$target_widths  = isset( $settings['variant_sizes'] ) ? $settings['variant_sizes'] : array();
	$materials      = isset( $settings['variant_materials'] ) ? $settings['variant_materials'] : array();
	$colors         = isset( $settings['variant_colors'] ) ? $settings['variant_colors'] : array();

	if ( empty( $target_widths ) ) {
		return new WP_Error( 'decaldesk_product_error', __( 'No variant sizes configured in Settings.', 'decaldesk' ) );
	}

	// Всяка целева ширина -> [width, height] пропорционално на ТОЗИ дизайн,
	// после форматирана като "WxH" низ (за атрибут/SKU) - веднъж тук, за да
	// не се преизчислява отделно за всяка материал×цвят комбинация по-долу.
	$sizes = array();
	foreach ( $target_widths as $target_width ) {
		list( $w, $h ) = decaldesk_compute_variant_dimensions( (int) $target_width, $parsed['width'], $parsed['height'] );
		$sizes[]       = $w . 'x' . $h;
	}
	$sizes = array_values( array_unique( $sizes ) );

	$status = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';

	if ( empty( $ai_content ) ) {
		$ai_content = decaldesk_build_fallback_content( $parsed );
	}

	$description = decaldesk_append_custom_footer_text( $ai_content['description'] );

	$product = new WC_Product_Variable();
	$product->set_name( $parsed['name'] );
	$product->set_slug( decaldesk_generate_slug( $parsed['name'], $parsed ) );
	$product->set_status( $status );
	$product->set_catalog_visibility( 'visible' );
	$product->set_description( $description );
	$product->set_short_description( $ai_content['short_description'] );

	$term_id = decaldesk_get_or_create_category( $parsed['category'] );
	if ( $term_id ) {
		$product->set_category_ids( array( $term_id ) );
	}

	$product->update_meta_data( '_decaldesk_material', $parsed['material'] );

	// Локални (не-таксономийни) атрибути - по-просто от глобални taxonomy
	// атрибути и напълно достатъчно за случая, тъй като размерите/материалите/
	// цветовете се конфигурират централно в Настройки, не per-продукт.
	$size_label     = __( 'Size', 'decaldesk' );
	$material_label = __( 'Material', 'decaldesk' );
	$color_label    = __( 'Color', 'decaldesk' );

	$has_material = ! empty( $materials );
	$has_color    = ! empty( $colors );

	$attributes = array();
	$position   = 0;

	$size_attr = new WC_Product_Attribute();
	$size_attr->set_id( 0 );
	$size_attr->set_name( $size_label );
	$size_attr->set_options(
		array_map(
			function ( $s ) {
				return $s . ' cm';
			},
			$sizes
		)
	);
	$size_attr->set_position( $position++ );
	$size_attr->set_visible( true );
	$size_attr->set_variation( true );
	$attributes[] = $size_attr;

	if ( $has_material ) {
		$material_attr = new WC_Product_Attribute();
		$material_attr->set_id( 0 );
		$material_attr->set_name( $material_label );
		$material_attr->set_options( $materials );
		$material_attr->set_position( $position++ );
		$material_attr->set_visible( true );
		$material_attr->set_variation( true );
		$attributes[] = $material_attr;
	}

	if ( $has_color ) {
		$color_attr = new WC_Product_Attribute();
		$color_attr->set_id( 0 );
		$color_attr->set_name( $color_label );
		$color_attr->set_options( $colors );
		$color_attr->set_position( $position++ );
		$color_attr->set_visible( true );
		$color_attr->set_variation( true );
		$attributes[] = $color_attr;
	}

	$product->set_attributes( $attributes );

	$product_id = $product->save();

	if ( ! $product_id ) {
		return new WP_Error( 'decaldesk_product_error', __( 'Failed to create the parent product.', 'decaldesk' ) );
	}

	if ( ! empty( $ai_content['tags'] ) && is_array( $ai_content['tags'] ) ) {
		wp_set_object_terms( $product_id, $ai_content['tags'], 'product_tag', false );
	}

	if ( ! empty( $ai_content['meta_description'] ) ) {
		update_post_meta( $product_id, '_yoast_wpseo_metadesc', $ai_content['meta_description'] );
		update_post_meta( $product_id, 'rank_math_description', $ai_content['meta_description'] );
	}
	if ( ! empty( $ai_content['seo_title'] ) ) {
		update_post_meta( $product_id, '_yoast_wpseo_title', $ai_content['seo_title'] );
		update_post_meta( $product_id, 'rank_math_title', $ai_content['seo_title'] );
	}
	if ( ! empty( $ai_content['focus_keyphrase'] ) ) {
		update_post_meta( $product_id, '_yoast_wpseo_focuskw', $ai_content['focus_keyphrase'] );
		update_post_meta( $product_id, 'rank_math_focus_keyword', $ai_content['focus_keyphrase'] );
	}

	decaldesk_attach_mockups_and_design( $product_id, $mockup_paths, $design_path, $parsed, $ai_content, $description );

	// Генерираме всички комбинации размер × материал × цвят (cartesian product)
	$material_options = $has_material ? $materials : array( null );
	$color_options    = $has_color ? $colors : array( null );

	$base_slug    = decaldesk_generate_slug( $parsed['name'], $parsed );
	$size_key     = sanitize_title( $size_label );
	$material_key = sanitize_title( $material_label );
	$color_key    = sanitize_title( $color_label );

	foreach ( $sizes as $size_str ) {
		$dimensions      = array_map( 'intval', explode( 'x', $size_str ) );
		$w               = isset( $dimensions[0] ) ? $dimensions[0] : 0;
		$h               = isset( $dimensions[1] ) ? $dimensions[1] : 0;
		$variation_price = decaldesk_calculate_price( $w, $h );

		foreach ( $material_options as $material_value ) {
			foreach ( $color_options as $color_value ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $product_id );

				$variation_attributes = array( $size_key => $size_str . ' cm' );
				$sku_parts            = array( $base_slug, $size_str );

				if ( $has_material && null !== $material_value ) {
					$variation_attributes[ $material_key ] = $material_value;
					$sku_parts[]                           = sanitize_title( decaldesk_transliterate_bg( $material_value ) );
				}
				if ( $has_color && null !== $color_value ) {
					$variation_attributes[ $color_key ] = $color_value;
					$sku_parts[]                        = sanitize_title( decaldesk_transliterate_bg( $color_value ) );
				}

				$variation->set_attributes( $variation_attributes );
				$variation->set_regular_price( (string) $variation_price );
				$variation->set_sku( implode( '-', $sku_parts ) );
				$variation->set_status( 'publish' ); // видимостта на вариацията се управлява от родителския продукт
				$variation->save();
			}
		}
	}

	// WooCommerce трябва да синхронизира диапазона на цените и наличността
	// на родителския продукт след добавяне на вариациите.
	WC_Product_Variable::sync( $product_id );

	return $product_id;
}
/*! </fs_premium_only> */

/**
 * Създава WooCommerce продукт от парснатите данни.
 *
 * @param array    $parsed       Резултат от decaldesk_parse_filename().
 * @param float    $price        Изчислена цена от decaldesk_calculate_price().
 * @param string[] $mockup_paths Списък с пътища до генерираните (и вече мета-обогатени) мокъп файлове (поне 1).
 * @param string   $status       'draft' или 'publish'.
 * @param array    $ai_content   Резултат от decaldesk_generate_ai_content(): description, short_description, meta_description.
 * @param string   $design_path  Път до ОРИГИНАЛНИЯ качен дизайн (не мокъпа) - добавя се като допълнително
 *                                изображение в галерията на продукта, за да се вижда и чистия файл. По желание.
 * @return int|WP_Error ID на продукта, или WP_Error при грешка.
 */
function decaldesk_create_product( $parsed, $price, $mockup_paths, $status = 'draft', $ai_content = array(), $design_path = '' ) {
	if ( ! class_exists( 'WC_Product_Simple' ) ) {
		return new WP_Error( 'decaldesk_product_error', __( 'WooCommerce is not active.', 'decaldesk' ) );
	}

	$status = in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';

	if ( empty( $ai_content ) ) {
		$ai_content = decaldesk_build_fallback_content( $parsed );
	}

	$description = decaldesk_append_custom_footer_text( $ai_content['description'] );

	$product = new WC_Product_Simple();

	// Заглавието остава точно както е зададено в името на файла (кирилица или каквото е подадено)
	$product->set_name( $parsed['name'] );

	// Кратък адрес (slug) винаги на латиница, независимо от езика на заглавието
	$product->set_slug( decaldesk_generate_slug( $parsed['name'], $parsed ) );

	// Продуктов код (SKU) - генериран по правило (не от AI), за да е предвидим,
	// уникален и наличен дори когато AI е изключен/недостъпен.
	$product->set_sku( decaldesk_generate_product_sku( $parsed ) );

	$product->set_status( $status );
	$product->set_catalog_visibility( 'visible' );
	$product->set_regular_price( (string) $price );
	$product->set_description( $description );
	$product->set_short_description( $ai_content['short_description'] );

	// Категория
	$term_id = decaldesk_get_or_create_category( $parsed['category'] );
	if ( $term_id ) {
		$product->set_category_ids( array( $term_id ) );
	}

	// Мета данни за размер/материал (полезно за филтри и допълнителна логика)
	$product->update_meta_data( '_decaldesk_width', $parsed['width'] );
	$product->update_meta_data( '_decaldesk_height', $parsed['height'] );
	$product->update_meta_data( '_decaldesk_material', $parsed['material'] );

	$product_id = $product->save();

	if ( ! $product_id ) {
		return new WP_Error( 'decaldesk_product_error', __( 'Failed to create the product.', 'decaldesk' ) );
	}

	// Тагове (product_tag таксономия) - от AI или fallback списъка.
	// wp_set_object_terms сам създава термините, ако още не съществуват.
	if ( ! empty( $ai_content['tags'] ) && is_array( $ai_content['tags'] ) ) {
		wp_set_object_terms( $product_id, $ai_content['tags'], 'product_tag', false );
	}

	// SEO мета - записваме за Yoast SEO и RankMath (каквото от двете е активно го използва)
	if ( ! empty( $ai_content['meta_description'] ) ) {
		update_post_meta( $product_id, '_yoast_wpseo_metadesc', $ai_content['meta_description'] );
		update_post_meta( $product_id, 'rank_math_description', $ai_content['meta_description'] );
	}
	if ( ! empty( $ai_content['seo_title'] ) ) {
		update_post_meta( $product_id, '_yoast_wpseo_title', $ai_content['seo_title'] );
		update_post_meta( $product_id, 'rank_math_title', $ai_content['seo_title'] );
	}
	if ( ! empty( $ai_content['focus_keyphrase'] ) ) {
		update_post_meta( $product_id, '_yoast_wpseo_focuskw', $ai_content['focus_keyphrase'] );
		update_post_meta( $product_id, 'rank_math_focus_keyword', $ai_content['focus_keyphrase'] );
	}

	// Прикачваме мокъпа(ите) - първият става featured image, останалите (+
	// оригиналният дизайн) отиват в галерията.
	decaldesk_attach_mockups_and_design( $product_id, $mockup_paths, $design_path, $parsed, $ai_content, $description );

	return $product_id;
}

/**
 * Добавя configurirания в настройките текст ("За различен размер, моля..."
 * и т.н.) най-отдолу в описанието, ако е зададен. Работи еднакво независимо
 * дали описанието е от AI или от статичен fallback шаблон.
 *
 * @param string $description Основното описание (HTML).
 * @return string Описанието с добавен footer текст (ако има такъв).
 */
function decaldesk_append_custom_footer_text( $description ) {
	$settings    = get_option( 'decaldesk_settings', array() );
	$footer_text = isset( $settings['custom_footer_text'] ) ? trim( $settings['custom_footer_text'] ) : '';

	if ( '' === $footer_text ) {
		return $description;
	}

	// Ако вече не е в HTML тагове (обикновен текст от textarea), обвиваме в <p>
	if ( false === strpos( $footer_text, '<' ) ) {
		$footer_text = wpautop( esc_html( $footer_text ) );
	} else {
		$footer_text = wp_kses_post( $footer_text );
	}

	return $description . "\n" . $footer_text;
}

/**
 * Намира или създава WooCommerce категория по slug.
 *
 * @param string $category_slug
 * @return int|null ID на термина, или null при грешка.
 */
function decaldesk_get_or_create_category( $category_slug ) {
	$settings   = get_option( 'decaldesk_settings', array() );
	$categories = isset( $settings['categories'] ) ? $settings['categories'] : array();

	$category_name = isset( $categories[ $category_slug ] ) ? $categories[ $category_slug ] : ucfirst( $category_slug );

	$term = get_term_by( 'slug', $category_slug, 'product_cat' );

	if ( $term ) {
		return (int) $term->term_id;
	}

	$inserted = wp_insert_term( $category_name, 'product_cat', array( 'slug' => $category_slug ) );

	if ( is_wp_error( $inserted ) ) {
		return null;
	}

	return (int) $inserted['term_id'];
}

/**
 * Прикачва изображение (мокъп или оригинален дизайн) като media attachment,
 * свързан с продукта. Задава ALT текст / caption / описание за SEO. НЕ слага
 * featured image или галерия сам по себе си - това го прави извикващият код,
 * за да може една и съща функция да се ползва и за двата случая.
 *
 * @param int    $product_id
 * @param string $file_path     Път до файла (мокъп или оригинален дизайн).
 * @param string $title         Заглавие на attachment-а (показва се в Медия библиотеката).
 * @param string $alt_text      ALT текст на изображението (SEO).
 * @param string $caption_text  Caption (post_excerpt).
 * @param string $description   Описание на attachment-а (post_content).
 * @return int|false ID на новия attachment, или false при грешка.
 */
function decaldesk_attach_product_image( $product_id, $file_path, $title, $alt_text, $caption_text = '', $description = '' ) {
	// Само image.php - единствената функция от този клъстер, която реално
	// ползваме тук, е wp_generate_attachment_metadata() (дефинирана там).
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$upload_dir = wp_upload_dir();
	$filename   = wp_unique_filename( $upload_dir['path'], basename( $file_path ) );

	// Копираме файла в стандартната WP upload директория за текущия месец
	$target_path = $upload_dir['path'] . '/' . $filename;
	copy( $file_path, $target_path );

	$filetype = wp_check_filetype( $filename, null );

	$attachment = array(
		'post_mime_type' => $filetype['type'],
		'post_title'     => sanitize_text_field( $title ),
		'post_excerpt'   => sanitize_text_field( $caption_text ),
		'post_content'   => wp_kses_post( $description ),
		'post_status'    => 'inherit',
	);

	$attach_id = wp_insert_attachment( $attachment, $target_path, $product_id );

	if ( is_wp_error( $attach_id ) ) {
		return false;
	}

	$attach_data = wp_generate_attachment_metadata( $attach_id, $target_path );
	wp_update_attachment_metadata( $attach_id, $attach_data );

	// ALT текст - най-важното SEO поле за изображения в WordPress
	update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

	return $attach_id;
}

/**
 * Добавя attachment ID към галерията на продукта (_product_image_gallery),
 * без да презаписва вече съществуващи снимки в галерията.
 *
 * @param int $product_id
 * @param int $attach_id
 */
function decaldesk_add_to_product_gallery( $product_id, $attach_id ) {
	$existing_ids = get_post_meta( $product_id, '_product_image_gallery', true );
	$ids          = $existing_ids ? explode( ',', $existing_ids ) : array();

	$ids[] = (string) $attach_id;
	$ids   = array_unique( array_filter( $ids ) );

	update_post_meta( $product_id, '_product_image_gallery', implode( ',', $ids ) );
}
