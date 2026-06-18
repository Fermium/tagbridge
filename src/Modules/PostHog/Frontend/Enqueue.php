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
use Tagbridge\Modules\PostHog\Settings;

/**
 * Enqueues / prints the posthog-js snippet on the front end.
 */
final class Enqueue {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Early in the head so autocapture and pageview fire as soon as possible.
		add_action( 'wp_head', array( $this, 'print_snippet' ), 1 );
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

		// Tie a logged-in user to a stable PostHog person. Calling identify on
		// the next page load after login merges the prior anonymous activity into
		// the identified person. posthog-js de-duplicates repeat identify calls,
		// so emitting this each page load does not spam $identify events.
		$identify = $this->identify_call();
		if ( '' !== $identify ) {
			$js .= "\n" . $identify;
		}

		wp_print_inline_script_tag( $js, array( 'id' => 'tagbridge-posthog-js' ) );
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
