<?php
/**
 * Moves one photo from PicPeak into the media library.
 *
 * Fetching is authenticated, so media_sideload_image() is not an option: it
 * takes a URL and fetches it unauthenticated. The file is pulled with the
 * bearer header into a temp file and handed to media_handle_sideload()
 * instead — which is also why PicPeak needs no signed-URL endpoint for any of
 * this.
 *
 * Imports are deduplicated on the PicPeak row id rather than the filename.
 * Filenames repeat across shoots, and PicPeak's own file watcher has been
 * bitten by filename-based matching before.
 */

namespace PicPeak;

defined( 'ABSPATH' ) || exit;

use WP_Error;

class Importer {

	const META_PHOTO    = '_picpeak_photo_id';
	const META_EVENT    = '_picpeak_event_id';
	const META_INSTANCE = '_picpeak_instance';

	/** The attachment already holding this photo, if there is one. */
	public static function existing( int $photo_id ): ?int {
		$instance = Settings::instance_id();
		if ( '' === $instance ) {
			return null;
		}

		$found = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- indexed meta, bounded to one row
					'relation' => 'AND',
					array(
						'key'   => self::META_PHOTO,
						'value' => (string) $photo_id,
					),
					array(
						'key'   => self::META_INSTANCE,
						'value' => $instance,
					),
				),
			)
		);

		return empty( $found ) ? null : (int) $found[0];
	}

	/**
	 * Import one photo.
	 *
	 * @param array $photo  A row from the v1 photo list.
	 * @param array $event  id / slug / event_name of the gallery it came from.
	 * @param array $opts   resolution, watermark, on_duplicate.
	 * @return array|WP_Error {status: imported|skipped|replaced, attachment_id, title}
	 */
	public static function import( array $photo, array $event, array $opts ) {
		// The same gate as the route, checked again here: this is the last
		// point before a remote file is written into the library, and the
		// importer is callable from anywhere in PHP.
		if ( ! current_user_can( Rest::capability() ) ) {
			return new WP_Error( 'picpeak_forbidden', __( 'You do not have permission to import media.', 'picpeak' ) );
		}

		$photo_id = isset( $photo['id'] ) ? (int) $photo['id'] : 0;
		$event_id = isset( $event['id'] ) ? (int) $event['id'] : 0;
		if ( $photo_id <= 0 || $event_id <= 0 ) {
			return new WP_Error( 'picpeak_bad_row', __( 'That photo record is incomplete.', 'picpeak' ) );
		}

		$already = self::existing( $photo_id );
		if ( null !== $already && 'replace' !== ( $opts['on_duplicate'] ?? 'skip' ) ) {
			return array(
				'status'        => 'skipped',
				'attachment_id' => $already,
				'title'         => get_the_title( $already ),
			);
		}

		// The caller is a browser, so nothing it says about this photo is
		// authoritative — a crafted request could otherwise choose the filename
		// the file lands under and the text written into post meta. Only the
		// ids are taken on trust (and they are checked against the gallery by
		// PicPeak itself); everything describing the file comes from PicPeak.
		$resolution = (string) ( $opts['resolution'] ?? 'original' );
		$query      = array();
		if ( '' !== $resolution && 'original' !== $resolution ) {
			$query['resolution'] = $resolution;
		}
		// Off unless asked for: this is the photographer's own site, where a
		// watermark is usually the last thing they want.
		if ( ! empty( $opts['watermark'] ) ) {
			$query['watermark'] = 'on';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = wp_tempnam( 'picpeak' );
		if ( ! $tmp ) {
			return new WP_Error( 'picpeak_tmp', __( 'WordPress could not create a temporary file for the download.', 'picpeak' ) );
		}

		$fetched = Client::to_file(
			'/events/' . $event_id . '/photos/' . $photo_id . '/download',
			$tmp,
			$query
		);
		if ( is_wp_error( $fetched ) ) {
			wp_delete_file( $tmp );
			return $fetched;
		}

		// Only image types. PicPeak also stores videos, and a media library is
		// not where a 2 GB clip belongs; more to the point, this keeps the set
		// of things sideloaded here to what the picker actually offers.
		if ( 0 !== strpos( strtolower( $fetched['content_type'] ), 'image/' ) ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'picpeak_not_an_image',
				__( 'That item is not an image, so it was not imported.', 'picpeak' )
			);
		}

		// PicPeak names the file in Content-Disposition, from the row it holds.
		// That is the authoritative name; the browser's copy is only a fallback
		// for an instance that somehow omits the header.
		$filename = self::filename_from_disposition( $fetched['content_disposition'] );
		if ( '' === $filename ) {
			$filename = self::filename_for( $photo );
		}


		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			),
			0,
			null,
			array( 'post_title' => self::title_for( $photo ) )
		);

		if ( is_wp_error( $attachment_id ) ) {
			// media_handle_sideload removes the temp file itself on success;
			// on failure it may not have reached it.
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $attachment_id;
		}

		$attachment_id = (int) $attachment_id;
		$folder        = self::tag( $attachment_id, $photo, $event, $resolution );

		// A replacement leaves the old attachment behind on purpose: it may be
		// used in published posts, and silently deleting it would break them.
		// The new one carries the id, so the old row is detached from PicPeak.
		if ( null !== $already ) {
			delete_post_meta( $already, self::META_PHOTO );
		}

		return array(
			'status'        => null !== $already ? 'replaced' : 'imported',
			'attachment_id' => $attachment_id,
			'title'         => get_the_title( $attachment_id ),
			// Reported, not swallowed: a folder plugin whose API moved would
			// otherwise look exactly like one that worked.
			'folder'        => $folder,
		);
	}

	/** Everything worth keeping about where this image came from. */
	private static function tag( int $attachment_id, array $photo, array $event, string $resolution ): string {
		update_post_meta( $attachment_id, self::META_PHOTO, (string) (int) $photo['id'] );
		update_post_meta( $attachment_id, self::META_EVENT, (string) (int) $event['id'] );
		update_post_meta( $attachment_id, self::META_INSTANCE, Settings::instance_id() );
		update_post_meta( $attachment_id, '_picpeak_gallery_name', sanitize_text_field( (string) ( $event['event_name'] ?? '' ) ) );
		update_post_meta( $attachment_id, '_picpeak_resolution', sanitize_text_field( $resolution ) );

		foreach ( array( 'category', 'rating', 'color_label' ) as $key ) {
			if ( ! empty( $photo[ $key ] ) ) {
				update_post_meta( $attachment_id, '_picpeak_' . $key, sanitize_text_field( (string) $photo[ $key ] ) );
			}
		}

		// Alt text is what a screen reader will read out, so it gets the most
		// human string available rather than a camera filename.
		$alt = (string) ( $photo['category'] ?? '' );
		if ( '' === $alt ) {
			$alt = self::title_for( $photo );
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );

		if ( ! empty( $photo['category'] ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_text_field( (string) $photo['category'] ),
				)
			);
		}

		$term_id = Taxonomy::term_for(
			(string) ( $event['slug'] ?? '' ),
			(string) ( $event['event_name'] ?? '' )
		);
		if ( null !== $term_id ) {
			wp_set_object_terms( $attachment_id, array( $term_id ), Taxonomy::NAME, false );
		}

		return Folders::place( $attachment_id, $event );
	}

	/**
	 * The filename PicPeak put in Content-Disposition.
	 *
	 * Prefers the RFC 5987 `filename*` form, which is the one that survives
	 * non-ASCII, and falls back to the quoted ASCII form. The result still goes
	 * through sanitize_file_name(), so a header claiming `../../evil.php` can
	 * only ever become a flat, extension-checked name in the uploads directory.
	 */
	private static function filename_from_disposition( string $header ): string {
		if ( '' === $header ) {
			return '';
		}

		if ( preg_match( "/filename\*\s*=\s*UTF-8''([^;]+)/i", $header, $m ) ) {
			$decoded = rawurldecode( trim( $m[1] ) );
			if ( '' !== $decoded ) {
				return sanitize_file_name( $decoded );
			}
		}

		if ( preg_match( '/filename\s*=\s*"([^"]*)"/i', $header, $m ) ) {
			return sanitize_file_name( $m[1] );
		}

		return '';
	}

	/**
	 * Fallback name, from the row the browser sent. source_filename is the
	 * camera-original name PicPeak preserves across replaces, which is what a
	 * photographer recognises; sanitize_file_name keeps it safe for the uploads
	 * directory.
	 */
	private static function filename_for( array $photo ): string {
		$name = (string) ( $photo['source_filename'] ?? '' );
		if ( '' === $name ) {
			$name = (string) ( $photo['original_filename'] ?? '' );
		}
		if ( '' === $name ) {
			$name = (string) ( $photo['filename'] ?? '' );
		}
		if ( '' === $name ) {
			$name = 'picpeak-' . (int) ( $photo['id'] ?? 0 ) . '.jpg';
		}
		return sanitize_file_name( $name );
	}

	/** The filename without its extension, which is what WordPress shows. */
	private static function title_for( array $photo ): string {
		$base = pathinfo( self::filename_for( $photo ), PATHINFO_FILENAME );
		return sanitize_text_field( '' !== $base ? $base : (string) ( $photo['id'] ?? '' ) );
	}
}
