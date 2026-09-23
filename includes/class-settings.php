<?php
/**
 * Connection settings: where the PicPeak instance is, and the token to reach it.
 *
 * The token is the whole security surface of this plugin. It is an admin-scoped
 * API token, so it is stored with autoload off, never printed back into the
 * page, and never handed to JavaScript — the browser talks to the WordPress
 * REST proxy in class-rest.php instead, and only WordPress talks to PicPeak.
 */

namespace PicPeak;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION   = 'picpeak_settings';
	const CAP      = 'manage_options';
	const PAGE     = 'picpeak-settings';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Stored settings, with the wp-config.php constants taking precedence.
	 *
	 * A site that would rather keep secrets out of the database can define
	 * PICPEAK_API_TOKEN (and PICPEAK_BASE_URL) instead; the field then shows as
	 * locked rather than silently ignoring what the admin types.
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'base_url'       => defined( 'PICPEAK_BASE_URL' ) ? (string) PICPEAK_BASE_URL : (string) ( $stored['base_url'] ?? '' ),
			'token'          => defined( 'PICPEAK_API_TOKEN' ) ? (string) PICPEAK_API_TOKEN : (string) ( $stored['token'] ?? '' ),
			'base_url_fixed' => defined( 'PICPEAK_BASE_URL' ),
			'token_fixed'    => defined( 'PICPEAK_API_TOKEN' ),
		);
	}

	/** True once there is enough to attempt a call. */
	public static function is_configured(): bool {
		$s = self::get();
		return '' !== $s['base_url'] && '' !== $s['token'];
	}

	/**
	 * Identifies the instance a photo was imported from, so two PicPeak
	 * installs cannot collide on row ids in the attachment meta. Hashed rather
	 * than stored as a URL: it only ever has to be compared.
	 */
	public static function instance_id(): string {
		$base = self::get()['base_url'];
		return '' === $base ? '' : substr( hash( 'sha256', untrailingslashit( $base ) ), 0, 16 );
	}

	public static function add_page(): void {
		add_options_page(
			__( 'PicPeak', 'picpeak' ),
			__( 'PicPeak', 'picpeak' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register(): void {
		register_setting(
			self::OPTION,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * A blank token field means "leave the stored one alone", not "clear it" —
	 * the field is rendered empty on every load because the token is never
	 * echoed back, so treating empty as a delete would wipe it on any save.
	 * Clearing is deliberate, via the checkbox.
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = get_option( self::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();

		// A disabled field is not submitted at all, so when PICPEAK_BASE_URL is
		// defined the stored value has to be carried forward rather than read
		// back as an empty string and saved over. It matters the day the
		// constant is removed again.
		if ( defined( 'PICPEAK_BASE_URL' ) ) {
			$base_url = (string) ( $existing['base_url'] ?? '' );
		} else {
			$base_url = isset( $input['base_url'] ) ? esc_url_raw( trim( (string) $input['base_url'] ) ) : '';
			$base_url = self::validate_url( $base_url, (string) ( $existing['base_url'] ?? '' ) );
		}
		$out = array( 'base_url' => untrailingslashit( $base_url ) );

		if ( ! empty( $input['clear_token'] ) ) {
			$out['token'] = '';
			return $out;
		}

		$typed        = isset( $input['token'] ) ? trim( (string) $input['token'] ) : '';
		$out['token'] = '' !== $typed ? sanitize_text_field( $typed ) : (string) ( $existing['token'] ?? '' );

		return $out;
	}

	/**
	 * Where the token is allowed to be sent.
	 *
	 * This matters more than an ordinary URL field: every request carries
	 * `Authorization: Bearer <token>`, so whoever sets this address decides who
	 * receives the token. A bad value is rejected back to the previous one
	 * rather than saved, because saving it would send the credential on the
	 * next request.
	 *
	 * Embedded credentials are stripped: `https://user:pass@host` is a shape
	 * that confuses both people and parsers, and nothing here needs it.
	 */
	private static function validate_url( string $candidate, string $fallback ): string {
		if ( '' === $candidate ) {
			return '';
		}

		$parts = wp_parse_url( $candidate );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			add_settings_error(
				self::OPTION,
				'picpeak_bad_url',
				__( 'That does not look like an http or https address, so it was not saved.', 'picpeak' )
			);
			return $fallback;
		}

		$clean = strtolower( $parts['scheme'] ) . '://' . $parts['host']
			. ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' )
			. ( isset( $parts['path'] ) ? $parts['path'] : '' );

		// wp_http_validate_url refuses private and loopback addresses. A
		// self-hosted PicPeak on a LAN is a legitimate setup, so this can be
		// opted out of — but it fails closed, because the default case is a
		// public instance and the cost of getting it wrong is the token.
		if ( self::reject_unsafe_urls() && ! wp_http_validate_url( $clean ) ) {
			add_settings_error(
				self::OPTION,
				'picpeak_unsafe_url',
				__( 'That address is a private or loopback host. If your PicPeak really is on a local network, allow it with the picpeak_reject_unsafe_urls filter.', 'picpeak' )
			);
			return $fallback;
		}

		return $clean;
	}

	/**
	 * Whether private and loopback hosts are refused. Filterable for a
	 * self-hosted instance on a LAN:
	 *
	 *     add_filter( 'picpeak_reject_unsafe_urls', '__return_false' );
	 */
	public static function reject_unsafe_urls(): bool {
		return (bool) apply_filters( 'picpeak_reject_unsafe_urls', true );
	}

	/** Enough of the token to recognise which one is stored, and no more. */
	private static function token_hint( string $token ): string {
		if ( '' === $token ) {
			return '';
		}
		// The prefix is a constant, so it reveals nothing; the last four
		// distinguish one token from another without narrowing a guess.
		return 'pp_live_…' . substr( $token, -4 );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'picpeak' ) );
		}
		$s = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PicPeak', 'picpeak' ); ?></h1>
			<p><?php esc_html_e( 'Connect this site to a PicPeak instance, then import gallery images from Media → Import from PicPeak.', 'picpeak' ); ?></p>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="picpeak-base-url"><?php esc_html_e( 'PicPeak URL', 'picpeak' ); ?></label>
						</th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION ); ?>[base_url]" id="picpeak-base-url"
								type="url" class="regular-text" placeholder="https://gallery.example.com"
								value="<?php echo esc_attr( $s['base_url'] ); ?>"
								<?php disabled( $s['base_url_fixed'] ); ?> />
							<?php if ( $s['base_url_fixed'] ) : ?>
								<p class="description"><?php esc_html_e( 'Set by PICPEAK_BASE_URL in wp-config.php.', 'picpeak' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="picpeak-token"><?php esc_html_e( 'API token', 'picpeak' ); ?></label>
						</th>
						<td>
							<?php if ( $s['token_fixed'] ) : ?>
								<code><?php echo esc_html( self::token_hint( $s['token'] ) ); ?></code>
								<p class="description"><?php esc_html_e( 'Set by PICPEAK_API_TOKEN in wp-config.php.', 'picpeak' ); ?></p>
							<?php else : ?>
								<input name="<?php echo esc_attr( self::OPTION ); ?>[token]" id="picpeak-token"
									type="password" class="regular-text" autocomplete="off"
									placeholder="pp_live_…" value="" />
								<?php if ( '' !== $s['token'] ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: a masked fragment of the stored token. */
											esc_html__( 'A token is stored (%s). Leave this blank to keep it.', 'picpeak' ),
											'<code>' . esc_html( self::token_hint( $s['token'] ) ) . '</code>'
										);
										?>
									</p>
									<p>
										<label>
											<input type="checkbox" value="1"
												name="<?php echo esc_attr( self::OPTION ); ?>[clear_token]" />
											<?php esc_html_e( 'Remove the stored token', 'picpeak' ); ?>
										</label>
									</p>
								<?php else : ?>
									<p class="description">
										<?php esc_html_e( 'Create one in PicPeak under Settings → Integrations. It needs the read scope, and its owner needs the photo view and download permissions.', 'picpeak' ); ?>
									</p>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Connection', 'picpeak' ); ?></h2>
			<?php if ( ! self::is_configured() ) : ?>
				<p><?php esc_html_e( 'Enter a URL and a token, then save, to test the connection.', 'picpeak' ); ?></p>
			<?php else : ?>
				<p>
					<button type="button" class="button" id="picpeak-test"
						data-endpoint="<?php echo esc_url( rest_url( Rest::NS . '/ping' ) ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">
						<?php esc_html_e( 'Test connection', 'picpeak' ); ?>
					</button>
					<span id="picpeak-test-result" role="status"></span>
				</p>
				<script>
				( function () {
					var b = document.getElementById( 'picpeak-test' );
					var out = document.getElementById( 'picpeak-test-result' );
					if ( ! b ) { return; }
					b.addEventListener( 'click', function () {
						b.disabled = true;
						out.textContent = <?php echo wp_json_encode( __( 'Testing…', 'picpeak' ) ); ?>;
						fetch( b.dataset.endpoint, { headers: { 'X-WP-Nonce': b.dataset.nonce } } )
							.then( function ( r ) { return r.json().then( function ( j ) { return { http: r.ok, j: j }; } ); } )
							.then( function ( res ) {
								var body = res.j || {};
								// /ping answers 200 even when PicPeak refused, so that the
								// reason can be shown. Colour on the payload's own ok flag —
								// reading r.ok here would paint every failure as a success.
								var good = res.http && true === body.ok;
								out.textContent = body.message || '';
								out.style.color = good ? '' : '#b32d2e';
							} )
							.catch( function () {
								out.textContent = <?php echo wp_json_encode( __( 'The request failed before it reached PicPeak.', 'picpeak' ) ); ?>;
								out.style.color = '#b32d2e';
							} )
							.finally( function () { b.disabled = false; } );
					} );
				}() );
				</script>
			<?php endif; ?>
		</div>
		<?php
	}
}
