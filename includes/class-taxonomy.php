<?php
/**
 * A taxonomy on attachments, named after the PicPeak gallery.
 *
 * WordPress core has no media folders — the library is a flat list — so
 * "which shoot did this come from" has nowhere to live otherwise. Registering
 * a taxonomy gives that a home AND a filter dropdown in the media grid for
 * free, with no folder plugin involved and no dependency on one. The optional
 * folder adapters in class-folders.php layer on top of this rather than
 * replacing it, so nothing breaks if a folder plugin is later deactivated.
 */

namespace PicPeak;

defined( 'ABSPATH' ) || exit;

class Taxonomy {

	const NAME = 'picpeak_gallery';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
	}

	public static function register(): void {
		register_taxonomy(
			self::NAME,
			'attachment',
			array(
				'labels'            => array(
					'name'          => __( 'PicPeak galleries', 'picpeak' ),
					'singular_name' => __( 'PicPeak gallery', 'picpeak' ),
					'menu_name'     => __( 'PicPeak galleries', 'picpeak' ),
					'all_items'     => __( 'All galleries', 'picpeak' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => false,
				'rewrite'           => false,
				// Terms are created by the importer from gallery names, which
				// are the photographer's own wording.
				// The same gate as the import routes: these terms are created
				// from PicPeak gallery names, so whoever may import may manage
				// them, and nobody else.
				'capabilities'      => array(
					'manage_terms' => Rest::capability(),
					'edit_terms'   => Rest::capability(),
					'delete_terms' => Rest::capability(),
					'assign_terms' => Rest::capability(),
				),
			)
		);
	}

	/**
	 * Term for a gallery, created on first import.
	 *
	 * Keyed on the PicPeak slug rather than the display name: two events can
	 * share a name, and a renamed event keeps importing into the same term.
	 */
	public static function term_for( string $slug, string $name ): ?int {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return null;
		}

		$existing = get_term_by( 'slug', $slug, self::NAME );
		if ( $existing instanceof \WP_Term ) {
			return (int) $existing->term_id;
		}

		$created = wp_insert_term( '' !== $name ? $name : $slug, self::NAME, array( 'slug' => $slug ) );
		if ( is_wp_error( $created ) ) {
			// A parallel import may have won the race; take whatever is there.
			$again = get_term_by( 'slug', $slug, self::NAME );
			return $again instanceof \WP_Term ? (int) $again->term_id : null;
		}

		return (int) $created['term_id'];
	}

	/** The media library's own filter row, on the list view. */
	public static function filter_dropdown( string $post_type ): void {
		if ( 'attachment' !== $post_type || ! current_user_can( 'upload_files' ) ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::NAME,
				'hide_empty' => true,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$current = isset( $_GET[ self::NAME ] ) ? sanitize_title( wp_unslash( $_GET[ self::NAME ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
		?>
		<label class="screen-reader-text" for="picpeak-gallery-filter">
			<?php esc_html_e( 'Filter by PicPeak gallery', 'picpeak' ); ?>
		</label>
		<select name="<?php echo esc_attr( self::NAME ); ?>" id="picpeak-gallery-filter">
			<option value=""><?php esc_html_e( 'All PicPeak galleries', 'picpeak' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
				<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $current, $term->slug ); ?>>
					<?php echo esc_html( $term->name ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
