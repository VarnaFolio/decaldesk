<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Брандиран header + таб навигация, споделени между четирите DecalDesk admin
 * страници (Upload/History/Categories/Settings) - заменя обикновения <h1>
 * с полиран hero бар и позволява превключване между страниците без да се
 * минава през лявото WP admin меню всеки път.
 *
 * @param string $current_slug Query-string стойността на текущата страница
 *                              (напр. 'decaldesk', 'decaldesk-categories').
 * @param string $subtitle     Кратък подзаглавен текст под "DecalDesk" (по избор).
 */
function decaldesk_render_page_header( $current_slug, $subtitle = '' ) {
	$tabs = array(
		'decaldesk'            => __( 'Upload', 'decaldesk' ),
		'decaldesk-history'    => __( 'History', 'decaldesk' ),
		'decaldesk-categories' => __( 'Categories', 'decaldesk' ),
		'decaldesk-settings'   => __( 'Settings', 'decaldesk' ),
	);
	?>
	<div class="decaldesk-header">
		<div class="decaldesk-header-top">
			<span class="decaldesk-header-logo" aria-hidden="true">
				<svg width="28" height="28" viewBox="0 0 28 28" xmlns="http://www.w3.org/2000/svg">
					<rect width="28" height="28" rx="7" fill="#153631"/>
					<path d="M8 18.5L14 7.5L20 18.5H8Z" fill="#ccff66"/>
				</svg>
			</span>
			<div class="decaldesk-header-titles">
				<span class="decaldesk-header-name"><?php esc_html_e( 'DecalDesk', 'decaldesk' ); ?></span>
				<?php if ( $subtitle ) : ?>
					<span class="decaldesk-header-subtitle"><?php echo esc_html( $subtitle ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<nav class="decaldesk-header-tabs" aria-label="<?php esc_attr_e( 'DecalDesk sections', 'decaldesk' ); ?>">
			<?php foreach ( $tabs as $slug => $label ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"
					class="decaldesk-header-tab<?php echo $slug === $current_slug ? ' is-active' : ''; ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
	</div>
	<?php
}
