<?php
/**
 * OAuth consent screen at wp-login.php?action=viagent_authorize.
 *
 * Runs inside wp-login.php so it looks like WordPress, works with any
 * permalink setting, and gets the login page's no-cache and anti-framing headers.
 *
 * @package Viagent
 */

namespace Viagent\Auth\OAuth;

use Viagent\Security\Policy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Consent screen.
 */
class Authorize {

	const NONCE = 'viagent_authorize';

	/**
	 * Parameters carried from the authorization request to the consent form.
	 */
	const PARAMS = array( 'response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource' );

	/**
	 * Registers hooks.
	 */
	public static function init() {
		if ( OAuth::enabled() ) {
			add_action( 'login_form_viagent_authorize', array( self::class, 'handle' ) );
		}
	}

	/**
	 * Reads authorization parameters from the query string or the consent form.
	 *
	 * @return array<string,string>
	 */
	private static function params() {
		$params = array();
		foreach ( self::PARAMS as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- OAuth values must stay byte-exact; each is validated strictly in validate(), and the POST is nonce-checked in handle().
			$value           = isset( $_REQUEST[ $name ] ) ? wp_unslash( $_REQUEST[ $name ] ) : '';
			$params[ $name ] = is_string( $value ) ? trim( $value ) : '';
		}
		return $params;
	}

	/**
	 * Validates the request. Errors that happen before the redirect URI is
	 * trusted are shown on screen; later ones are sent back to the app.
	 *
	 * @param array $params Params.
	 * @return array{client:object}|WP_Error Error data may contain `redirect` => true.
	 */
	private static function validate( array $params ) {
		$client = OAuth::get_client( $params['client_id'] );
		if ( ! $client ) {
			return new WP_Error( 'invalid_client', __( 'This app is not registered with your site. Please start the connection again from your AI app.', 'viagent' ) );
		}
		if ( ! in_array( $params['redirect_uri'], $client->redirect_uris, true ) ) {
			return new WP_Error( 'invalid_redirect', __( 'The app sent an unexpected return address, so the request was stopped for your safety.', 'viagent' ) );
		}

		if ( 'code' !== $params['response_type'] ) {
			return new WP_Error( 'unsupported_response_type', 'Only response_type=code is supported.', array( 'redirect' => true ) );
		}
		if ( 'S256' !== $params['code_challenge_method'] || ! preg_match( '/^[A-Za-z0-9\-_]{43}$/', $params['code_challenge'] ) ) {
			return new WP_Error( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.', array( 'redirect' => true ) );
		}
		if ( '' !== $params['resource'] && ! OAuth::is_our_resource( $params['resource'] ) ) {
			return new WP_Error( 'invalid_target', 'Unknown resource.', array( 'redirect' => true ) );
		}

		return array( 'client' => $client );
	}

	/**
	 * Sends the user back to the app with query parameters.
	 *
	 * @param string $redirect_uri Registered redirect URI.
	 * @param array  $args         Query args.
	 */
	private static function redirect_back( $redirect_uri, array $args ) {
		$args['iss'] = OAuth::issuer();
		$query       = http_build_query( array_filter( $args, 'strlen' ), '', '&', PHP_QUERY_RFC3986 );
		$url         = $redirect_uri . ( false === strpos( $redirect_uri, '?' ) ? '?' : '&' ) . $query;

		// Not wp_redirect(): it rewrites some characters in app-specific schemes.
		header( 'Location: ' . $url, true, 302 );
		exit;
	}

	/**
	 * Handles the consent screen.
	 */
	public static function handle() {
		$params = self::params();
		$valid  = self::validate( $params );

		if ( is_wp_error( $valid ) ) {
			$data = $valid->get_error_data();
			if ( ! empty( $data['redirect'] ) ) {
				self::redirect_back(
					$params['redirect_uri'],
					array(
						'error'             => $valid->get_error_code(),
						'error_description' => $valid->get_error_message(),
						'state'             => $params['state'],
					)
				);
			}
			self::render_error( $valid->get_error_message() );
		}

		$authorize_url = add_query_arg( array_map( 'rawurlencode', array_filter( $params, 'strlen' ) ), OAuth::authorization_endpoint() );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( $authorize_url ) );
			exit;
		}

		if ( Policy::is_paused() ) {
			self::render_error( __( 'AI access to this site is paused. Resume it in Viagent → Settings, then try again.', 'viagent' ) );
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			check_admin_referer( self::NONCE );
			self::decide( $params );
		}

		self::render_consent( $valid['client'], $params );
	}

	/**
	 * Processes Allow / Deny.
	 *
	 * @param array $params Params.
	 */
	private static function decide( array $params ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle().
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$choice   = isset( $_POST['access'] ) ? sanitize_key( wp_unslash( $_POST['access'] ) ) : '';
		// phpcs:enable

		if ( 'allow' !== $decision ) {
			self::redirect_back(
				$params['redirect_uri'],
				array(
					'error'             => 'access_denied',
					'error_description' => 'The user denied access.',
					'state'             => $params['state'],
				)
			);
		}

		$options = OAuth::consent_options();
		if ( ! isset( $options[ $choice ] ) ) {
			self::render_error( __( 'Please choose what the app may do.', 'viagent' ) );
		}

		$code = OAuth::issue_code(
			array(
				'client_id'      => $params['client_id'],
				'user_id'        => get_current_user_id(),
				'redirect_uri'   => $params['redirect_uri'],
				'code_challenge' => $params['code_challenge'],
				'access_level'   => $options[ $choice ]['level'],
				'draft_only'     => $options[ $choice ]['draft_only'],
			)
		);

		self::redirect_back(
			$params['redirect_uri'],
			array(
				'code'  => $code,
				'state' => $params['state'],
			)
		);
	}

	/**
	 * Where the app will send the user back, for display.
	 *
	 * @param string $redirect_uri Redirect URI.
	 * @return string
	 */
	private static function destination( $redirect_uri ) {
		$parts = wp_parse_url( $redirect_uri );
		if ( in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return $parts['host'] ?? $redirect_uri;
		}
		/* translators: %s: URL scheme such as "cursor" */
		return sprintf( __( 'the %s app on this computer', 'viagent' ), $parts['scheme'] ?? '' );
	}

	/**
	 * Renders the consent form.
	 *
	 * @param object $client Client.
	 * @param array  $params Params.
	 */
	private static function render_consent( $client, array $params ) {
		$options = OAuth::consent_options();
		$default = isset( $options['drafts'] ) ? 'drafts' : 'read';
		$user    = wp_get_current_user();

		self::enqueue_styles();
		login_header( __( 'Connect an AI app', 'viagent' ) );
		?>
		<form class="viagent-consent" method="post" action="<?php echo esc_url( OAuth::authorization_endpoint() ); ?>">
			<h2>
				<?php
				printf(
					/* translators: 1: app name, 2: site name */
					esc_html__( '%1$s wants to connect to %2$s', 'viagent' ),
					'<strong>' . esc_html( $client->client_name ) . '</strong>',
					'<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
				);
				?>
			</h2>
			<p class="viagent-consent__meta">
				<?php
				printf(
					/* translators: 1: user name, 2: destination host */
					esc_html__( 'It will act as %1$s. After you approve, you’ll return to %2$s.', 'viagent' ),
					'<strong>' . esc_html( $user->display_name ) . '</strong>',
					'<strong>' . esc_html( self::destination( $params['redirect_uri'] ) ) . '</strong>'
				);
				?>
			</p>

			<fieldset>
				<legend><?php esc_html_e( 'What may it do?', 'viagent' ); ?></legend>
				<?php foreach ( $options as $key => $option ) : ?>
					<label class="viagent-consent__option">
						<input type="radio" name="access" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $default ); ?> />
						<span>
							<strong><?php echo esc_html( $option['label'] ); ?></strong>
							<?php echo esc_html( $option['description'] ); ?>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<p class="viagent-consent__note">
				<?php esc_html_e( 'Only approve apps you trust. You can see everything it does and disconnect it any time in Viagent.', 'viagent' ); ?>
			</p>

			<?php foreach ( $params as $name => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
			<?php endforeach; ?>
			<?php wp_nonce_field( self::NONCE ); ?>

			<p class="viagent-consent__actions">
				<button type="submit" name="decision" value="deny" class="button button-large"><?php esc_html_e( 'Cancel', 'viagent' ); ?></button>
				<button type="submit" name="decision" value="allow" class="button button-primary button-large"><?php esc_html_e( 'Approve', 'viagent' ); ?></button>
			</p>
		</form>
		<?php
		login_footer();
		exit;
	}

	/**
	 * Renders an error page.
	 *
	 * @param string $message Message.
	 */
	private static function render_error( $message ) {
		self::enqueue_styles();
		login_header( __( 'Connect an AI app', 'viagent' ) );
		echo '<div class="viagent-consent"><h2>' . esc_html__( 'We couldn’t connect this app', 'viagent' ) . '</h2><p>' . esc_html( $message ) . '</p></div>';
		login_footer();
		exit;
	}

	/**
	 * Enqueues the consent box styles; login_header() prints them.
	 */
	private static function enqueue_styles() {
		add_action(
			'login_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'viagent-consent', VIAGENT_URL . 'assets/css/consent.css', array(), VIAGENT_VERSION );
			}
		);
	}
}
