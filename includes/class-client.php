<?php
/**
 * The only thing in this plugin that talks to PicPeak.
 *
 * Everything goes out with the bearer token and comes back either as decoded
 * JSON or as a WP_Error carrying a message an admin can act on. PicPeak's own
 * error codes are mapped here rather than at the call sites, so "your token
 * lacks the read scope" does not surface as a generic failure three layers up.
 */

namespace PicPeak;

defined( 'ABSPATH' ) || exit;

use WP_Error;

class Client {

	/** Long enough for a slow instance, short enough not to hold a request open. */
	const TIMEOUT = 20;

	/**
	 * Ceiling on a single downloaded file.
	 *
	 * Originals are routinely tens of megabytes and the instance is remote, so
	 * without a bound one import can exhaust PHP's memory_limit and take the
	 * request down with it. Any user who can reach the import endpoint can pick
	 * the photo, so this is not only an accident case.
	 */
	const MAX_DOWNLOAD_BYTES = 134217728; // 128 MiB

	/**
	 * PicPeak's documented error codes. Anything not listed falls back to the
	 * server's own message, which the v1 routes write for humans.
	 */
	private static function explain( string $code, int $status ): string {
		switch ( $code ) {
			case 'NO_TOKEN':
			case 'INVALID_TOKEN':
				return __( 'PicPeak did not accept the token. Check it was copied whole, including the pp_live_ prefix.', 'picpeak' );
			case 'TOKEN_REVOKED':
				return __( 'This token has been revoked in PicPeak. Create a new one under Settings → Integrations.', 'picpeak' );
			case 'TOKEN_EXPIRED':
				return __( 'This token has expired. Create a new one under Settings → Integrations.', 'picpeak' );
			case 'OWNER_INACTIVE':
				return __( 'The PicPeak account that created this token is no longer active.', 'picpeak' );
			case 'MUST_CHANGE_PASSWORD':
				return __( 'The PicPeak account that created this token must change its password before the API will answer.', 'picpeak' );
			case 'INSUFFICIENT_SCOPE':
				return __( 'This token is missing the read scope. Create one with read access.', 'picpeak' );
			case 'EVENT_ARCHIVED':
				return __( 'That gallery is archived, so its photos are only inside its archive. Restore it in PicPeak to import from it.', 'picpeak' );
			case 'PHOTO_FILE_MISSING':
				return __( 'PicPeak has a record of that photo but its file is missing from storage.', 'picpeak' );
			case 'PHOTO_PROCESSING':
				return __( 'That photo is still being processed. Try again shortly.', 'picpeak' );
			case 'PREVIEW_UNAVAILABLE':
				return __( 'No preview exists for that item. Videos never have one.', 'picpeak' );
		}

		if ( 403 === $status ) {
			return __( 'PicPeak refused the request. The token owner may lack permission for this gallery.', 'picpeak' );
		}
		if ( 404 === $status ) {
			return __( 'PicPeak has nothing at that address. Check the URL points at the instance root.', 'picpeak' );
		}
		return '';
	}

	/** The base for every call, or a WP_Error when nothing is configured yet. */
	private static function base() {
		$s = Settings::get();
		if ( '' === $s['base_url'] || '' === $s['token'] ) {
			return new WP_Error(
				'picpeak_not_configured',
				__( 'PicPeak is not connected yet. Add the instance URL and an API token in Settings → PicPeak.', 'picpeak' )
			);
		}
		return $s;
	}

	/**
	 * Builds an upstream URL with the query string encoded by hand.
	 *
	 * NOT add_query_arg(): it serialises through build_query(), which calls
	 * _http_build_query() with $urlencode = false, so values go on the wire
	 * verbatim. sanitize_text_field() keeps `&` and `=`, so a forwarded value
	 * of `1&admin=1` would become two parameters — walking straight past the
	 * allowlist in Rest::photos and adding parameters of its own to a request
	 * carrying the admin token. Encoding each value is what makes that
	 * allowlist mean anything.
	 */
	private static function url( array $s, string $path, array $query = array() ): string {
		$url = untrailingslashit( $s['base_url'] ) . '/api/v1' . $path;

		$pairs = array();
		foreach ( $query as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		return empty( $pairs ) ? $url : $url . '?' . implode( '&', $pairs );
	}

	private static function headers( array $s ): array {
		return array(
			'Authorization' => 'Bearer ' . $s['token'],
			'Accept'        => 'application/json',
		);
	}

	/**
	 * A GET returning decoded JSON.
	 *
	 * 429 is surfaced with its Retry-After rather than swallowed: a bulk import
	 * has to back off and resume, and the caller cannot decide that without the
	 * delay. PicPeak's general API budget is per client IP, so a large import
	 * is expected to meet it.
	 */
	public static function get_json( string $path, array $query = array() ) {
		$s = self::base();
		if ( is_wp_error( $s ) ) {
			return $s;
		}

		$response = wp_remote_get(
			self::url( $s, $path, $query ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => self::headers( $s ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'picpeak_unreachable',
				sprintf(
					/* translators: %s: the underlying transport error. */
					__( 'Could not reach PicPeak: %s', 'picpeak' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 429 === $status ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return new WP_Error(
				'picpeak_rate_limited',
				__( 'PicPeak is rate limiting this site. The import can continue after a short wait.', 'picpeak' ),
				array(
					'status'      => 429,
					'retry_after' => $retry > 0 ? $retry : 30,
				)
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			$code       = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : '';
			$explained  = self::explain( $code, $status );
			$from_picpeak = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : '';

			return new WP_Error(
				'picpeak_http_' . $status,
				$explained !== '' ? $explained : (
					$from_picpeak !== ''
						? $from_picpeak
						: sprintf(
							/* translators: %d: an HTTP status code. */
							__( 'PicPeak answered with status %d.', 'picpeak' ),
							$status
						)
				),
				array( 'status' => $status, 'picpeak_code' => $code )
			);
		}

		if ( null === $body ) {
			return new WP_Error(
				'picpeak_bad_json',
				__( 'PicPeak answered with something that was not JSON. Check the URL points at the instance root and not at a login page.', 'picpeak' )
			);
		}

		return $body;
	}

	/**
	 * A GET returning raw bytes, for previews.
	 *
	 * The body is held in memory, so this is only for the preview tiers, which
	 * are web-sized by construction. A full-size original goes through
	 * to_file() instead, which never buffers it.
	 */
	public static function get_binary( string $path, array $query = array() ) {
		$s = self::base();
		if ( is_wp_error( $s ) ) {
			return $s;
		}

		$response = wp_remote_get(
			self::url( $s, $path, $query ),
			array(
				'timeout'             => self::TIMEOUT,
				'headers'             => self::headers( $s ),
				// Every request carries the token, so the same host policy that
				// guarded the setting guards the request.
				'reject_unsafe_urls'  => Settings::reject_unsafe_urls(),
				// A preview tier is at most a couple of megabytes; anything
				// larger means this is not the endpoint we think it is.
				'limit_response_size' => 8388608,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'picpeak_unreachable', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$code = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : '';
			$msg  = self::explain( $code, $status );
			return new WP_Error(
				'picpeak_http_' . $status,
				'' !== $msg ? $msg : sprintf(
					/* translators: %d: an HTTP status code. */
					__( 'PicPeak answered with status %d.', 'picpeak' ),
					$status
				),
				array( 'status' => $status, 'picpeak_code' => $code )
			);
		}

		return array(
			'body'         => wp_remote_retrieve_body( $response ),
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}

	/**
	 * A GET streamed straight to a local file.
	 *
	 * wp_remote_get with 'stream' writes the body as it arrives instead of
	 * assembling it in memory, which is what makes importing a 40 MB original
	 * survivable, and limit_response_size aborts a response that runs past the
	 * ceiling rather than filling the disk.
	 *
	 * Returns the response headers on success; the bytes are already on disk.
	 */
	public static function to_file( string $path, string $destination, array $query = array() ) {
		$s = self::base();
		if ( is_wp_error( $s ) ) {
			return $s;
		}

		$response = wp_remote_get(
			self::url( $s, $path, $query ),
			array(
				'timeout'             => self::TIMEOUT,
				'headers'             => self::headers( $s ),
				// Every request carries the token, so the same host policy that
				// guarded the setting guards the request.
				'reject_unsafe_urls'  => Settings::reject_unsafe_urls(),
				'stream'              => true,
				'filename'            => $destination,
				'limit_response_size' => self::MAX_DOWNLOAD_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'picpeak_unreachable', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			// On a non-2xx, the error body was streamed to the file rather than
			// returned, so read the little of it that matters from disk.
			$body = is_readable( $destination )
				? json_decode( (string) file_get_contents( $destination ), true ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
				: null;
			$code = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : '';
			$msg  = self::explain( $code, $status );

			return new WP_Error(
				'picpeak_http_' . $status,
				'' !== $msg ? $msg : sprintf(
					/* translators: %d: an HTTP status code. */
					__( 'PicPeak answered with status %d.', 'picpeak' ),
					$status
				),
				array( 'status' => $status, 'picpeak_code' => $code )
			);
		}

		return array(
			'content_type'        => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'content_disposition' => (string) wp_remote_retrieve_header( $response, 'content-disposition' ),
		);
	}

	/** Galleries the token's owner can see. */
	public static function events( int $page = 1, int $limit = 25 ) {
		return self::get_json( '/events', array( 'page' => $page, 'limit' => $limit ) );
	}

	/** One gallery's photos, passing PicPeak's own proofing filters straight through. */
	public static function photos( int $event_id, array $query = array() ) {
		return self::get_json( '/events/' . $event_id . '/photos', $query );
	}

	/** One gallery, read from PicPeak rather than taken on the caller's word. */
	public static function event( int $event_id ) {
		return self::get_json( '/events/' . $event_id );
	}
}
