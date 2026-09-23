<?php
/**
 * The browser's only way to reach PicPeak.
 *
 * The admin token is admin-scoped on the PicPeak side, so it must never be
 * handed to a page: anything running in the admin — another plugin, an
 * extension, an XSS — would inherit it. So the picker calls WordPress, and
 * WordPress calls PicPeak with the token attached server side.
 *
 * Every route is gated on upload_files (the capability that actually
 * corresponds to putting things in the media library) plus the REST nonce, so
 * a logged-in subscriber cannot use this site as an unauthenticated proxy into
 * someone's private galleries.
 */

namespace PicPeak;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Rest {

	const NS  = 'picpeak/v1';
	const CAP = 'upload_files';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function may( WP_REST_Request $request ) {
		if ( current_user_can( self::CAP ) ) {
			return true;
		}
		return new WP_Error(
			'picpeak_forbidden',
			__( 'You do not have permission to import media.', 'picpeak' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	public static function routes(): void {
		register_rest_route(
			self::NS,
			'/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'may' ),
				'callback'            => array( __CLASS__, 'ping' ),
			)
		);

		register_rest_route(
			self::NS,
			'/events',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'may' ),
				'callback'            => array( __CLASS__, 'events' ),
				'args'                => array(
					'page'  => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 25,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/preview/(?P<photo>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'may' ),
				'callback'            => array( __CLASS__, 'preview' ),
			)
		);

		register_rest_route(
			self::NS,
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'may' ),
				'callback'            => array( __CLASS__, 'import' ),
			)
		);

		register_rest_route(
			self::NS,
			'/events/(?P<id>\d+)/photos',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'may' ),
				'callback'            => array( __CLASS__, 'photos' ),
			)
		);
	}

	/**
	 * Answers what the settings page needs to say: did it connect, as whom, and
	 * is there anything to import. The count comes from the pagination block
	 * rather than the page length, so "0 galleries" reads as a real answer
	 * instead of an empty first page.
	 */
	public static function ping() {
		$result = Client::events( 1, 1 );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'message' => $result->get_error_message() ),
				200
			);
		}

		$total = (int) ( $result['pagination']['total'] ?? 0 );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'total'   => $total,
				'message' => $total > 0
					? sprintf(
						/* translators: %d: how many galleries the token can see. */
						_n( 'Connected. %d gallery available.', 'Connected. %d galleries available.', $total, 'picpeak' ),
						$total
					)
					: __( 'Connected, but this token cannot see any galleries yet.', 'picpeak' ),
			),
			200
		);
	}

	/** A WP_Error from the client, given an HTTP status the browser can read. */
	private static function fail( WP_Error $error ): WP_Error {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502;
		// 5xx from PicPeak is still a bad gateway from this site's point of view.
		if ( $status >= 500 ) {
			$status = 502;
		}
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), array( 'status' => $status ) );
	}

	public static function events( WP_REST_Request $request ) {
		$result = Client::events( (int) $request['page'], min( 100, max( 1, (int) $request['limit'] ) ) );
		return is_wp_error( $result ) ? self::fail( $result ) : new WP_REST_Response( $result, 200 );
	}

	/**
	 * The photo list, with PicPeak's proofing filters passed through unchanged.
	 * Only the parameters the v1 API documents are forwarded — an allowlist, so
	 * this cannot become a way to reach arbitrary query strings on the instance.
	 */
	/**
	 * Proxies one preview image.
	 *
	 * The grid cannot fetch these itself — it has no token — so the bytes come
	 * through WordPress. Cached privately: a preview is exactly as
	 * access-controlled as the gallery it belongs to and must not be held by a
	 * shared cache.
	 *
	 * PicPeak answers 404 PREVIEW_UNAVAILABLE for videos and anything with no
	 * preview tier, which the grid renders as a placeholder rather than an
	 * error, so this passes the status through untouched.
	 */
	public static function preview( WP_REST_Request $request ) {
		$width = (int) $request->get_param( 'w' );
		$query = in_array( $width, array( 640, 1280, 1920 ), true ) ? array( 'w' => $width ) : array();

		$result = Client::get_binary(
			'/events/' . (int) $request['id'] . '/photos/' . (int) $request['photo'] . '/preview',
			$query
		);

		if ( is_wp_error( $result ) ) {
			return self::fail( $result );
		}

		$type = $result['content_type'];
		// Only the two types PicPeak generates; never echo back a type the
		// upstream response chose for us.
		if ( ! in_array( $type, array( 'image/jpeg', 'image/webp' ), true ) ) {
			$type = 'image/jpeg';
		}

		header( 'Content-Type: ' . $type );
		header( 'Content-Length: ' . strlen( $result['body'] ) );
		header( 'Cache-Control: private, max-age=3600' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image body
		exit;
	}

	/**
	 * Imports one batch.
	 *
	 * Batched rather than whole-gallery because a single request importing
	 * hundreds of photos reliably dies on max_execution_time. The browser
	 * drives the loop and can show progress, stop, and back off on a 429.
	 */
	public static function import( WP_REST_Request $request ) {
		$event  = (array) $request->get_param( 'event' );
		$photos = (array) $request->get_param( 'photos' );
		$opts   = (array) $request->get_param( 'options' );

		if ( empty( $event['id'] ) || empty( $photos ) ) {
			return new WP_Error(
				'picpeak_bad_request',
				__( 'The import request was missing a gallery or a selection.', 'picpeak' ),
				array( 'status' => 400 )
			);
		}

		// A cap on top of whatever the browser asks for: this endpoint is
		// reachable by any upload_files user, and the batch size decides how
		// long one request runs.
		$photos = array_slice( $photos, 0, 10 );

		$event = array(
			'id'         => (int) $event['id'],
			'slug'       => sanitize_title( (string) ( $event['slug'] ?? '' ) ),
			'event_name' => sanitize_text_field( (string) ( $event['event_name'] ?? '' ) ),
		);

		$opts = array(
			'resolution'   => sanitize_text_field( (string) ( $opts['resolution'] ?? 'original' ) ),
			'watermark'    => ! empty( $opts['watermark'] ),
			'on_duplicate' => 'replace' === ( $opts['on_duplicate'] ?? 'skip' ) ? 'replace' : 'skip',
		);

		$results = array();
		foreach ( $photos as $photo ) {
			$photo  = (array) $photo;
			$result = Importer::import( $photo, $event, $opts );

			if ( is_wp_error( $result ) ) {
				$data = $result->get_error_data();
				// A rate limit stops the whole run: continuing would just
				// collect more of the same. The browser waits and resumes.
				if ( 'picpeak_rate_limited' === $result->get_error_code() ) {
					return new WP_REST_Response(
						array(
							'results'     => $results,
							'rate_limited' => true,
							'retry_after' => (int) ( $data['retry_after'] ?? 30 ),
						),
						200
					);
				}
				$results[] = array(
					'photo_id' => (int) ( $photo['id'] ?? 0 ),
					'status'   => 'failed',
					'message'  => $result->get_error_message(),
				);
				continue;
			}

			$results[] = array(
				'photo_id'      => (int) ( $photo['id'] ?? 0 ),
				'status'        => $result['status'],
				'attachment_id' => $result['attachment_id'],
				'title'         => $result['title'],
			);
		}

		return new WP_REST_Response( array( 'results' => $results ), 200 );
	}

	public static function photos( WP_REST_Request $request ) {
		$allowed = array(
			'page',
			'limit',
			'marked_only',
			'mark_source',
			'color_labels',
			'my_color_labels',
			'min_rating',
			'my_min_rating',
			'logic',
		);

		$query = array();
		foreach ( $allowed as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value && '' !== $value ) {
				$query[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$result = Client::photos( (int) $request['id'], $query );
		return is_wp_error( $result ) ? self::fail( $result ) : new WP_REST_Response( $result, 200 );
	}
}
