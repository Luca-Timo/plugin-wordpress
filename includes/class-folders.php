<?php
/**
 * Optional bridge to whichever media-folder plugin the site already runs.
 *
 * WordPress core has no media folders, so "import into a folder" only means
 * anything when a third-party plugin provides them — and there are several,
 * with incompatible data models. This detects whichever is active and places
 * imported attachments there as well.
 *
 * Nothing here is required. With no folder plugin active every method is a
 * no-op and imports are simply findable through the picpeak_gallery taxonomy,
 * which is registered regardless. Placement is additive for the same reason:
 * deactivating a folder plugin later must not strip anything that matters.
 */

namespace PicPeak;

defined( 'ABSPATH' ) || exit;

class Folders {

	/**
	 * Detection is by class/function existence, never by reading the active
	 * plugin list: these get forked and renamed, and a slug check would miss
	 * a fork that still exposes the same API.
	 */
	public static function detect(): ?string {
		if ( class_exists( '\FileBird\Model\Folder' ) ) {
			return 'filebird';
		}
		if ( function_exists( 'wp_rml_create_or_return_existing_folder' ) ) {
			return 'real-media-library';
		}
		return null;
	}

	/** What to show on the import screen, or null when there is nothing to say. */
	public static function label(): ?string {
		switch ( self::detect() ) {
			case 'filebird':
				return __( 'FileBird', 'picpeak' );
			case 'real-media-library':
				return __( 'Real Media Library', 'picpeak' );
		}
		return null;
	}

	/**
	 * Put an imported attachment in a folder named after its gallery.
	 *
	 * Failure is deliberately silent. The image is already in the library and
	 * already carries its taxonomy term, so a folder plugin changing its API
	 * between versions must not turn a successful import into a failed one.
	 */
	public static function place( int $attachment_id, array $event ): void {
		$name = trim( (string) ( $event['event_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( (string) ( $event['slug'] ?? '' ) );
		}
		if ( '' === $name ) {
			return;
		}

		/**
		 * Filters the folder name an imported attachment is placed in.
		 *
		 * @param string $name  Gallery name.
		 * @param array  $event The PicPeak event row.
		 */
		$name = (string) apply_filters( 'picpeak_folder_name', $name, $event );

		try {
			switch ( self::detect() ) {
				case 'filebird':
					self::place_filebird( $attachment_id, $name );
					break;
				case 'real-media-library':
					self::place_rml( $attachment_id, $name );
					break;
			}
		} catch ( \Throwable $e ) {
			// An import that landed is not a failure because a folder plugin
			// moved its API. Recorded for support, not surfaced.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'PicPeak: could not place attachment in a folder: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	private static function place_filebird( int $attachment_id, string $name ): void {
		$folder = \FileBird\Model\Folder::getFolderByName( $name );
		$id     = is_array( $folder ) && isset( $folder['id'] ) ? (int) $folder['id'] : 0;

		if ( ! $id ) {
			$created = \FileBird\Model\Folder::newOrGet( $name, 0 );
			$id      = is_array( $created ) && isset( $created['id'] ) ? (int) $created['id'] : (int) $created;
		}

		if ( $id > 0 && class_exists( '\FileBird\Classes\Helpers' ) ) {
			\FileBird\Classes\Helpers::setFolder( array( $attachment_id ), $id );
		}
	}

	private static function place_rml( int $attachment_id, string $name ): void {
		$folder_id = wp_rml_create_or_return_existing_folder( $name );
		if ( $folder_id && ! is_wp_error( $folder_id ) && function_exists( 'wp_attachment_move' ) ) {
			wp_attachment_move( array( $attachment_id ), (int) $folder_id );
		}
	}
}
