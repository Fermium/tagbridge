<?php
/**
 * Validates a PostHog project API key and host with a live test call.
 *
 * This is WordPress glue: it uses the HTTP API. The endpoint and response
 * meaning come from Core\Connection\Host. The call hits the public, read-only
 * flags endpoint, so it never ingests a test event.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Connection;

use Tagbridge\Core\Connection\Host;

/**
 * Connection validator.
 */
final class Validator {

	/**
	 * Validate a key against a host by calling the public flags endpoint.
	 *
	 * @param string $api_key Project API key.
	 * @param string $host    Resolved base host URL.
	 * @return array{ok:bool,message:string} Result with a human-readable message.
	 */
	public static function validate( $api_key, $host ) {
		$api_key = (string) $api_key;
		$host    = (string) $host;

		if ( '' === $api_key ) {
			return array(
				'ok'      => false,
				'message' => __( 'Enter your PostHog project API key.', 'tagbridge' ),
			);
		}

		if ( ! Host::is_valid_url( $host ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'That host does not look like a valid URL. Check the region or custom host.', 'tagbridge' ),
			);
		}

		$response = wp_remote_post(
			Host::flags_endpoint( $host ),
			array(
				'timeout'     => 12,
				'redirection' => 2,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'api_key'     => $api_key,
						'distinct_id' => 'tagbridge-setup-check',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: the host URL that could not be reached. */
				'message' => sprintf( __( 'We could not reach PostHog at %s. Check the region or custom host and try again.', 'tagbridge' ), $host ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			return array(
				'ok'      => true,
				'message' => __( 'Connected. Your key and host check out.', 'tagbridge' ),
			);
		}

		if ( 401 === $code ) {
			return array(
				'ok'      => false,
				'message' => __( 'That project API key was rejected by PostHog. Double-check the key and the region.', 'tagbridge' ),
			);
		}

		return array(
			'ok'      => false,
			/* translators: %d: the HTTP status code returned by PostHog. */
			'message' => sprintf( __( 'PostHog returned an unexpected response (HTTP %d). Try again in a moment.', 'tagbridge' ), $code ),
		);
	}
}
