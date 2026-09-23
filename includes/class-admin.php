<?php
/**
 * The import screen, under Media.
 *
 * Renders an empty shell and hands the browser the REST endpoints and a nonce;
 * everything else is fetched. No PicPeak data is inlined into the page, and in
 * particular no token — see class-rest.php.
 */

namespace PicPeak;

defined( 'ABSPATH' ) || exit;

class Admin {

	const PAGE = 'picpeak-import';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function add_page(): void {
		add_submenu_page(
			'upload.php',
			__( 'Import from PicPeak', 'picpeak' ),
			__( 'Import from PicPeak', 'picpeak' ),
			Rest::capability(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	private static function is_screen(): bool {
		return isset( $_GET['page'] ) && self::PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only
	}

	public static function assets( string $hook ): void {
		if ( 'media_page_' . self::PAGE !== $hook || ! self::is_screen() ) {
			return;
		}

		wp_enqueue_style( 'picpeak-admin', PICPEAK_URL . 'assets/css/admin.css', array(), PICPEAK_VERSION );
		wp_enqueue_script( 'picpeak-admin', PICPEAK_URL . 'assets/js/admin.js', array(), PICPEAK_VERSION, true );

		wp_localize_script(
			'picpeak-admin',
			'picpeakData',
			array(
				'root'    => esc_url_raw( rest_url( Rest::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'library' => esc_url_raw( admin_url( 'upload.php' ) ),
				'batch'   => 5,
				'folder'  => Folders::label(),
				'i18n'    => array(
					'loading'        => __( 'Loading…', 'picpeak' ),
					'noGalleries'    => __( 'This token cannot see any galleries yet.', 'picpeak' ),
					'noPhotos'       => __( 'No photos match those filters.', 'picpeak' ),
					'selectAll'      => __( 'Select all', 'picpeak' ),
					'selectNone'     => __( 'Clear selection', 'picpeak' ),
					/* translators: %d: number of selected photos. */
					'selected'       => __( '%d selected', 'picpeak' ),
					'import'         => __( 'Import selected', 'picpeak' ),
					'importing'      => __( 'Importing…', 'picpeak' ),
					/* translators: 1: photos done, 2: photos total. */
					'progress'       => __( '%1$d of %2$d', 'picpeak' ),
					'done'           => __( 'Import finished.', 'picpeak' ),
					/* translators: %d: seconds to wait. */
					'rateLimited'    => __( 'PicPeak is rate limiting this site. Waiting %d seconds, then continuing.', 'picpeak' ),
					'imported'       => __( 'imported', 'picpeak' ),
					'skipped'        => __( 'already here', 'picpeak' ),
					'replaced'       => __( 'replaced', 'picpeak' ),
					'failed'         => __( 'failed', 'picpeak' ),
					'noPreview'      => __( 'No preview', 'picpeak' ),
					'video'          => __( 'Video', 'picpeak' ),
					'viewLibrary'    => __( 'Open the media library', 'picpeak' ),
					'stop'           => __( 'Stop', 'picpeak' ),
					'processing'     => __( 'Still processing in PicPeak', 'picpeak' ),
					'size'           => __( 'Size', 'picpeak' ),
					'size2048'       => __( 'Long edge 2048 px', 'picpeak' ),
					'size1600'       => __( 'Long edge 1600 px', 'picpeak' ),
					'size1200'       => __( 'Long edge 1200 px', 'picpeak' ),
					'sizeCustom'     => __( 'Custom…', 'picpeak' ),
					'sizeOriginal'   => __( 'Original', 'picpeak' ),
					'longestEdge'    => __( 'Longest edge in pixels', 'picpeak' ),
					/* translators: 1: number of images, 2: folder plugin name. */
					'folderFailed'   => __( '%1$d image(s) imported but could not be placed in a %2$s folder. They are still in the media library and tagged with the gallery name.', 'picpeak' ),
					/* translators: %d: how many photos were loaded. */
					'truncated'      => __( 'This gallery is larger than this screen loads at once. Showing the first %d photos — narrow it with the filters to reach the rest.', 'picpeak' ),
				),
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Rest::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to import media.', 'picpeak' ) );
		}
		?>
		<div class="wrap picpeak-wrap">
			<h1><?php esc_html_e( 'Import from PicPeak', 'picpeak' ); ?></h1>

			<?php if ( ! Settings::is_configured() ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s: link to the settings screen. */
							esc_html__( 'PicPeak is not connected yet. %s', 'picpeak' ),
							'<a href="' . esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE ) ) . '">'
								. esc_html__( 'Add the instance URL and an API token.', 'picpeak' ) . '</a>'
						);
						?>
					</p>
				</div>
				<?php return; ?>
			<?php endif; ?>

			<div id="picpeak-app" class="picpeak-app">
				<p class="picpeak-loading"><?php esc_html_e( 'Loading…', 'picpeak' ); ?></p>
			</div>
		</div>
		<?php
	}
}
