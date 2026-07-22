<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ==========================================================
 * AI генериране на описания (Gemini безплатен / Claude платен)
 * ==========================================================
 */

/**
 * Главна функция: генерира дълго описание, кратко описание и мета описание.
 * Избира доставчик според настройките ('free_gemini' или 'claude'), пази дневен
 * лимит за безплатния вариант, и винаги пада на fallback шаблон при проблем.
 *
 * @param array $parsed Резултат от decaldesk_parse_filename().
 * @return array {
 *     @type string $description       Дълго описание (HTML абзаци).
 *     @type string $short_description Кратко описание (1-2 изречения).
 *     @type string $meta_description  SEO мета описание (до ~155 знака).
 *     @type string $source            'ai_free' | 'ai_claude' | 'fallback' — за информация в резултата на качването.
 * }
 */
/**
 * Главна функция: генерира дълго описание, кратко описание и мета описание.
 * Избира доставчик според настройките ('free_gemini' или 'claude'), пази дневен
 * лимит за безплатния вариант, и винаги пада на fallback шаблон при проблем.
 *
 * @param array  $parsed     Резултат от decaldesk_parse_filename().
 * @param string $design_path Пълен път до качения PNG дизайн (за AI Vision анализ). По желание.
 * @return array {
 *     @type string $description       Дълго описание (HTML абзаци).
 *     @type string $short_description Кратко описание (1-2 изречения).
 *     @type string $meta_description  SEO мета описание (до ~155 знака).
 *     @type string $source            'ai_free' | 'ai_claude' | 'fallback' — за информация в резултата на качването.
 * }
 */
function decaldesk_generate_ai_content( $parsed, $design_path = '' ) {
	/*! <fs_premium_only> */
	$settings = wp_parse_args(
		get_option( 'decaldesk_settings', array() ),
		array(
			'ai_provider'        => 'none', // none | free_gemini | claude
			'ai_api_key'         => '',
			'ai_model'           => 'claude-sonnet-4-6',
			'gemini_api_key'     => '',
			'gemini_daily_limit' => 10,
			'ai_use_vision'      => false,
		)
	);

	$provider = ! empty( $settings['ai_provider'] ) ? $settings['ai_provider'] : 'none';

	// AI описанията са Pro функция - без валиден лиценз винаги падаме на
	// статичния шаблон, независимо какво е избрано в настройките.
	if ( 'none' !== $provider && ! decaldesk_fs()->can_use_premium_code() ) {
		$provider = 'none';
	}

	// Подготвяме изображението само ако vision е включен, доставчикът поддържа снимки,
	// и файлът реално съществува. При грешка просто продължаваме без изображение.
	$image_payload = '';
	if ( ! empty( $settings['ai_use_vision'] ) && $design_path && file_exists( $design_path ) ) {
		$image_payload = decaldesk_prepare_image_for_ai( $design_path );
	}

	if ( 'free_gemini' === $provider ) {
		$gemini_key = decaldesk_get_gemini_api_key();

		if ( empty( $gemini_key ) ) {
			return decaldesk_build_fallback_content( $parsed );
		}

		$daily_limit = max( 1, (int) $settings['gemini_daily_limit'] );

		if ( ! decaldesk_daily_quota_available( $daily_limit ) ) {
			decaldesk_log( 'Дневният безплатен AI лимит (' . $daily_limit . ') е достигнат — пада на шаблон.' );
			$fallback           = decaldesk_build_fallback_content( $parsed );
			$fallback['source'] = 'fallback';
			return $fallback;
		}

		$result = decaldesk_call_gemini_api( $parsed, $gemini_key, $image_payload );

		if ( false !== $result ) {
			decaldesk_increment_daily_quota();
			$result['source'] = 'ai_free';
			return $result;
		}

		// Gemini заявката се провали (мрежа/грешка/парсване) - пада на шаблон, без да хаби квота
		$fallback           = decaldesk_build_fallback_content( $parsed );
		$fallback['source'] = 'fallback';
		return $fallback;
	}

	if ( 'claude' === $provider ) {
		$api_key = decaldesk_get_ai_api_key();

		if ( empty( $api_key ) ) {
			return decaldesk_build_fallback_content( $parsed );
		}

		$result = decaldesk_call_claude_api( $parsed, $api_key, $settings['ai_model'], $image_payload );

		if ( false !== $result ) {
			$result['source'] = 'ai_claude';
			return $result;
		}

		$fallback           = decaldesk_build_fallback_content( $parsed );
		$fallback['source'] = 'fallback';
		return $fallback;
	}
	/*
	! </fs_premium_only> */
	// Този build генерира само статичното шаблонно съдържание. AI-генерирани
	// описания (Google Gemini / Anthropic Claude) са налични в DecalDesk Pro.
	$fallback           = decaldesk_build_fallback_content( $parsed );
	$fallback['source'] = 'fallback';
	return $fallback;
}

/*! <fs_premium_only> */
/**
 * Смалява дизайна и го връща като base64 PNG, готов за пращане към AI Vision.
 * Оригиналните файлове за печат често са огромни (много MB) - смаляваме до
 * максимум 1024px по дългата страна, за да пестим трафик, токени и време.
 *
 * @param string $design_path Пълен път до оригиналния PNG файл.
 * @return string Base64-кодирани PNG данни, или '' при грешка.
 */
function decaldesk_prepare_image_for_ai( $design_path ) {
	$max_dimension = 1024;

	try {
		if ( class_exists( 'Imagick' ) ) {
			$image = new Imagick( $design_path );
			$image->setImageFormat( 'png' );

			$width  = $image->getImageWidth();
			$height = $image->getImageHeight();

			if ( $width > $max_dimension || $height > $max_dimension ) {
				$image->resizeImage( $max_dimension, $max_dimension, Imagick::FILTER_LANCZOS, 1, true );
			}

			$blob = $image->getImageBlob();
			$image->clear();
			$image->destroy();

			return base64_encode( $blob );
		}

		// GD fallback - зареждаме според реалния формат на файла (по съдържание, не по разширение)
		$src = decaldesk_gd_load_image( $design_path );
		if ( $src ) {
			$width  = imagesx( $src );
			$height = imagesy( $src );
			$ratio  = min( 1, $max_dimension / max( $width, $height ) );

			$new_width  = (int) round( $width * $ratio );
			$new_height = (int) round( $height * $ratio );

			$resized = imagecreatetruecolor( $new_width, $new_height );
			imagesavealpha( $resized, true );
			$transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
			imagefill( $resized, 0, 0, $transparent );

			imagecopyresampled( $resized, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height );

			ob_start();
			imagepng( $resized );
			$blob = ob_get_clean();

			imagedestroy( $src );
			imagedestroy( $resized );

			return base64_encode( $blob );
		}
	} catch ( Exception $e ) {
		decaldesk_log( 'Неуспешна подготовка на изображение за AI Vision: ' . $e->getMessage() );
	}

	return '';
}
/*! </fs_premium_only> */

/**
 * Зарежда изображение с GD, разпознавайки реалния формат по съдържанието на
 * файла (чрез getimagesize), а не по разширението - работи с PNG/JPG/WEBP/GIF.
 * Използва се само като fallback, когато Imagick липсва (той разпознава автоматично).
 *
 * @param string $path Път до файла.
 * @return GdImage|resource|false Заредено GD изображение, или false при грешка.
 */
function decaldesk_gd_load_image( $path ) {
	$info = @getimagesize( $path );
	if ( ! $info ) {
		return false;
	}

	switch ( $info[2] ) {
		case IMAGETYPE_PNG:
			return function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $path ) : false;
		case IMAGETYPE_JPEG:
			return function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $path ) : false;
		case IMAGETYPE_GIF:
			return function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $path ) : false;
		case IMAGETYPE_WEBP:
			return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false;
		default:
			return false;
	}
}

/*! <fs_premium_only> */
/**
 * Изгражда общия промпт за AI (еднакъв за всички доставчици).
 *
 * Забележка: НЕ искаме JSON тук нарочно. По-бързите/безплатни модели (напр.
 * Gemini Flash) често вкарват истински нови редове или кавички в текста, което
 * чупи строгия JSON синтаксис при парсване. Текстов формат с уникални разделители
 * е много по-устойчив - няма нужда от escaping и не зависи от JSON коректност.
 */
function decaldesk_build_ai_prompt( $parsed, $has_image = false ) {
	$category_name      = decaldesk_get_category_display_name( $parsed['category'] );
	$area_sqm           = decaldesk_calculate_area_sqm( $parsed['width'], $parsed['height'] );
	$language           = decaldesk_get_ai_content_language();
	$store_description  = decaldesk_get_store_description();

	// Магазинът НЕ продава непременно декали/стикери - DecalDesk работи с
	// всеки продукт, чиято цена зависи от площта (картини, платове, плочки
	// и т.н.). Ако собственикът е описал какво продава в Настройки, ползваме
	// точно тази фраза; иначе падаме на оригиналния default (декали/стикери),
	// за да не се променя поведението за съществуващи инсталации без промяна.
	$business_context = '' !== $store_description
		? $store_description
		: 'self-adhesive vinyl decals/stickers';

	$vision_instruction = $has_image
		? "Look carefully at the ATTACHED IMAGE of the design - describe what you actually see (motif, objects, colors, style, mood) and base the product content on that, not just the filename.\n\n"
		: '';

	return sprintf(
		"You are a copywriter for an online store selling %s, priced by size (width x height).\n" .
		'%s' .
		"Write ALL product content in %s for the following product:\n\n" .
		"- Design name (do NOT translate or transliterate it - use it exactly as given): %s\n" .
		"- Dimensions: %d x %d cm (%s m²)\n" .
		"- Material: %s\n" .
		"- Category: %s\n\n" .
		'Reply in EXACTLY the following format, no markdown, no explanation before or after, ' .
		"no code fences (```), just the six blocks with exactly these markers:\n\n" .
		"===DESCRIPTION===\n" .
		'(long product description, 3-4 sentences, sales tone, mentions size/material/use; ' .
		"may contain short HTML <p> paragraphs; do NOT put quote marks \" at the start/end here)\n" .
		"===SHORT===\n" .
		"(short description, 1 sentence, up to 25 words, plain text only, no HTML)\n" .
		"===META===\n" .
		"(SEO meta description, max 150 characters, plain text on a single line, no line breaks)\n" .
		"===SEO_TITLE===\n" .
		'(SEO title for the product page, max 60 characters, plain text on a single line, ' .
		"include the design name and the most important keyword - do NOT just repeat the design name alone)\n" .
		"===FOCUS_KEYPHRASE===\n" .
		'(the single SEO focus keyphrase this product page should rank for, 2-4 words, ' .
		"plain text, lowercase, no punctuation - e.g. \"christmas wall decal\")\n" .
		"===TAGS===\n" .
		'(5-8 relevant tags for the product, comma-separated, in %s, lowercase, ' .
		"no hashtags - e.g. motif/style/use/color - example: holiday, winter motif, kitchen, matte, gift)\n",
		$business_context,
		$vision_instruction,
		$language,
		$parsed['name'],
		$parsed['width'],
		$parsed['height'],
		$area_sqm,
		$parsed['material'],
		$category_name,
		$language
	);
}

/**
 * Извлича description/short_description/meta_description от суров текст с
 * разделители (===DESCRIPTION===, ===SHORT===, ===META===), върнат от AI-то.
 * Устойчиво е на markdown fences, водещ/следващ текст и вътрешни нови редове/
 * кавички в описанието - за разлика от строг JSON parse.
 *
 * Връща false само ако липсва самият маркер "===DESCRIPTION===" (т.е. AI-то изобщо
 * не е спазило формата) - във всички други случаи попълва липсващите полета
 * от fallback шаблона, вместо да проваля цялата генерация заради дреболия.
 */
function decaldesk_parse_ai_json_response( $raw_text, $parsed ) {
	$raw_text = trim( $raw_text );
	// Премахваме евентуални ```...``` markdown fences, ако AI-то ги е добавило въпреки инструкцията
	$raw_text = preg_replace( '/```[a-z]*\s*|```/i', '', $raw_text );

	$fallback = decaldesk_build_fallback_content( $parsed );

	$description       = decaldesk_extract_ai_section( $raw_text, 'DESCRIPTION', 'SHORT' );
	$short_description = decaldesk_extract_ai_section( $raw_text, 'SHORT', 'META' );
	$meta_description  = decaldesk_extract_ai_section( $raw_text, 'META', 'SEO_TITLE' );
	$seo_title         = decaldesk_extract_ai_section( $raw_text, 'SEO_TITLE', 'FOCUS_KEYPHRASE' );
	$focus_keyphrase   = decaldesk_extract_ai_section( $raw_text, 'FOCUS_KEYPHRASE', 'TAGS' );
	$tags_raw          = decaldesk_extract_ai_section( $raw_text, 'TAGS', null );

	if ( false === $description ) {
		return false;
	}

	return array(
		'description'       => wp_kses_post( trim( $description ) ),
		'short_description' => false !== $short_description
			? sanitize_text_field( trim( $short_description ) )
			: $fallback['short_description'],
		'meta_description'  => false !== $meta_description
			? mb_substr( sanitize_text_field( trim( $meta_description ) ), 0, 160 )
			: $fallback['meta_description'],
		'seo_title'         => false !== $seo_title
			? mb_substr( sanitize_text_field( trim( $seo_title ) ), 0, 70 )
			: $fallback['seo_title'],
		'focus_keyphrase'   => false !== $focus_keyphrase
			? mb_substr( sanitize_text_field( trim( wp_strip_all_tags( $focus_keyphrase ) ) ), 0, 80 )
			: $fallback['focus_keyphrase'],
		'tags'              => false !== $tags_raw
			? decaldesk_parse_tags_string( $tags_raw )
			: $fallback['tags'],
	);
}

/**
 * Превръща суров низ с тагове, разделени със запетая, в чист масив от
 * санитизирани стрингове - за WooCommerce product_tag таксономията.
 *
 * @param string $raw_tags Суров низ, напр. "коледа, зимен мотив, кухня"
 * @return string[] Списък с чисти тагове (без празни, без дубликати).
 */
function decaldesk_parse_tags_string( $raw_tags ) {
	$parts = explode( ',', $raw_tags );
	$tags  = array();

	foreach ( $parts as $tag ) {
		$tag = trim( wp_strip_all_tags( $tag ) );
		$tag = trim( $tag, " \t\n\r\0\x0B.\"'" );

		if ( '' !== $tag && mb_strlen( $tag ) <= 50 ) {
			$tags[] = $tag;
		}
	}

	return array_slice( array_unique( $tags ), 0, 10 );
}

/**
 * Извлича съдържанието между два маркера от вида ===ИМЕ=== в суров текст.
 * Ако $end_marker е null, взима всичко до края на текста.
 *
 * @return string|false Съдържанието (може да е празен низ), или false ако маркерът липсва.
 */
function decaldesk_extract_ai_section( $text, $start_marker, $end_marker ) {
	$start_pattern = '/===\s*' . preg_quote( $start_marker, '/' ) . '\s*===/ui';

	if ( ! preg_match( $start_pattern, $text, $start_match, PREG_OFFSET_CAPTURE ) ) {
		return false;
	}

	$content_start = $start_match[0][1] + strlen( $start_match[0][0] );

	if ( null !== $end_marker ) {
		$end_pattern = '/===\s*' . preg_quote( $end_marker, '/' ) . '\s*===/ui';
		if ( preg_match( $end_pattern, $text, $end_match, PREG_OFFSET_CAPTURE, $content_start ) ) {
			$content_end = $end_match[0][1];
			return substr( $text, $content_start, $content_end - $content_start );
		}
	}

	return substr( $text, $content_start );
}

/**
 * Вика Claude API (Anthropic) - платен доставчик.
 *
 * @param array  $parsed
 * @param string $api_key
 * @param string $model
 * @param string $image_base64 Base64-кодирано PNG изображение (от decaldesk_prepare_image_for_ai), или '' ако няма.
 * @return array|false Съдържание или false при грешка.
 */
function decaldesk_call_claude_api( $parsed, $api_key, $model, $image_base64 = '' ) {
	$prompt = decaldesk_build_ai_prompt( $parsed, ! empty( $image_base64 ) );

	$content = array();

	if ( ! empty( $image_base64 ) ) {
		$content[] = array(
			'type'   => 'image',
			'source' => array(
				'type'       => 'base64',
				'media_type' => 'image/png',
				'data'       => $image_base64,
			),
		);
	}

	$content[] = array(
		'type' => 'text',
		'text' => $prompt,
	);

	$response = wp_remote_post(
		'https://api.anthropic.com/v1/messages',
		array(
			'timeout' => 45,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode(
				array(
					'model'      => ! empty( $model ) ? $model : 'claude-sonnet-4-6',
					'max_tokens' => 1200,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => $content,
						),
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		decaldesk_log( 'Claude API заявка неуспешна: ' . $response->get_error_message() );
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( 200 !== $code || empty( $body['content'][0]['text'] ) ) {
		$error_detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		decaldesk_log( 'Claude API върна грешка: ' . $error_detail );
		return false;
	}

	$result = decaldesk_parse_ai_json_response( $body['content'][0]['text'], $parsed );

	if ( false === $result ) {
		decaldesk_log( 'Claude отговорът не съдържаше очаквания маркер ===DESCRIPTION=== - AI-то не спази формата.' );
	}

	return $result;
}

/**
 * Вика Google Gemini API (безплатен tier) - безплатен доставчик.
 *
 * Безплатният лимит на Google за Gemini 2.5 Flash е ~500 заявки/ден - далеч
 * над дневния лимит, който сами задаваме тук (по подразбиране 10), така че
 * практически никога не се удря лимитът на Google, а само нашия собствен таван.
 *
 * ВАЖНО: maxOutputTokens трябва да е достатъчно висок (1536+) и thinkingBudget
 * изключен (0) - иначе Gemini 2.5 Flash изразходва част от лимита за вътрешно
 * "мислене" преди да стигне до реалния текст, и отговорът излиза отрязан по средата.
 *
 * @param array  $parsed
 * @param string $api_key
 * @param string $image_base64 Base64-кодирано PNG изображение, или '' ако няма.
 * @return array|false Съдържание или false при грешка.
 */
function decaldesk_call_gemini_api( $parsed, $api_key, $image_base64 = '' ) {
	$prompt = decaldesk_build_ai_prompt( $parsed, ! empty( $image_base64 ) );
	$model  = 'gemini-2.5-flash';

	$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

	$parts = array();

	if ( ! empty( $image_base64 ) ) {
		$parts[] = array(
			'inline_data' => array(
				'mime_type' => 'image/png',
				'data'      => $image_base64,
			),
		);
	}

	$parts[] = array( 'text' => $prompt );

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 45,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode(
				array(
					'contents'         => array(
						array( 'parts' => $parts ),
					),
					'generationConfig' => array(
						'temperature'     => 0.7,
						// По-висок лимит + изключено "thinking" - иначе Gemini 2.5 Flash
						// изяжда токени за вътрешно разсъждение и отговорът излиза отрязан.
						'maxOutputTokens' => 1536,
						'thinkingConfig'  => array(
							'thinkingBudget' => 0,
						),
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		decaldesk_log( 'Gemini API заявка неуспешна: ' . $response->get_error_message() );
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	$text          = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
	$finish_reason = $body['candidates'][0]['finishReason'] ?? '';

	if ( 200 !== $code || empty( $text ) ) {
		$error_detail = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		if ( 'MAX_TOKENS' === $finish_reason ) {
			$error_detail .= ' (отговорът е отрязан заради лимит на токени, преди да излезе текст)';
		}
		decaldesk_log( 'Gemini API върна грешка: ' . $error_detail );
		return false;
	}

	$result = decaldesk_parse_ai_json_response( $text, $parsed );

	if ( false === $result ) {
		decaldesk_log( 'Gemini отговорът не съдържаше очаквания маркер ===DESCRIPTION=== - AI-то не спази формата.' );
	}

	return $result;
}
/*! </fs_premium_only> */

/**
 * Fallback съдържание (шаблонно), използва се когато AI е изключен или недостъпен.
 */
function decaldesk_build_fallback_content( $parsed ) {
	$area               = decaldesk_calculate_area_sqm( $parsed['width'], $parsed['height'] );
	$category_name      = decaldesk_get_category_display_name( $parsed['category'] );
	$language           = decaldesk_get_ai_content_language();
	$store_description  = decaldesk_get_store_description();

	// Fallback шаблоните НЕ минават през WP gettext система (__()) нарочно -
	// езикът им следва настройката "Език на AI съдържанието", не locale-а на
	// WP админ панела. Иначе биха се разминали: напр. admin на английски, но
	// избран AI език "немски" -> fallback щеше да излезе на английски (грешно),
	// вместо да съвпада с реалния AI изход. Засега поддържаме готови шаблони
	// за български и английски; всеки друг избран език пада на английски
	// (разумен универсален fallback, когато нямаме специфичен темплейт).
	//
	// Магазинът НЕ продава непременно декали/стикери - ако собственикът е
	// описал какво продава в Настройки, ползваме точно тази фраза вместо
	// хардкоднатото "самозалепващо се фолио"/"self-adhesive film" - иначе
	// поведението остава каквото беше преди (обратна съвместимост).
	if ( 'bulgarian' === strtolower( $language ) ) {
		$product_phrase = '' !== $store_description ? $store_description : 'самозалепващо се фолио';

		$description = sprintf(
			'%1$s – %2$s с размери %3$d x %4$d см (%5$s м²). Изработено от %6$s материал с високо качество на печат, подходящо за %7$s.',
			$parsed['name'],
			$product_phrase,
			$parsed['width'],
			$parsed['height'],
			$area,
			strtolower( $parsed['material'] ),
			mb_strtolower( $category_name )
		);

		$short_description = sprintf(
			'Размер: %1$d x %2$d см | Материал: %3$s',
			$parsed['width'],
			$parsed['height'],
			ucfirst( $parsed['material'] )
		);

		$meta_description = sprintf(
			'%1$s – %2$s %3$d x %4$d см. Поръчай онлайн с бърза доставка.',
			$parsed['name'],
			$product_phrase,
			$parsed['width'],
			$parsed['height']
		);

		$seo_title = sprintf( '%1$s %2$d x %3$d см – %4$s', $parsed['name'], $parsed['width'], $parsed['height'], $category_name );

		$size_unit = ' см';
	} else {
		$product_phrase = '' !== $store_description ? $store_description : 'self-adhesive film';

		$description = sprintf(
			'%1$s – %2$s, %3$d x %4$d cm (%5$s m²). Made from %6$s material with high-quality printing, suited for %7$s.',
			$parsed['name'],
			$product_phrase,
			$parsed['width'],
			$parsed['height'],
			$area,
			strtolower( $parsed['material'] ),
			mb_strtolower( $category_name )
		);

		$short_description = sprintf(
			'Size: %1$d x %2$d cm | Material: %3$s',
			$parsed['width'],
			$parsed['height'],
			ucfirst( $parsed['material'] )
		);

		$meta_description = sprintf(
			'%1$s – %2$s, %3$d x %4$d cm. Order online with fast delivery.',
			$parsed['name'],
			$product_phrase,
			$parsed['width'],
			$parsed['height']
		);

		$seo_title = sprintf( '%1$s %2$d x %3$d cm – %4$s', $parsed['name'], $parsed['width'], $parsed['height'], $category_name );

		$size_unit = ' cm';
	}

	// Без AI не измисляме "умна" ключова фраза - просто името + категорията,
	// с достатъчна точност за повечето магазини и без риск от объркващ резултат.
	$focus_keyphrase = mb_strtolower( trim( $parsed['name'] . ' ' . $category_name ) );

	// Fallback тагове - базирани на реалните данни от името на файла (без AI),
	// за да има консистентност дори когато AI е изключен/недостъпен.
	$fallback_tags = array_unique(
		array_filter(
			array(
				mb_strtolower( $parsed['name'] ),
				mb_strtolower( $category_name ),
				mb_strtolower( $parsed['material'] ),
				$parsed['width'] . 'x' . $parsed['height'] . $size_unit,
			)
		)
	);

	return array(
		'description'       => wpautop( esc_html( $description ) ),
		'short_description' => $short_description,
		'meta_description'  => mb_substr( $meta_description, 0, 160 ),
		'seo_title'         => mb_substr( $seo_title, 0, 70 ),
		'focus_keyphrase'   => mb_substr( $focus_keyphrase, 0, 80 ),
		'tags'              => array_values( $fallback_tags ),
	);
}

/*! <fs_premium_only> */
/**
 * ==========================================================
 * Диагностични "суров резултат" версии - за бутона "Тествай връзката"
 * Връщат точното съобщение за грешка (WP_Error) вместо тихо да логват и
 * да падат на fallback - полезно за диагностика от настройките.
 * ==========================================================
 */
function decaldesk_call_gemini_api_raw( $parsed, $api_key ) {
	$prompt = decaldesk_build_ai_prompt( $parsed );
	$model  = 'gemini-2.5-flash';
	$url    = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode(
				array(
					'contents'         => array(
						array( 'parts' => array( array( 'text' => $prompt ) ) ),
					),
					'generationConfig' => array(
						'temperature'     => 0.7,
						'maxOutputTokens' => 1536,
						'thinkingConfig'  => array(
							'thinkingBudget' => 0,
						),
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'decaldesk_gemini_error', 'Мрежова грешка: ' . $response->get_error_message() );
	}

	$code     = wp_remote_retrieve_response_code( $response );
	$raw_body = wp_remote_retrieve_body( $response );
	$body     = json_decode( $raw_body, true );

	if ( 200 !== $code ) {
		$error_detail = isset( $body['error']['message'] ) ? $body['error']['message'] : $raw_body;
		return new WP_Error( 'decaldesk_gemini_error', 'HTTP ' . $code . ' — ' . $error_detail );
	}

	$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

	if ( empty( $text ) ) {
		return new WP_Error( 'decaldesk_gemini_error', 'Празен отговор от Gemini. Суров JSON: ' . mb_substr( $raw_body, 0, 500 ) );
	}

	return $text;
}

function decaldesk_call_claude_api_raw( $parsed, $api_key, $model ) {
	$prompt = decaldesk_build_ai_prompt( $parsed );

	$response = wp_remote_post(
		'https://api.anthropic.com/v1/messages',
		array(
			'timeout' => 30,
			'headers' => array(
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
			),
			'body'    => wp_json_encode(
				array(
					'model'      => ! empty( $model ) ? $model : 'claude-sonnet-4-6',
					'max_tokens' => 1200,
					'messages'   => array(
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'decaldesk_claude_error', 'Мрежова грешка: ' . $response->get_error_message() );
	}

	$code     = wp_remote_retrieve_response_code( $response );
	$raw_body = wp_remote_retrieve_body( $response );
	$body     = json_decode( $raw_body, true );

	if ( 200 !== $code ) {
		$error_detail = isset( $body['error']['message'] ) ? $body['error']['message'] : $raw_body;
		return new WP_Error( 'decaldesk_claude_error', 'HTTP ' . $code . ' — ' . $error_detail );
	}

	$text = $body['content'][0]['text'] ?? '';

	if ( empty( $text ) ) {
		return new WP_Error( 'decaldesk_claude_error', 'Празен отговор от Claude. Суров JSON: ' . mb_substr( $raw_body, 0, 500 ) );
	}

	return $text;
}
/*! </fs_premium_only> */

/**
 * Връща избрания език за AI-генерираното продуктово съдържание (описания,
 * тагове) - НЕЗАВИСИМ от езика на admin панела. Default е "Bulgarian", за да
 * не се промени поведението на съществуващи инсталации след ъпдейт.
 *
 * @return string Име на езика на английски (напр. "Bulgarian", "German") -
 *                директно се вкарва в AI промпта като инструкция за изхода.
 */
function decaldesk_get_ai_content_language() {
	$settings = get_option( 'decaldesk_settings', array() );
	$language = isset( $settings['ai_content_language'] ) ? trim( $settings['ai_content_language'] ) : '';

	return '' !== $language ? $language : 'English';
}

/**
 * Връща свободния текст, с който потребителят описва какво продава
 * магазинът (напр. "vinyl decals and stickers", "framed canvas prints",
 * "ceramic tiles") - празен низ, ако не е конфигуриран. Ползва се и в AI
 * промпта, и в статичния fallback шаблон, за да звучи съдържанието
 * коректно за реалния тип продукт - DecalDesk не е ограничен само до
 * декали/стикери, а до всеки продукт, чиято цена зависи от площта.
 */
function decaldesk_get_store_description() {
	$settings = get_option( 'decaldesk_settings', array() );
	return isset( $settings['store_description'] ) ? trim( $settings['store_description'] ) : '';
}

/*! <fs_premium_only> */
/**
 * Връща API ключа: приоритет има константа в wp-config.php (по-сигурно),
 * след това полето от настройките в базата.
 */
function decaldesk_get_ai_api_key() {
	if ( defined( 'DECALDESK_AI_API_KEY' ) && DECALDESK_AI_API_KEY ) {
		return DECALDESK_AI_API_KEY;
	}

	$settings = get_option( 'decaldesk_settings', array() );
	return ! empty( $settings['ai_api_key'] ) ? $settings['ai_api_key'] : '';
}

/**
 * Връща Gemini API ключа: приоритет има константа в wp-config.php.
 */
function decaldesk_get_gemini_api_key() {
	if ( defined( 'DECALDESK_GEMINI_API_KEY' ) && DECALDESK_GEMINI_API_KEY ) {
		return DECALDESK_GEMINI_API_KEY;
	}

	$settings = get_option( 'decaldesk_settings', array() );
	return ! empty( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
}

/**
 * ==========================================================
 * Дневен лимит за безплатния AI доставчик
 * ==========================================================
 * Пазим брояч в един WP option, който се нулира автоматично при смяна на деня
 * (по часовата зона на сайта). Не ползваме transient с TTL, защото искаме
 * нулиране точно в полунощ, а не 24ч. от първата заявка.
 */

if ( ! defined( 'DECALDESK_QUOTA_OPTION' ) ) {
	define( 'DECALDESK_QUOTA_OPTION', 'decaldesk_ai_daily_usage' );
}

/**
 * Връща текущия брой изразходвани безплатни AI заявки за деня (0, ако денят е нов).
 */
function decaldesk_get_daily_usage() {
	$usage = get_option(
		DECALDESK_QUOTA_OPTION,
		array(
			'date'  => '',
			'count' => 0,
		)
	);
	$today = current_time( 'Y-m-d' );

	if ( $usage['date'] !== $today ) {
		return 0;
	}

	return (int) $usage['count'];
}

/**
 * Проверява дали има свободна квота за днес (без да я увеличава).
 */
function decaldesk_daily_quota_available( $daily_limit ) {
	return decaldesk_get_daily_usage() < $daily_limit;
}

/**
 * Увеличава дневния брояч с 1 (вика се само след успешна AI генерация).
 */
function decaldesk_increment_daily_quota() {
	$today = current_time( 'Y-m-d' );
	$usage = get_option(
		DECALDESK_QUOTA_OPTION,
		array(
			'date'  => '',
			'count' => 0,
		)
	);

	if ( $usage['date'] !== $today ) {
		$usage = array(
			'date'  => $today,
			'count' => 0,
		);
	}

	++$usage['count'];
	update_option( DECALDESK_QUOTA_OPTION, $usage, false );
}

/**
 * Помощна функция за AJAX/UI: връща оставащата безплатна квота за днес.
 */
function decaldesk_get_remaining_daily_quota() {
	$settings    = get_option( 'decaldesk_settings', array() );
	$daily_limit = ! empty( $settings['gemini_daily_limit'] ) ? (int) $settings['gemini_daily_limit'] : 10;
	$used        = decaldesk_get_daily_usage();

	return array(
		'used'      => $used,
		'limit'     => $daily_limit,
		'remaining' => max( 0, $daily_limit - $used ),
	);
}
/*! </fs_premium_only> */

/**
 * Помощна функция за логване на грешки от плъгина (в output/logs/ и/или error_log).
 */
function decaldesk_log( $message ) {
	$upload_dir = wp_upload_dir();
	$log_dir    = $upload_dir['basedir'] . '/decaldesk/logs';

	if ( ! file_exists( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}

	$line = '[' . current_time( 'mysql' ) . '] ' . $message . PHP_EOL;
	@file_put_contents( $log_dir . '/decaldesk.log', $line, FILE_APPEND | LOCK_EX );
}

/**
 * ==========================================================
 * Транслитерация на кирилица -> латиница (за slug/адрес на продукта)
 * ==========================================================
 */

/**
 * Транслитерира български кирилски текст на латиница по стандартната
 * система (Указ 3-2009 / улична транслитерация).
 *
 * @param string $text Текст на кирилица (или смесен).
 * @return string Транслитериран текст на латиница.
 */
function decaldesk_transliterate_bg( $text ) {
	$map = array(
		'А' => 'A',
		'Б' => 'B',
		'В' => 'V',
		'Г' => 'G',
		'Д' => 'D',
		'Е' => 'E',
		'Ж' => 'Zh',
		'З' => 'Z',
		'И' => 'I',
		'Й' => 'Y',
		'К' => 'K',
		'Л' => 'L',
		'М' => 'M',
		'Н' => 'N',
		'О' => 'O',
		'П' => 'P',
		'Р' => 'R',
		'С' => 'S',
		'Т' => 'T',
		'У' => 'U',
		'Ф' => 'F',
		'Х' => 'H',
		'Ц' => 'Ts',
		'Ч' => 'Ch',
		'Ш' => 'Sh',
		'Щ' => 'Sht',
		'Ъ' => 'A',
		'Ь' => 'Y',
		'Ю' => 'Yu',
		'Я' => 'Ya',
		'а' => 'a',
		'б' => 'b',
		'в' => 'v',
		'г' => 'g',
		'д' => 'd',
		'е' => 'e',
		'ж' => 'zh',
		'з' => 'z',
		'и' => 'i',
		'й' => 'y',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'о' => 'o',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'у' => 'u',
		'ф' => 'f',
		'х' => 'h',
		'ц' => 'ts',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'sht',
		'ъ' => 'a',
		'ь' => 'y',
		'ю' => 'yu',
		'я' => 'ya',
	);

	return strtr( $text, $map );
}

/**
 * Генерира URL-friendly slug (само латиница/цифри/тирета) от дадено име,
 * дори ако името е на кирилица.
 *
 * @param string $name    Име на продукта (може да е на кирилица).
 * @param array  $parsed  Пълните парснати данни (за добавяне на размери/категория за уникалност).
 * @return string Готов slug.
 */
function decaldesk_generate_slug( $name, $parsed = array() ) {
	$latin = decaldesk_transliterate_bg( $name );
	$slug  = sanitize_title( $latin );

	// Добавяме размери и категория, за да намалим риска от дублиращи се адреси
	if ( ! empty( $parsed['width'] ) && ! empty( $parsed['height'] ) ) {
		$slug .= '-' . (int) $parsed['width'] . 'x' . (int) $parsed['height'];
	}
	if ( ! empty( $parsed['category'] ) ) {
		$slug .= '-' . sanitize_title( $parsed['category'] );
	}

	return $slug;
}

/**
 * Генерира SKU (продуктов код) за Simple Product по правило, БЕЗ AI - същия
 * подход, ползван за вариациите на Variable Products (виж
 * decaldesk_create_variable_product()): slug + размери + материал, за да е
 * предвидим, уникален и да работи дори когато AI е изключен/недостъпен.
 * При колизия с вече съществуващ SKU добавя нарастващ суфикс (-2, -3, ...).
 *
 * @param array $parsed Резултат от decaldesk_parse_filename().
 * @return string Уникален SKU.
 */
function decaldesk_generate_product_sku( $parsed ) {
	$base          = decaldesk_generate_slug( $parsed['name'], $parsed );
	$material_slug = sanitize_title( decaldesk_transliterate_bg( $parsed['material'] ) );
	$sku           = $material_slug ? $base . '-' . $material_slug : $base;

	$unique_sku = $sku;
	$suffix     = 2;
	while ( function_exists( 'wc_get_product_id_by_sku' ) && wc_get_product_id_by_sku( $unique_sku ) ) {
		$unique_sku = $sku . '-' . $suffix;
		++$suffix;
	}

	return $unique_sku;
}

/**
 * Връща показваното име на категория по slug (от настройките, с fallback).
 */
function decaldesk_get_category_display_name( $category_slug ) {
	$settings   = get_option( 'decaldesk_settings', array() );
	$categories = isset( $settings['categories'] ) ? $settings['categories'] : array();

	return isset( $categories[ $category_slug ] ) ? $categories[ $category_slug ] : ucfirst( $category_slug );
}
