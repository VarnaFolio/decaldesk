<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ==========================================================
 * DB таблица за проследяване на фонови задачи (jobs)
 * ==========================================================
 * Всеки качен файл създава един ред тук със статус pending -> processing ->
 * done/error. Обработката се случва във фонов процес (Action Scheduler),
 * така че затварянето на браузър таба не прекъсва обработката.
 *
 * Ползваме собствена таблица (не един голям WP option), защото при стотици
 * качени файлове един autoloaded option би забавил всяка страница в админа.
 */

if ( ! defined( 'DECALDESK_DB_VERSION' ) ) {
	define( 'DECALDESK_DB_VERSION', '1.1' );
}

/**
 * Създава (или обновява чрез dbDelta) таблицата decaldesk_jobs.
 */
function decaldesk_create_jobs_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'decaldesk_jobs';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        filename VARCHAR(255) NOT NULL,
        file_hash VARCHAR(64) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        message TEXT NULL,
        product_id BIGINT UNSIGNED NULL,
        ai_source VARCHAR(20) NULL,
        price DECIMAL(10,2) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY status (status),
        KEY created_at (created_at),
        KEY file_hash (file_hash)
    ) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'decaldesk_db_version', DECALDESK_DB_VERSION );
}

/**
 * При всяко зареждане на плъгина проверяваме дали таблицата отговаря на
 * очакваната версия - и я създаваме/обновяваме ако не. Това е ВАЖНО за
 * работния процес чрез директно презаписване на файлове по FTP/rsync
 * (без деактивиране + активиране), защото activation hook няма да се
 * изпълни в такъв случай.
 */
function decaldesk_maybe_upgrade_db() {
	if ( get_option( 'decaldesk_db_version' ) !== DECALDESK_DB_VERSION ) {
		decaldesk_create_jobs_table();
	}
}
add_action( 'plugins_loaded', 'decaldesk_maybe_upgrade_db' );

/**
 * Помощни функции за работа с таблицата
 */
function decaldesk_jobs_table() {
	global $wpdb;
	return $wpdb->prefix . 'decaldesk_jobs';
}

/**
 * Създава нов job запис със статус 'pending'.
 *
 * @param string $filename  Оригиналното име на файла (за показване в UI).
 * @param string $file_hash SHA-256 хеш на съдържанието на файла (за detection на дубликати).
 * @return int ID на новосъздадения job.
 */
function decaldesk_create_job( $filename, $file_hash = '' ) {
	global $wpdb;

	$now = current_time( 'mysql' );

	$wpdb->insert(
		decaldesk_jobs_table(),
		array(
			'filename'   => $filename,
			'file_hash'  => $file_hash,
			'status'     => 'pending',
			'created_at' => $now,
			'updated_at' => $now,
		),
		array( '%s', '%s', '%s', '%s', '%s' )
	);

	return (int) $wpdb->insert_id;
}

/**
 * Търси съществуващ (не провален) job със същия хеш на съдържанието -
 * използва се за detection на дубликат дизайн (същия файл качен повторно).
 *
 * @param string $file_hash SHA-256 хеш на файла.
 * @return array|null Job записът, ако е намерен дубликат, иначе null.
 */
function decaldesk_find_duplicate_job( $file_hash ) {
	global $wpdb;

	if ( empty( $file_hash ) ) {
		return null;
	}

	$row = $wpdb->get_row(
		$wpdb->prepare(
			'SELECT * FROM ' . decaldesk_jobs_table() . "
             WHERE file_hash = %s AND status IN ('pending', 'processing', 'done')
             ORDER BY id DESC LIMIT 1",
			$file_hash
		),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Обновява полета на съществуващ job.
 *
 * @param int   $job_id
 * @param array $fields Асоциативен масив колона => стойност.
 */
function decaldesk_update_job( $job_id, $fields ) {
	global $wpdb;

	$fields['updated_at'] = current_time( 'mysql' );

	$wpdb->update(
		decaldesk_jobs_table(),
		$fields,
		array( 'id' => (int) $job_id )
	);
}

/**
 * Връща един job по ID.
 *
 * @param int $job_id
 * @return array|null
 */
function decaldesk_get_job( $job_id ) {
	global $wpdb;

	$row = $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . decaldesk_jobs_table() . ' WHERE id = %d', $job_id ),
		ARRAY_A
	);

	return $row ? $row : null;
}

/**
 * Връща множество job-ове по списък от ID-та (за polling статус от JS).
 *
 * @param int[] $job_ids
 * @return array[]
 */
function decaldesk_get_jobs( $job_ids ) {
	global $wpdb;

	$job_ids = array_map( 'intval', $job_ids );
	$job_ids = array_filter( $job_ids );

	if ( empty( $job_ids ) ) {
		return array();
	}

	$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
	$sql          = 'SELECT * FROM ' . decaldesk_jobs_table() . " WHERE id IN ({$placeholders})";

	return $wpdb->get_results( $wpdb->prepare( $sql, $job_ids ), ARRAY_A );
}

/**
 * Гъвкава заявка за job-ове - поддържа филтър по статус, търсене по име на
 * файл, сортиране и пагинация. Използва се от List Table в "История".
 *
 * @param array $args {
 *     @type string $status   Филтър по статус ('', 'pending', 'processing', 'done', 'error'). '' = всички.
 *     @type string $search   Търсене по filename (LIKE).
 *     @type string $orderby  Колона за сортиране (по подразбиране 'id').
 *     @type string $order    'ASC' или 'DESC' (по подразбиране 'DESC').
 *     @type int    $per_page Брой резултати на страница.
 *     @type int    $page     Номер на текущата страница (1-based).
 * }
 * @return array[]
 */
function decaldesk_query_jobs( $args = array() ) {
	global $wpdb;
	$table = decaldesk_jobs_table();

	$defaults = array(
		'status'   => '',
		'search'   => '',
		'orderby'  => 'id',
		'order'    => 'DESC',
		'per_page' => 20,
		'page'     => 1,
	);
	$args     = wp_parse_args( $args, $defaults );

	$where  = array( '1=1' );
	$params = array();

	if ( ! empty( $args['status'] ) ) {
		$where[]  = 'status = %s';
		$params[] = $args['status'];
	}

	if ( ! empty( $args['search'] ) ) {
		$where[]  = 'filename LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
	}

	$allowed_orderby = array( 'id', 'filename', 'status', 'price', 'created_at' );
	$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
	$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

	$per_page = max( 1, (int) $args['per_page'] );
	$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

	$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where )
		. " ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

	$params[] = $per_page;
	$params[] = $offset;

	return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
}

/**
 * Брои общия брой job-ове, отговарящи на същите филтри като decaldesk_query_jobs()
 * (без LIMIT/OFFSET) - за пагинацията.
 */
function decaldesk_count_jobs( $args = array() ) {
	global $wpdb;
	$table = decaldesk_jobs_table();

	$where  = array( '1=1' );
	$params = array();

	if ( ! empty( $args['status'] ) ) {
		$where[]  = 'status = %s';
		$params[] = $args['status'];
	}

	if ( ! empty( $args['search'] ) ) {
		$where[]  = 'filename LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
	}

	$sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );

	if ( empty( $params ) ) {
		return (int) $wpdb->get_var( $sql );
	}

	return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
}

/**
 * Изтрива job записи по ID (само записа в нашата таблица - НЕ пипа реалния
 * WooCommerce продукт, дори ако job-ът е свързан с такъв).
 *
 * @param int[] $job_ids
 * @return int Брой изтрити редове.
 */
function decaldesk_delete_jobs( $job_ids ) {
	global $wpdb;

	$job_ids = array_filter( array_map( 'intval', $job_ids ) );
	if ( empty( $job_ids ) ) {
		return 0;
	}

	$placeholders = implode( ',', array_fill( 0, count( $job_ids ), '%d' ) );
	$sql          = 'DELETE FROM ' . decaldesk_jobs_table() . " WHERE id IN ({$placeholders})";

	return $wpdb->query( $wpdb->prepare( $sql, $job_ids ) );
}

/**
 * ==========================================================
 * Автоматично почистване на стара история (дневен cron, виж decaldesk.php)
 * ==========================================================
 * Без това jobs таблицата и uploads/decaldesk/incoming/ растат безкрайно -
 * успешно обработените jobs вече чистят собствените си файлове веднага (виж
 * decaldesk_cleanup_job_files() в background.php), но самият ред в историята
 * оставаше завинаги, а провалените jobs изобщо не се чистеха. Продуктите,
 * вече създадени от старите jobs, НЕ се засягат по никакъв начин - трие се
 * само служебната бекенд информация (история + евентуален осиротял файл).
 */
function decaldesk_cleanup_old_jobs() {
	global $wpdb;

	$settings       = get_option( 'decaldesk_settings', array() );
	$retention_days = isset( $settings['job_retention_days'] ) ? (int) $settings['job_retention_days'] : 90;

	// 0 = автоматичното почистване е изрично изключено от потребителя.
	if ( $retention_days <= 0 ) {
		return;
	}

	$table  = decaldesk_jobs_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - $retention_days * DAY_IN_SECONDS );

	$old_jobs = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, filename FROM {$table} WHERE status IN ('done', 'error') AND updated_at < %s",
			$cutoff
		),
		ARRAY_A
	);

	if ( empty( $old_jobs ) ) {
		return;
	}

	// Почистваме евентуален останал файл в incoming/ за всеки job, преди да
	// изтрием реда - най-вече засяга 'error' jobs (успешните вече са си
	// изчистили файла веднага след обработката, виж decaldesk_cleanup_job_files()).
	$upload_dir   = wp_upload_dir();
	$incoming_dir = $upload_dir['basedir'] . '/decaldesk/incoming';

	foreach ( $old_jobs as $job ) {
		if ( empty( $job['filename'] ) ) {
			continue;
		}

		$file_path = $incoming_dir . '/' . sanitize_file_name( $job['filename'] );
		if ( file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	decaldesk_delete_jobs( wp_list_pluck( $old_jobs, 'id' ) );
}
add_action( 'decaldesk_daily_cleanup', 'decaldesk_cleanup_old_jobs' );

/**
 * Връща последните N job-а (за статус таблото в админа).
 *
 * @param int $limit
 * @return array[]
 */
function decaldesk_get_recent_jobs( $limit = 50 ) {
	global $wpdb;

	return $wpdb->get_results(
		$wpdb->prepare( 'SELECT * FROM ' . decaldesk_jobs_table() . ' ORDER BY id DESC LIMIT %d', $limit ),
		ARRAY_A
	);
}

/**
 * Бърза статистика за статус таблото: колко pending/processing/done/error.
 *
 * @return array{pending:int,processing:int,done:int,error:int}
 */
function decaldesk_get_job_stats() {
	global $wpdb;

	$rows = $wpdb->get_results( 'SELECT status, COUNT(*) as cnt FROM ' . decaldesk_jobs_table() . ' GROUP BY status', ARRAY_A );

	$stats = array(
		'pending'    => 0,
		'processing' => 0,
		'done'       => 0,
		'error'      => 0,
	);
	foreach ( $rows as $row ) {
		if ( isset( $stats[ $row['status'] ] ) ) {
			$stats[ $row['status'] ] = (int) $row['cnt'];
		}
	}

	return $stats;
}

/**
 * Статистика за последните N дни - използва се за admin notice-а, който
 * предупреждава при много fallback описания или грешки при обработка.
 *
 * @param int $days Брой дни назад (по подразбиране 7).
 * @return array{
 *     fallback_count: int,   Успешно завършени job-ове с ai_source='fallback'
 *     ai_success_count: int, Успешно завършени job-ове с реален AI (free/claude)
 *     error_count: int,      Job-ове завършили със статус 'error'
 *     last_error_message: string|null
 * }
 */
function decaldesk_get_recent_job_health( $days = 7 ) {
	global $wpdb;
	$table = decaldesk_jobs_table();

	// ВАЖНО: created_at се пази чрез current_time('mysql') (локално време на
	// сайта), затова границата тук трябва да се смята по същия начин - не
	// през GMT - иначе сравнението е леко изместено при сайтове извън UTC.
	// gmdate(), not date(): current_time('timestamp') already has the site's
	// gmt_offset baked in, so date() would apply the server's own PHP
	// timezone on top of that and double-shift the result on non-UTC servers.
	$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( (int) $days * DAY_IN_SECONDS ) );

	$fallback_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'done' AND ai_source = 'fallback' AND created_at >= %s",
			$since
		)
	);

	$ai_success_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'done' AND ai_source IN ('ai_free', 'ai_claude') AND created_at >= %s",
			$since
		)
	);

	$error_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'error' AND created_at >= %s",
			$since
		)
	);

	$last_error_message = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT message FROM {$table} WHERE status = 'error' AND created_at >= %s ORDER BY id DESC LIMIT 1",
			$since
		)
	);

	return array(
		'fallback_count'     => $fallback_count,
		'ai_success_count'   => $ai_success_count,
		'error_count'        => $error_count,
		'last_error_message' => $last_error_message ? $last_error_message : null,
	);
}
