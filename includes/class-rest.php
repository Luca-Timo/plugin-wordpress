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
