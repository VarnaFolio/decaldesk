<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ==========================================================
 * "История" - таблица с всички обработени дизайни
 * ==========================================================
 * Използва WP_List_Table - стандартния WordPress клас за административни
 * таблици (същият, който стои зад "Продукти", "Публикации" и т.н.), за да
 * изглежда напълно естествено в админ панела: сортиране, търсене, филтри
 * по статус, пагинация, bulk действия.
 */

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class DecalDesk_Jobs_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => 'job',
            'plural'   => 'jobs',
            'ajax'     => false,
        ) );
    }

    public function get_columns() {
        return array(
            'cb'         => '<input type="checkbox" />',
            'thumbnail'  => '',
            'filename'   => __( 'File', 'decaldesk' ),
            'status'     => __( 'Status', 'decaldesk' ),
            'ai_source'  => __( 'Description', 'decaldesk' ),
            'price'      => __( 'Price', 'decaldesk' ),
            'created_at' => __( 'Date', 'decaldesk' ),
        );
    }

    public function get_sortable_columns() {
        return array(
            'filename'   => array( 'filename', false ),
            'status'     => array( 'status', false ),
            'price'      => array( 'price', false ),
            'created_at' => array( 'created_at', true ), // по подразбиране сортирано по това
        );
    }

    protected function get_views() {
        $stats  = decaldesk_get_job_stats();
        $total  = array_sum( $stats );
        $current_status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';

        $base_url = admin_url( 'admin.php?page=decaldesk-history' );

        $labels = array(
            ''           => __( 'All', 'decaldesk' ),
            'pending'    => __( 'Pending', 'decaldesk' ),
            'processing' => __( 'Processing', 'decaldesk' ),
            'done'       => __( 'Done', 'decaldesk' ),
            'error'      => __( 'Failed', 'decaldesk' ),
        );

        $views = array();
        foreach ( $labels as $status_key => $label ) {
            $count = '' === $status_key ? $total : $stats[ $status_key ];
            $url   = '' === $status_key ? $base_url : add_query_arg( 'status', $status_key, $base_url );
            $class = ( $current_status === $status_key ) ? 'current' : '';

            $views[ $status_key ?: 'all' ] = sprintf(
                '<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url( $url ),
                esc_attr( $class ),
                esc_html( $label ),
                (int) $count
            );
        }

        return $views;
    }

    protected function get_bulk_actions() {
        return array(
            'publish' => __( 'Publish selected', 'decaldesk' ),
            'delete'  => __( 'Delete record (doesn\'t touch the product)', 'decaldesk' ),
        );
    }

    protected function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="job_ids[]" value="%d" />', $item['id'] );
    }

    protected function column_thumbnail( $item ) {
        if ( 'done' === $item['status'] && ! empty( $item['product_id'] ) ) {
            $thumb = get_the_post_thumbnail( (int) $item['product_id'], array( 40, 40 ) );
            if ( $thumb ) {
                return $thumb;
            }
        }
        return '<span class="dashicons dashicons-format-image" style="opacity:0.25;font-size:32px;width:40px;height:40px;"></span>';
    }

    protected function column_filename( $item ) {
        $actions = array();

        if ( 'done' === $item['status'] && ! empty( $item['product_id'] ) ) {
            $edit_link = get_edit_post_link( (int) $item['product_id'], '' );
            $actions['edit'] = sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit product', 'decaldesk' ) );

            $view_link = get_permalink( (int) $item['product_id'] );
            if ( $view_link ) {
                $actions['view'] = sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( $view_link ), esc_html__( 'View in store', 'decaldesk' ) );
            }

            if ( 'draft' === get_post_status( (int) $item['product_id'] ) ) {
                $actions['publish'] = sprintf(
                    '<a href="%s" style="color:#00a32a;">%s</a>',
                    esc_url( wp_nonce_url(
                        add_query_arg( array( 'action' => 'publish', 'job_ids[]' => $item['id'] ) ),
                        'bulk-' . $this->_args['plural']
                    ) ),
                    esc_html__( 'Publish', 'decaldesk' )
                );
            }
        }

        if ( 'error' === $item['status'] && ! empty( $item['message'] ) ) {
            $actions['error'] = '<span style="color:#d63638;">' . esc_html( $item['message'] ) . '</span>';
        }

        $actions['delete'] = sprintf(
            '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
            esc_url( wp_nonce_url(
                add_query_arg( array( 'action' => 'delete', 'job_ids[]' => $item['id'] ) ),
                'bulk-' . $this->_args['plural']
            ) ),
            esc_js( __( 'Delete this record from history? (the store product, if any, is NOT deleted)', 'decaldesk' ) ),
            esc_html__( 'Delete', 'decaldesk' )
        );

        return sprintf( '<strong>%s</strong>%s', esc_html( $item['filename'] ), $this->row_actions( $actions ) );
    }

    protected function column_status( $item ) {
        $badges = array(
            'pending'    => array( 'label' => __( 'Pending', 'decaldesk' ), 'color' => '#dba617', 'bg' => '#fcf9e8' ),
            'processing' => array( 'label' => __( 'Processing', 'decaldesk' ), 'color' => '#2271b1', 'bg' => '#eef6fc' ),
            'done'       => array( 'label' => __( 'Done', 'decaldesk' ), 'color' => '#00a32a', 'bg' => '#edfaef' ),
            'error'      => array( 'label' => __( 'Error', 'decaldesk' ), 'color' => '#d63638', 'bg' => '#fcf0f1' ),
        );

        $status = $item['status'];
        $badge  = isset( $badges[ $status ] ) ? $badges[ $status ] : array( 'label' => $status, 'color' => '#646970', 'bg' => '#f0f0f1' );

        return sprintf(
            '<span style="display:inline-block;padding:3px 10px;border-radius:10px;font-size:12px;font-weight:600;color:%s;background:%s;">%s</span>',
            esc_attr( $badge['color'] ),
            esc_attr( $badge['bg'] ),
            esc_html( $badge['label'] )
        );
    }

    protected function column_ai_source( $item ) {
        if ( 'done' !== $item['status'] ) {
            return '—';
        }

        $badges = array(
            'ai_free'   => array( 'label' => __( 'AI (free)', 'decaldesk' ), 'color' => '#007a2e', 'bg' => '#e7f5ea' ),
            'ai_claude' => array( 'label' => __( 'AI (Claude)', 'decaldesk' ), 'color' => '#6b21a8', 'bg' => '#f1e9fb' ),
            'fallback'  => array( 'label' => __( 'Template', 'decaldesk' ), 'color' => '#646970', 'bg' => '#f0f0f1' ),
        );

        $source = $item['ai_source'];
        if ( ! isset( $badges[ $source ] ) ) {
            return '—';
        }

        $badge = $badges[ $source ];

        return sprintf(
            '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;color:%s;background:%s;">%s</span>',
            esc_attr( $badge['color'] ),
            esc_attr( $badge['bg'] ),
            esc_html( $badge['label'] )
        );
    }

    protected function column_price( $item ) {
        return $item['price'] ? wc_price( $item['price'] ) : '—';
    }

    protected function column_created_at( $item ) {
        $timestamp = strtotime( $item['created_at'] );
        return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
    }

    protected function column_default( $item, $column_name ) {
        return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
    }

    protected function process_bulk_action() {
        $job_ids = isset( $_REQUEST['job_ids'] ) ? (array) $_REQUEST['job_ids'] : array();

        if ( empty( $job_ids ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You don\'t have permission to do this.', 'decaldesk' ) );
        }

        if ( 'delete' === $this->current_action() ) {
            check_admin_referer( 'bulk-' . $this->_args['plural'] );
            decaldesk_delete_jobs( $job_ids );

            add_action( 'admin_notices', function () use ( $job_ids ) {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    sprintf(
                        /* translators: %d: number of deleted records */
                        esc_html__( 'Deleted %d records from history.', 'decaldesk' ),
                        count( $job_ids )
                    ) . '</p></div>';
            } );
        }

        if ( 'publish' === $this->current_action() ) {
            check_admin_referer( 'bulk-' . $this->_args['plural'] );

            $published = 0;
            $skipped   = 0;

            foreach ( $job_ids as $job_id ) {
                $job = decaldesk_get_job( (int) $job_id );

                if ( ! $job || 'done' !== $job['status'] || empty( $job['product_id'] ) ) {
                    $skipped++;
                    continue;
                }

                $product_id = (int) $job['product_id'];

                if ( 'draft' !== get_post_status( $product_id ) ) {
                    $skipped++;
                    continue;
                }

                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    $skipped++;
                    continue;
                }

                $product->set_status( 'publish' );
                $product->save();
                $published++;
            }

            add_action( 'admin_notices', function () use ( $published, $skipped ) {
                $message = sprintf(
                    /* translators: %d: number of published products */
                    esc_html__( 'Published %d products.', 'decaldesk' ),
                    $published
                );
                if ( $skipped > 0 ) {
                    $message .= ' ' . sprintf(
                        /* translators: %d: number of skipped records */
                        esc_html__( '%d skipped (already published, still processing, or without a product).', 'decaldesk' ),
                        $skipped
                    );
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses_post( $message ) . '</p></div>';
            } );
        }
    }

    public function prepare_items() {
        $this->process_bulk_action();

        $per_page     = 20;
        $current_page = $this->get_pagenum();

        $status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created_at';
        $order   = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'desc';

        $args = array(
            'status'   => $status,
            'search'   => $search,
            'orderby'  => $orderby,
            'order'    => $order,
            'per_page' => $per_page,
            'page'     => $current_page,
        );

        $this->items = decaldesk_query_jobs( $args );
        $total_items = decaldesk_count_jobs( $args );

        $this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ) );
    }
}

/**
 * Рендер на страницата "История"
 */
function decaldesk_render_history_page() {
    $stats = decaldesk_get_job_stats();

    $list_table = new DecalDesk_Jobs_List_Table();
    $list_table->prepare_items();
    ?>
    <div class="wrap decaldesk-wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e( 'DecalDesk – History', 'decaldesk' ); ?></h1>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=decaldesk' ) ); ?>" class="page-title-action">
            <?php esc_html_e( 'Upload new designs', 'decaldesk' ); ?>
        </a>
        <hr class="wp-header-end">

        <div class="decaldesk-stat-cards">
            <div class="decaldesk-stat-card">
                <span class="decaldesk-stat-number"><?php echo (int) $stats['pending']; ?></span>
                <span class="decaldesk-stat-label"><?php esc_html_e( 'Pending', 'decaldesk' ); ?></span>
            </div>
            <div class="decaldesk-stat-card">
                <span class="decaldesk-stat-number"><?php echo (int) $stats['processing']; ?></span>
                <span class="decaldesk-stat-label"><?php esc_html_e( 'Processing', 'decaldesk' ); ?></span>
            </div>
            <div class="decaldesk-stat-card decaldesk-stat-card-success">
                <span class="decaldesk-stat-number"><?php echo (int) $stats['done']; ?></span>
                <span class="decaldesk-stat-label"><?php esc_html_e( 'Total done', 'decaldesk' ); ?></span>
            </div>
            <div class="decaldesk-stat-card <?php echo $stats['error'] > 0 ? 'decaldesk-stat-card-error' : ''; ?>">
                <span class="decaldesk-stat-number"><?php echo (int) $stats['error']; ?></span>
                <span class="decaldesk-stat-label"><?php esc_html_e( 'Failed', 'decaldesk' ); ?></span>
            </div>
        </div>

        <form method="get">
            <input type="hidden" name="page" value="decaldesk-history">
            <?php
            // Nonce за bulk действията (публикуване/изтриване) - без това полето,
            // WordPress отхвърля всяка bulk заявка като невалидна ("Are you sure?").
            wp_nonce_field( 'bulk-jobs' );

            $list_table->views();
            $list_table->search_box( __( 'Search by filename', 'decaldesk' ), 'decaldesk-search' );
            $list_table->display();
            ?>
        </form>
    </div>
    <?php
}
