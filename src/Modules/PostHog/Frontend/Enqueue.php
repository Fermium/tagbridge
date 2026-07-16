<?php
/**
 * Front-end injection of posthog-js.
 *
 * Outputs the official posthog-js array loader and an init call configured from
 * the saved settings. The script loads from the configured host so self-hosted
 * and reverse-proxy installs work without code.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Frontend;

use Tagbridge\Modules\PostHog\Identity;
use Tagbridge\Modules\PostHog\ProductMeta;
use Tagbridge\Modules\PostHog\Settings;

/**
 * Enqueues / prints the posthog-js snippet on the front end.
 */
final class Enqueue {

	/**
	 * One-shot flag cookie set on logout so the next page load calls reset().
	 *
	 * @var string
	 */
	const RESET_COOKIE = 'tagbridge_reset';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Early in the head so autocapture and pageview fire as soon as possible.
		add_action( 'wp_head', array( $this, 'print_snippet' ), 1 );
		// Front-end script that captures WooCommerce variant selections.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_variations' ) );
		// Capture cart_viewed when CheckoutWC's side-cart opens (no cart page).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_side_cart_tracking' ) );
		// Flag a browser reset() on the next page load after the user logs out.
		add_action( 'wp_logout', array( $this, 'flag_reset_on_logout' ) );
	}

	/**
	 * Enqueue the variant-tracking script on single product pages.
	 *
	 * Only loads when configured, the toggle is on, and we are on a product page
	 * (where WooCommerce's variation form and jQuery are present).
	 *
	 * @return void
	 */
	public function enqueue_variations() {
		if ( ! Settings::is_configured() ) {
			return;
		}

		$client = Settings::client();
		if ( empty( $client['track_variants'] ) ) {
			return;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		wp_enqueue_script(
			'tagbridge-variations',
			TAGBRIDGE_URL . 'assets/js/variations.js',
			array( 'jquery' ),
			TAGBRIDGE_VERSION,
			true
		);

		// Pass the product's category + descriptive attributes so variant
		// selections carry attribute-level context (blade material, origin, etc.).
		if ( function_exists( 'wc_get_product' ) ) {
			$meta = ProductMeta::collect( wc_get_product( get_queried_object_id() ) );
			if ( ! empty( $meta ) ) {
				wp_localize_script( 'tagbridge-variations', 'tagbridgePostHogProduct', $meta );
			}
		}
	}

	/**
	 * Enqueue the side-cart cart_viewed tracker.
	 *
	 * CheckoutWC replaces the cart page with a slide-out side-cart, so the
	 * server-side cart_viewed (which needs a cart page) never fires. This small
	 * script sends cart_viewed when the side-cart opens. Loads only when the
	 * plugin is configured, the cart_viewed event is enabled, and CheckoutWC is
	 * active, so non-CheckoutWC sites are unaffected.
	 *
	 * @return void
	 */
	public function enqueue_side_cart_tracking() {
		if ( ! Settings::is_configured() ) {
			return;
		}

		// Reuse the cart_viewed event toggle — same logical event, client transport.
		if ( ! Settings::is_server_event_enabled( 'cart_viewed' ) ) {
			return;
		}

		// Only when CheckoutWC is active; its template helpers are a stable signal.
		if ( ! function_exists( 'cfw_is_checkout' ) ) {
			return;
		}

		wp_enqueue_script(
			'tagbridge-side-cart',
			TAGBRIDGE_URL . 'assets/js/side-cart.js',
			array(),
			TAGBRIDGE_VERSION,
			true
		);

		wp_localize_script(
			'tagbridge-side-cart',
			'tagbridgeSideCart',
			array(
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			)
		);
	}

	/**
	 * Print the posthog-js loader and init, if the plugin is configured.
	 *
	 * @return void
	 */
	public function print_snippet() {
		if ( ! Settings::is_configured() ) {
			return;
		}

		// Never load in contexts that are not real visitor page views.
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}

		$key = Settings::project_api_key();
		$js  = $this->loader() . "\n" . $this->init_call( $key, $this->build_config() );

		// After logout, tell posthog-js to reset() so the previous user's
		// identified id does not linger in the browser. Mutually exclusive with
		// the identify below (one runs when logged out, the other when logged in).
		$reset = $this->reset_call();
		if ( '' !== $reset ) {
			$js .= "\n" . $reset;
		}

		// Tie a logged-in user to a stable PostHog person. Calling identify on the
		// next page load after login merges the prior anonymous activity into the
		// identified person. With a persistent store (cookie / localStorage)
		// posthog-js de-duplicates repeat identify calls, so emitting it each page
		// load does not spam $identify events. In cookieless mode (persistence
		// 'memory') nothing survives a page load, so a fresh $identify fires per
		// page — an accepted trade-off of running without storage.
		$identify = $this->identify_call();
		if ( '' !== $identify ) {
			$js .= "\n" . $identify;
		}

		wp_print_inline_script_tag( $js, array( 'id' => 'tagbridge-posthog-js' ) );
	}

	/**
	 * On logout, set a short-lived flag cookie so the next front-end page load
	 * emits posthog.reset(). Without it the ph_<key>_posthog cookie keeps the
	 * logged-out user's identified id, so a later visitor on the same device is
	 * tracked as that user and a subsequent different login tries to merge two
	 * already-identified people — which PostHog refuses.
	 *
	 * @return void
	 */
	public function flag_reset_on_logout() {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::RESET_COOKIE,
			'1',
			array(
				'expires'  => time() + HOUR_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Build the posthog.reset() call for a just-logged-out visitor, or '' if none.
	 *
	 * Fires only when the logout flag cookie is present and the visitor is now
	 * anonymous, and clears the cookie client-side so it runs exactly once.
	 * reset() unlinks future events from the logged-out user and starts a fresh
	 * anonymous person and session, matching PostHog's documented logout handling.
	 *
	 * @return string
	 */
	private function reset_call() {
		if ( empty( $_COOKIE[ self::RESET_COOKIE ] ) || is_user_logged_in() ) {
			return '';
		}

		$path  = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
		$clear = self::RESET_COOKIE . '=; path=' . $path . '; max-age=0';

		return 'posthog.reset();document.cookie=' . wp_json_encode( $clear ) . ';';
	}

	/**
	 * Build the posthog.identify(...) call for the logged-in user, or '' if none.
	 *
	 * @return string
	 */
	private function identify_call() {
		$identity = Identity::current_user_identity();
		if ( null === $identity || '' === $identity['distinct_id'] ) {
			return '';
		}

		$args = wp_json_encode( $identity['distinct_id'] );
		if ( ! empty( $identity['properties'] ) ) {
			$args .= ',' . wp_json_encode( $identity['properties'] );
		}

		return 'posthog.identify(' . $args . ');';
	}

	/**
	 * Build the posthog.init config from settings.
	 *
	 * @return array<string,mixed>
	 */
	private function build_config() {
		$client = Settings::client();

		$config = array(
			'api_host'                  => Settings::host(),
			// Pin to a dated defaults bundle so PostHog behavior is stable across releases.
			'defaults'                  => '2026-01-30',
			'autocapture'               => (bool) $client['autocapture'],
			'capture_heatmaps'          => (bool) $client['heatmaps'],
			'capture_pageview'          => (bool) $client['pageviews'],
			'disable_session_recording' => empty( $client['session_recording'] ),
			'person_profiles'           => (string) $client['person_profiles'],
		);

		// Browser error tracking: posthog-js autocaptures unhandled JS exceptions.
		if ( ! empty( Settings::error_tracking()['javascript'] ) ) {
			$config['capture_exceptions'] = true;
		}

		// Cookieless mode: keep all state in memory so no PostHog cookie is set.
		if ( ! empty( $client['cookieless'] ) ) {
			$config['persistence'] = 'memory';
		}

		// When session replay is on, mask form inputs by default and let sites add a
		// text-masking selector for elements that render PII as text (e.g. a checkout
		// review pane or order-confirmation address). Inputs are masked regardless;
		// the selector covers the text that rrweb would otherwise record verbatim.
		if ( ! empty( $client['session_recording'] ) ) {
			$config['session_recording'] = array( 'maskAllInputs' => true );

			$mask_selector = $this->mask_text_selector();
			if ( '' !== $mask_selector ) {
				$config['session_recording']['maskTextSelector'] = $mask_selector;
			}
		}

		/**
		 * Filter the posthog-js init configuration before it is printed.
		 *
		 * @param array<string,mixed> $config The configuration array.
		 */
		return (array) apply_filters( 'tagbridge_posthog_js_config', $config );
	}

	/**
	 * CSS selector whose matching elements have their text masked in session
	 * replay. The defaults cover common WooCommerce / CheckoutWC surfaces that
	 * render a customer's name, email, or address as text (form inputs are masked
	 * separately). Return an empty string to disable text masking.
	 *
	 * @return string
	 */
	private function mask_text_selector() {
		$default = '.woocommerce-customer-details, .cfw-review-pane, .pac-container';

		/**
		 * Filter the session-replay text-masking selector.
		 *
		 * @param string $default Comma-separated CSS selector, or '' to disable.
		 */
		return (string) apply_filters( 'tagbridge_posthog_mask_text_selector', $default );
	}

	/**
	 * Build the posthog.init( token, config ) call as a JS string.
	 *
	 * Both arguments are JSON-encoded, which safely escapes them for inline JS.
	 *
	 * @param string              $key    Project API key.
	 * @param array<string,mixed> $config Init configuration.
	 * @return string
	 */
	private function init_call( $key, array $config ) {
		return 'posthog.init(' . wp_json_encode( $key ) . ',' . wp_json_encode( $config ) . ');';
	}

	/**
	 * The official posthog-js array loader snippet.
	 *
	 * This is PostHog's standard, publicly documented loader. The human-readable
	 * source lives in the posthog-js project:
	 * https://github.com/PostHog/posthog-js and https://posthog.com/docs/libraries/js
	 *
	 * It loads /static/array.js from the assets host derived from api_host (the
	 * snippet swaps ".i.posthog.com" for "-assets.i.posthog.com"; for custom
	 * hosts the string is unchanged and it loads from that host directly).
	 *
	 * @return string
	 */
	private function loader() {
		return <<<'JS'
!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
JS;
	}
}
