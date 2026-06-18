<?php
/**
 * PostHog settings panel.
 *
 * Renders the body of the PostHog module's settings: the tab nav and the
 * Connection, Tracking, Identity, and Server-side events panels. The admin shell
 * owns the page chrome, nonce, and save flow; this only outputs the module's
 * fields, namespaced under the module id so the shell can route the saved data.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog;

use Tagbridge\Core\Connection\Host;
use Tagbridge\Modules\PostHog\Listeners\WooEvents;

/**
 * Renders the PostHog module's settings UI.
 */
final class SettingsPanel {

	/**
	 * The module id, used as the field-name prefix (e.g. tagbridge[posthog][...]).
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param string $id Module id.
	 */
	public function __construct( $id ) {
		$this->id = $id;
	}

	/**
	 * The tab definitions for this module, in display order.
	 *
	 * @return array<string,string>
	 */
	public function tabs() {
		return array(
			'connection' => __( 'Connection', 'tagbridge' ),
			'tracking'   => __( 'Tracking', 'tagbridge' ),
			'identity'   => __( 'Identity', 'tagbridge' ),
			'server'     => __( 'Server events', 'tagbridge' ),
		);
	}

	/**
	 * Render the tab nav and all tab panels for the module.
	 *
	 * @return void
	 */
	public function render() {
		$connected   = Settings::is_configured();
		$api_key     = Settings::project_api_key();
		$region      = Settings::region();
		$custom_host = Settings::custom_host();
		$client      = Settings::client();
		$identity    = Settings::identity();
		$server      = Settings::server_events();
		$woo_active  = WooEvents::woocommerce_active();
		$error       = Settings::error_tracking();
		$is_custom   = Host::REGION_CUSTOM === $region;
		?>
		<nav class="tagbridge-tabs" role="tablist" aria-label="<?php esc_attr_e( 'PostHog settings', 'tagbridge' ); ?>">
			<?php
			$first = true;
			foreach ( $this->tabs() as $key => $label ) {
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
						<?php esc_html_e( 'Paste your PostHog project token and pick your region. We check it before saving.', 'tagbridge' ); ?>
					</p>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-key"><?php esc_html_e( 'Project token', 'tagbridge' ); ?></label>
						<input
							type="text"
							id="tagbridge-key"
							class="tagbridge-input tagbridge-input--mono regular-text"
							name="<?php echo esc_attr( $this->field( 'project_api_key' ) ); ?>"
							value="<?php echo esc_attr( $api_key ); ?>"
							spellcheck="false"
							autocomplete="off"
							placeholder="phc_..."
						/>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'Find this in PostHog under Settings, General, Project token. It is safe to expose in the browser.', 'tagbridge' ); ?>
						</p>
					</div>

					<div class="tagbridge-field">
						<label class="tagbridge-label" for="tagbridge-region"><?php esc_html_e( 'Region', 'tagbridge' ); ?></label>
						<select id="tagbridge-region" class="tagbridge-input" name="<?php echo esc_attr( $this->field( 'region' ) ); ?>" data-tagbridge-region>
							<option value="<?php echo esc_attr( Host::REGION_US ); ?>" <?php selected( $region, Host::REGION_US ); ?>><?php esc_html_e( 'US cloud (us.i.posthog.com)', 'tagbridge' ); ?></option>
							<option value="<?php echo esc_attr( Host::REGION_EU ); ?>" <?php selected( $region, Host::REGION_EU ); ?>><?php esc_html_e( 'EU cloud (eu.i.posthog.com)', 'tagbridge' ); ?></option>
							<option value="<?php echo esc_attr( Host::REGION_CUSTOM ); ?>" <?php selected( $region, Host::REGION_CUSTOM ); ?>><?php esc_html_e( 'Self-hosted or reverse proxy', 'tagbridge' ); ?></option>
						</select>
						<p class="tagbridge-help tagbridge-help--field">
							<?php
							printf(
								wp_kses(
									/* translators: %s: URL of PostHog's managed reverse proxy documentation. */
									__( 'Recommended: route events through your own domain with PostHog\'s free <a href="%s" target="_blank" rel="noopener noreferrer">managed reverse proxy</a> so ad blockers don\'t drop them (typically 10&#8211;30%% more events). Set it up in PostHog, then pick &#8220;Self-hosted or reverse proxy&#8221; and paste the subdomain below.', 'tagbridge' ),
									array(
										'a' => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								),
								esc_url( 'https://posthog.com/docs/advanced/proxy/managed-reverse-proxy' )
							);
							?>
						</p>
					</div>

					<div class="tagbridge-field" data-tagbridge-custom-host <?php echo $is_custom ? '' : 'hidden'; ?>>
						<label class="tagbridge-label" for="tagbridge-host"><?php esc_html_e( 'Custom host URL', 'tagbridge' ); ?></label>
						<input
							type="url"
							id="tagbridge-host"
							class="tagbridge-input tagbridge-input--mono regular-text"
							name="<?php echo esc_attr( $this->field( 'custom_host' ) ); ?>"
							value="<?php echo esc_attr( $custom_host ); ?>"
							spellcheck="false"
							autocomplete="off"
							placeholder="https://e.yourdomain.com"
						/>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'Your reverse-proxy subdomain (e.g. from PostHog\'s managed reverse proxy) or a self-hosted PostHog URL, including https://.', 'tagbridge' ); ?>
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
						'heatmaps',
						__( 'Heatmaps', 'tagbridge' ),
						__( 'Click and scroll heatmaps. Also enable heatmaps in your PostHog project settings.', 'tagbridge' ),
						! empty( $client['heatmaps'] )
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
					$this->row(
						'error_tracking',
						'javascript',
						__( 'Track JavaScript errors', 'tagbridge' ),
						__( 'Capture unhandled browser errors to PostHog error tracking.', 'tagbridge' ),
						! empty( $error['javascript'] )
					);
					?>
					<div class="tagbridge-row tagbridge-row--static">
						<span class="tagbridge-row__text">
							<span class="tagbridge-row__label"><?php esc_html_e( 'Person profiles', 'tagbridge' ); ?></span>
							<span class="tagbridge-help tagbridge-help--field"><?php esc_html_e( 'Identified-only is the usual choice.', 'tagbridge' ); ?></span>
						</span>
						<select class="tagbridge-input" name="<?php echo esc_attr( $this->field( 'client', 'person_profiles' ) ); ?>">
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
					<input type="hidden" name="<?php echo esc_attr( $this->field( 'identity', 'present' ) ); ?>" value="1" />
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
					<input type="hidden" name="<?php echo esc_attr( $this->field( 'server_events', 'present' ) ); ?>" value="1" />
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
					$this->row(
						'error_tracking',
						'php',
						__( 'Track PHP errors', 'tagbridge' ),
						__( 'Send uncaught PHP exceptions and errors from your server. Installs a PHP error handler (chained, not replacing existing ones).', 'tagbridge' ),
						! empty( $error['php'] )
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
							'product_list_viewed',
							__( 'Product list viewed', 'tagbridge' ),
							__( 'WooCommerce: a shop, category, or tag archive was viewed.', 'tagbridge' ),
							! empty( $server['product_list_viewed'] )
						);
						$this->row(
							'server_events',
							'products_searched',
							__( 'Products searched', 'tagbridge' ),
							__( 'A search was performed, with the query and result count.', 'tagbridge' ),
							! empty( $server['products_searched'] )
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
							'product_removed_from_cart',
							__( 'Removed from cart', 'tagbridge' ),
							__( 'WooCommerce: a product was removed from the cart.', 'tagbridge' ),
							! empty( $server['product_removed_from_cart'] )
						);
						$this->row(
							'server_events',
							'cart_viewed',
							__( 'Cart viewed', 'tagbridge' ),
							__( 'WooCommerce: the cart page was viewed.', 'tagbridge' ),
							! empty( $server['cart_viewed'] )
						);
						$this->row(
							'server_events',
							'coupon_applied',
							__( 'Coupon applied', 'tagbridge' ),
							__( 'WooCommerce: a coupon was applied to the cart.', 'tagbridge' ),
							! empty( $server['coupon_applied'] )
						);
						$this->row(
							'server_events',
							'coupon_removed',
							__( 'Coupon removed', 'tagbridge' ),
							__( 'WooCommerce: a coupon was removed from the cart.', 'tagbridge' ),
							! empty( $server['coupon_removed'] )
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
						$this->row(
							'server_events',
							'payment_failed',
							__( 'Payment failed', 'tagbridge' ),
							__( 'WooCommerce: an order\'s payment failed.', 'tagbridge' ),
							! empty( $server['payment_failed'] )
						);
						$this->row(
							'server_events',
							'order_refunded',
							__( 'Order refunded', 'tagbridge' ),
							__( 'WooCommerce: an order was refunded, fully or partially.', 'tagbridge' ),
							! empty( $server['order_refunded'] )
						);
						$this->row(
							'server_events',
							'order_cancelled',
							__( 'Order cancelled', 'tagbridge' ),
							__( 'WooCommerce: an order was cancelled.', 'tagbridge' ),
							! empty( $server['order_cancelled'] )
						);
					} else {
						?>
						<p class="tagbridge-help tagbridge-help--field">
							<?php esc_html_e( 'WooCommerce is not active. Install it to capture commerce events (product views, add to cart, checkout, orders).', 'tagbridge' ); ?>
						</p>
						<?php
					}
					?>
					<?php if ( $woo_active ) : ?>
						<p class="tagbridge-help tagbridge-help--field">
							<?php
							printf(
								wp_kses(
									/* translators: %s: URL of PostHog's WooCommerce data warehouse source documentation. */
									__( 'For SQL analytics on your orders, customers, and products, PostHog can sync your store as a <a href="%s" target="_blank" rel="noopener noreferrer">WooCommerce data warehouse source</a> and query it next to these events.', 'tagbridge' ),
									array(
										'a' => array(
											'href'   => array(),
											'target' => array(),
											'rel'    => array(),
										),
									)
								),
								esc_url( 'https://posthog.com/docs/data-warehouse/sources/woocommerce' )
							);
							?>
						</p>
					<?php endif; ?>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Build a namespaced field name: tagbridge[<id>][<group>] or [<group>][<name>].
	 *
	 * @param string      $group Group key (e.g. 'client') or a top-level key.
	 * @param string|null $name  Optional sub-key within the group.
	 * @return string
	 */
	private function field( $group, $name = null ) {
		$field = 'tagbridge[' . $this->id . '][' . $group . ']';
		if ( null !== $name ) {
			$field .= '[' . $name . ']';
		}
		return $field;
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
				name="<?php echo esc_attr( $this->field( $group, $name ) ); ?>"
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
	 * @param string $group      Settings group.
	 * @param string $name       Setting key within the group.
	 * @param string $master_key Identifier linking this master to its dependent body.
	 * @param string $aria_label Accessible label for the switch.
	 * @param bool   $checked    Whether currently enabled.
	 * @return void
	 */
	private function head_switch( $group, $name, $master_key, $aria_label, $checked ) {
		?>
		<label class="tagbridge-headswitch" data-hp-master="<?php echo esc_attr( $master_key ); ?>">
			<span class="screen-reader-text"><?php echo esc_html( $aria_label ); ?></span>
			<input
				type="checkbox"
				class="tagbridge-switch__input"
				name="<?php echo esc_attr( $this->field( $group, $name ) ); ?>"
				value="1"
				<?php checked( $checked ); ?>
			/>
			<span class="tagbridge-switch__track"><span class="tagbridge-switch__thumb"></span></span>
		</label>
		<?php
	}
}
