<?php
/**
 * Settings page: connection and tracking.
 *
 * Renders the admin UI, handles the save action (nonce + capability + sanitize),
 * and validates the connection with a live test call before saving the key.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Admin;

use Tagbridge\Core\Connection\Host;
use Tagbridge\Platform\Connection\Validator;
use Tagbridge\Platform\Listeners\WooEvents;
use Tagbridge\Platform\Settings\Options;

/**
 * The plugin's top-level settings screen.
 */
final class SettingsPage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const SLUG = 'tagbridge';

	/**
	 * The admin-post action name used to save settings.
	 *
	 * @var string
	 */
	const ACTION = 'tagbridge_save_settings';

	/**
	 * Nonce action/name.
	 *
	 * @var string
	 */
	const NONCE = 'tagbridge_save_settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the top-level admin menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Tagbridge', 'tagbridge' ),
			__( 'Tagbridge', 'tagbridge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-chart-bar',
			58
		);
	}

	/**
	 * The admin page hook suffix for our screen.
	 *
	 * @return string
	 */
	public static function hook_suffix() {
		return 'toplevel_page_' . self::SLUG;
	}

	/**
	 * Enqueue page assets only on our settings screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( self::hook_suffix() !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'tagbridge-admin',
			TAGBRIDGE_URL . 'assets/css/admin.css',
			array(),
			TAGBRIDGE_VERSION
		);

		wp_enqueue_script(
			'tagbridge-admin',
			TAGBRIDGE_URL . 'assets/js/admin.js',
			array(),
			TAGBRIDGE_VERSION,
			true
		);
	}

	/**
	 * Capability check helper.
	 *
	 * @return bool
	 */
	private function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle the settings save: validate then persist.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tagbridge' ), 403 );
		}

		check_admin_referer( self::NONCE, 'tagbridge_nonce' );

		// Nonce verified above; the raw array is sanitized field-by-field in Options::sanitize().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw       = isset( $_POST['tagbridge'] ) && is_array( $_POST['tagbridge'] ) ? wp_unslash( $_POST['tagbridge'] ) : array();
		$sanitized = Options::sanitize( $raw );

		$key  = (string) $sanitized['project_api_key'];
		$host = Host::resolve( $sanitized['region'], $sanitized['custom_host'] );

		// Validate the connection with a live call before saving the key.
		if ( '' !== $key ) {
			$result = Validator::validate( $key, $host );
			if ( ! $result['ok'] ) {
				$this->flash( 'error', $result['message'] );
				$this->redirect_back();
				return;
			}
		}

		Options::save( $sanitized );

		$this->flash(
			'success',
			'' === $key
				? __( 'Settings saved.', 'tagbridge' )
				: __( 'Connected and saved. PostHog will start receiving events on your site.', 'tagbridge' )
		);
		$this->redirect_back();
	}

	/**
	 * Store a one-time flash message for the current user.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Human-readable message.
	 * @return void
	 */
	private function flash( $type, $message ) {
		set_transient(
			$this->flash_key(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Read and clear the flash message.
	 *
	 * @return array{type:string,message:string}|null
	 */
	private function read_flash() {
		$flash = get_transient( $this->flash_key() );
		if ( ! is_array( $flash ) ) {
			return null;
		}
		delete_transient( $this->flash_key() );
		return $flash;
	}

	/**
	 * Per-user flash transient key.
	 *
	 * @return string
	 */
	private function flash_key() {
		return 'tagbridge_flash_' . get_current_user_id();
	}

	/**
	 * Redirect back to the settings page after a save.
	 *
	 * @return void
	 */
	private function redirect_back() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'tagbridge' ), 403 );
		}

		$flash       = $this->read_flash();
		$connected   = Options::is_configured();
		$api_key     = Options::project_api_key();
		$region      = Options::region();
		$custom_host = Options::custom_host();
		$client      = Options::client();
		$identity    = Options::identity();
		$server      = Options::server_events();
		$woo_active  = WooEvents::woocommerce_active();
		$is_custom   = Host::REGION_CUSTOM === $region;

		// Tabs, in display order. The key is used for the panel id and nav state.
		$tabs = array(
			'connection' => __( 'Connection', 'tagbridge' ),
			'tracking'   => __( 'Tracking', 'tagbridge' ),
			'identity'   => __( 'Identity', 'tagbridge' ),
			'server'     => __( 'Server events', 'tagbridge' ),
		);
		?>
		<div class="wrap tagbridge-wrap">
			<header class="tagbridge-hero">
				<div class="tagbridge-hero__brand">
					<span class="tagbridge-hero__titles">
						<span class="tagbridge-wordmark">Tagbridge</span>
						<span class="tagbridge-hero__subtitle"><?php esc_html_e( 'An independent PostHog integration for WordPress.', 'tagbridge' ); ?></span>
					</span>
				</div>
				<div class="tagbridge-hero__meta">
					<?php if ( $connected ) : ?>
						<span class="tagbridge-pill tagbridge-pill--ok"><span class="tagbridge-dot"></span><?php esc_html_e( 'Connected', 'tagbridge' ); ?></span>
						<a class="tagbridge-hero__link" href="<?php echo esc_url( $this->app_url( $region, $custom_host ) ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Open PostHog', 'tagbridge' ); ?> &#8599;
						</a>
					<?php else : ?>
						<span class="tagbridge-pill"><span class="tagbridge-dot"></span><?php esc_html_e( 'Not connected', 'tagbridge' ); ?></span>
					<?php endif; ?>
				</div>
			</header>

			<?php if ( $flash ) : ?>
				<div class="tagbridge-flash tagbridge-flash--<?php echo esc_attr( 'success' === $flash['type'] ? 'ok' : 'error' ); ?>">
					<?php echo esc_html( $flash['message'] ); ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tagbridge-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::NONCE, 'tagbridge_nonce' ); ?>

				<nav class="tagbridge-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Tagbridge settings', 'tagbridge' ); ?>">
					<?php
					$first = true;
					foreach ( $tabs as $key => $label ) {
						printf(
							'<button type="button" class="tagbridge-tab%1$s" id="tagbridge-tab-%2$s" role="tab" aria-controls="tagbridge-panel-%2$s" aria-selected="%3$s" tabindex="%4$s" data-hp-tab="%2$s">%5$s</button>',
							$first ? ' is-active' : '',
							esc_attr( $key ),
							$first ? 'true' : 'false',
							$first ? '0' : '-1',
							esc_html( $label )
						);
						$first = false;
					}
					?>
				</nav>

				<div class="tagbridge-panel is-active" id="tagbridge-panel-connection" role="tabpanel" aria-labelledby="tagbridge-tab-connection" tabindex="0">
				<section class="tagbridge-card">
					<div class="tagbridge-card__head">
						<h2 class="tagbridge-card__title"><?php esc_html_e( 'Connection', 'tagbridge' ); ?></h2>
						<?php if ( $connected ) : ?>
							<span class="tagbridge-status tagbridge-status--ok"><?php esc_html_e( 'Connected', 'tagbridge' ); ?></span>
						<?php else : ?>
							<span class="tagbridge-status"><?php esc_html_e( 'Not connected', 'tagbridge' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="tagbridge-card__body">
					<p class="tagbridge-help">
						<?php esc_html_e( 'Paste your PostHog project API key and pick your region. We check it before saving.', 'tagbridge' ); ?>
					</p>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-key"><?php esc_html_e( 'Project API key', 'tagbridge' ); ?></label>
						<input
							type="text"
							id="tagbridge-key"
							class="tagbridge-input tagbridge-input--mono regular-text"
							name="tagbridge[project_api_key]"
							value="<?php echo esc_attr( $api_key ); ?>"
							spellcheck="false"
							autocomplete="off"
							placeholder="phc_..."
						/>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'Find this in PostHog under Settings, Project, Project API key. It is safe to expose in the browser.', 'tagbridge' ); ?>
						</p>
					</div>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-region"><?php esc_html_e( 'Region', 'tagbridge' ); ?></label>
						<select id="tagbridge-region" class="tagbridge-input" name="tagbridge[region]" data-tagbridge-region>
							<option value="<?php echo esc_attr( Host::REGION_US ); ?>" <?php selected( $region, Host::REGION_US ); ?>><?php esc_html_e( 'US cloud (us.i.posthog.com)', 'tagbridge' ); ?></option>
							<option value="<?php echo esc_attr( Host::REGION_EU ); ?>" <?php selected( $region, Host::REGION_EU ); ?>><?php esc_html_e( 'EU cloud (eu.i.posthog.com)', 'tagbridge' ); ?></option>
							<option value="<?php echo esc_attr( Host::REGION_CUSTOM ); ?>" <?php selected( $region, Host::REGION_CUSTOM ); ?>><?php esc_html_e( 'Self-hosted or reverse proxy', 'tagbridge' ); ?></option>
						</select>
					</div>

					<div class="tagbridge-field" data-tagbridge-custom-host <?php echo $is_custom ? '' : 'hidden'; ?>>
						<label class="tagbridge-label" for="tagbridge-host"><?php esc_html_e( 'Custom host URL', 'tagbridge' ); ?></label>
						<input
							type="url"
							id="tagbridge-host"
							class="tagbridge-input tagbridge-input--mono regular-text"
							name="tagbridge[custom_host]"
							value="<?php echo esc_attr( $custom_host ); ?>"
							spellcheck="false"
							autocomplete="off"
							placeholder="https://posthog.example.com"
						/>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'The full URL of your PostHog instance or reverse proxy, including https://.', 'tagbridge' ); ?>
						</p>
					</div>
					</div>
				</section>
				</div>

				<div class="tagbridge-panel" id="tagbridge-panel-tracking" role="tabpanel" aria-labelledby="tagbridge-tab-tracking" tabindex="0" hidden>
					<section class="tagbridge-card">
						<div class="tagbridge-card__head">
							<h2 class="tagbridge-card__title"><?php esc_html_e( 'Tracking', 'tagbridge' ); ?></h2>
						</div>
						<div class="tagbridge-card__body">
							<p class="tagbridge-card__lead">
								<?php esc_html_e( 'What PostHog captures in the browser.', 'tagbridge' ); ?>
							</p>
							<?php
							$this->row(
								'client',
								'pageviews',
								__( 'Track pageviews', 'tagbridge' ),
								__( 'A pageview each time someone loads a page.', 'tagbridge' ),
								! empty( $client['pageviews'] )
							);
							$this->row(
								'client',
								'autocapture',
								__( 'Autocapture clicks and forms', 'tagbridge' ),
								__( 'Clicks, form submissions, and other interactions.', 'tagbridge' ),
								! empty( $client['autocapture'] )
							);
							$this->row(
								'client',
								'session_recording',
								__( 'Record sessions', 'tagbridge' ),
								__( 'Session replays. Heavier, so off by default.', 'tagbridge' ),
								! empty( $client['session_recording'] )
							);
							$this->row(
								'client',
								'cookieless',
								__( 'Cookieless mode', 'tagbridge' ),
								__( 'Keep visitor state in memory; no PostHog cookie.', 'tagbridge' ),
								! empty( $client['cookieless'] )
							);
							?>
							<div class="tagbridge-row tagbridge-row--static">
								<span class="tagbridge-row__text">
									<span class="tagbridge-row__label"><?php esc_html_e( 'Person profiles', 'tagbridge' ); ?></span>
									<span class="tagbridge-help tagbridge-help--field"><?php esc_html_e( 'Identified-only is the usual choice.', 'tagbridge' ); ?></span>
								</span>
								<select class="tagbridge-input" name="tagbridge[client][person_profiles]">
									<option value="identified_only" <?php selected( $client['person_profiles'], 'identified_only' ); ?>><?php esc_html_e( 'Identified only', 'tagbridge' ); ?></option>
									<option value="always" <?php selected( $client['person_profiles'], 'always' ); ?>><?php esc_html_e( 'Everyone', 'tagbridge' ); ?></option>
								</select>
							</div>
						</div>
					</section>
				</div>

				<div class="tagbridge-panel" id="tagbridge-panel-identity" role="tabpanel" aria-labelledby="tagbridge-tab-identity" tabindex="0" hidden>
					<section class="tagbridge-card">
						<div class="tagbridge-card__head">
							<h2 class="tagbridge-card__title"><?php esc_html_e( 'Identity', 'tagbridge' ); ?></h2>
							<?php
							$this->head_switch(
								'identity',
								'identify_logged_in',
								'identity',
								__( 'Identify logged-in users', 'tagbridge' ),
								! empty( $identity['identify_logged_in'] )
							);
							?>
						</div>
						<div class="tagbridge-card__body tagbridge-dependent" data-hp-dependent="identity">
							<p class="tagbridge-card__lead">
								<?php esc_html_e( 'Tie logged-in users to one PostHog person with a hashed ID (never the raw user ID). Choose which properties to send.', 'tagbridge' ); ?>
							</p>
							<input type="hidden" name="tagbridge[identity][present]" value="1" />
							<?php
							$this->row(
								'identity',
								'send_email',
								__( 'Send email address', 'tagbridge' ),
								__( 'Attach the user email to their profile.', 'tagbridge' ),
								! empty( $identity['send_email'] )
							);
							$this->row(
								'identity',
								'send_name',
								__( 'Send display name', 'tagbridge' ),
								__( 'Attach the display name to their profile.', 'tagbridge' ),
								! empty( $identity['send_name'] )
							);
							$this->row(
								'identity',
								'send_role',
								__( 'Send role', 'tagbridge' ),
								__( 'Attach the primary role to their profile.', 'tagbridge' ),
								! empty( $identity['send_role'] )
							);
							?>
						</div>
					</section>
				</div>

				<div class="tagbridge-panel" id="tagbridge-panel-server" role="tabpanel" aria-labelledby="tagbridge-tab-server" tabindex="0" hidden>
				<section class="tagbridge-card">
					<div class="tagbridge-card__head">
						<h2 class="tagbridge-card__title"><?php esc_html_e( 'Server-side events', 'tagbridge' ); ?></h2>
						<?php
						$this->head_switch(
							'server_events',
							'enabled',
							'server',
							__( 'Send events from the server', 'tagbridge' ),
							! empty( $server['enabled'] )
						);
						?>
					</div>
					<div class="tagbridge-card__body tagbridge-dependent" data-hp-dependent="server">
						<p class="tagbridge-card__lead">
							<?php esc_html_e( 'Send key events from your own WordPress server, so they still arrive when a visitor\'s browser blocks tracking. Runs on your existing hosting; nothing extra to set up.', 'tagbridge' ); ?>
						</p>
						<input type="hidden" name="tagbridge[server_events][present]" value="1" />
						<?php
						$this->row(
							'server_events',
							'user_logged_in',
							__( 'User logged in', 'tagbridge' ),
							__( 'Capture when a user logs in.', 'tagbridge' ),
							! empty( $server['user_logged_in'] )
						);
						$this->row(
							'server_events',
							'user_registered',
							__( 'User registered', 'tagbridge' ),
							__( 'Capture when a new account is created.', 'tagbridge' ),
							! empty( $server['user_registered'] )
						);

						if ( $woo_active ) {
							$this->row(
								'server_events',
								'product_viewed',
								__( 'Product viewed', 'tagbridge' ),
								__( 'WooCommerce: a product page was viewed.', 'tagbridge' ),
								! empty( $server['product_viewed'] )
							);
							$this->row(
								'server_events',
								'product_added_to_cart',
								__( 'Added to cart', 'tagbridge' ),
								__( 'WooCommerce: a product was added to the cart.', 'tagbridge' ),
								! empty( $server['product_added_to_cart'] )
							);
							$this->row(
								'server_events',
								'checkout_started',
								__( 'Checkout started', 'tagbridge' ),
								__( 'WooCommerce: a checkout was submitted.', 'tagbridge' ),
								! empty( $server['checkout_started'] )
							);
							$this->row(
								'server_events',
								'order_completed',
								__( 'Order completed', 'tagbridge' ),
								__( 'WooCommerce: an order completed, with value and currency.', 'tagbridge' ),
								! empty( $server['order_completed'] )
							);
						} else {
							?>
							<p class="tagbridge-help tagbridge-help--field">
								<?php esc_html_e( 'WooCommerce is not active. Install it to capture commerce events (product views, add to cart, checkout, orders).', 'tagbridge' ); ?>
							</p>
							<?php
						}
						?>
					</div>
				</section>
				</div>

				<div class="tagbridge-actionbar">
					<button type="submit" class="button button-primary button-hero tagbridge-btn">
						<?php esc_html_e( 'Validate and save', 'tagbridge' ); ?>
					</button>
					<span class="tagbridge-actionbar__note"><?php esc_html_e( 'We check your key with PostHog before saving.', 'tagbridge' ); ?></span>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a settings row: label and help on the left, switch on the right.
	 *
	 * The whole row is a label, so clicking anywhere toggles the switch.
	 *
	 * @param string $group   Settings group (e.g. 'client', 'identity').
	 * @param string $name    Setting key within the group.
	 * @param string $label   Visible label.
	 * @param string $help    One-line help text.
	 * @param bool   $checked Whether currently enabled.
	 * @return void
	 */
	private function row( $group, $name, $label, $help, $checked ) {
		?>
		<label class="tagbridge-row">
			<span class="tagbridge-row__text">
				<span class="tagbridge-row__label"><?php echo esc_html( $label ); ?></span>
				<span class="tagbridge-help tagbridge-help--field"><?php echo esc_html( $help ); ?></span>
			</span>
			<input
				type="checkbox"
				class="tagbridge-switch__input"
				name="tagbridge[<?php echo esc_attr( $group ); ?>][<?php echo esc_attr( $name ); ?>]"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<span class="tagbridge-switch__track"><span class="tagbridge-switch__thumb"></span></span>
		</label>
		<?php
	}

	/**
	 * Render a master switch for a card header (switch only, label is the title).
	 *
	 * @param string $group       Settings group.
	 * @param string $name        Setting key within the group.
	 * @param string $master_key  Identifier linking this master to its dependent body.
	 * @param string $aria_label  Accessible label for the switch.
	 * @param bool   $checked     Whether currently enabled.
	 * @return void
	 */
	private function head_switch( $group, $name, $master_key, $aria_label, $checked ) {
		?>
		<label class="tagbridge-headswitch" data-hp-master="<?php echo esc_attr( $master_key ); ?>">
			<span class="screen-reader-text"><?php echo esc_html( $aria_label ); ?></span>
			<input
				type="checkbox"
				class="tagbridge-switch__input"
				name="tagbridge[<?php echo esc_attr( $group ); ?>][<?php echo esc_attr( $name ); ?>]"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<span class="tagbridge-switch__track"><span class="tagbridge-switch__thumb"></span></span>
		</label>
		<?php
	}

	/**
	 * The PostHog app URL for the configured region, for a deep "Open PostHog" link.
	 *
	 * @param string $region      Region identifier.
	 * @param string $custom_host Custom host URL.
	 * @return string
	 */
	private function app_url( $region, $custom_host ) {
		if ( Host::REGION_EU === $region ) {
			return 'https://eu.posthog.com';
		}
		if ( Host::REGION_CUSTOM === $region && '' !== $custom_host ) {
			return $custom_host;
		}
		return 'https://us.posthog.com';
	}
}
