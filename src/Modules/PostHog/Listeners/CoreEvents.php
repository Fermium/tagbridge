<?php
/**
 * Core WordPress event listeners.
 *
 * Sends server-side events for login and registration, and keeps the identified
 * person in sync. Event names come from the Schema, never hardcoded here.
 *
 * @package Tagbridge\Modules\PostHog
 */

namespace Tagbridge\Modules\PostHog\Listeners;

use Tagbridge\Modules\PostHog\Events\Dispatcher;
use Tagbridge\Modules\PostHog\Identity;

/**
 * Listens for core WordPress user events.
 */
final class CoreEvents {

	/**
	 * The event dispatcher.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param Dispatcher $dispatcher Event dispatcher.
	 */
	public function __construct( Dispatcher $dispatcher ) {
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );
		add_action( 'user_register', array( $this, 'on_register' ), 10, 1 );
	}

	/**
	 * Handle a successful login.
	 *
	 * @param string        $user_login The username.
	 * @param \WP_User|null $user       The logged-in user.
	 * @return void
	 */
	public function on_login( $user_login, $user = null ) {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$distinct_id = Identity::stable_id_for_user( $user->ID );
		$this->sync_person( $user, $distinct_id );

		$this->dispatcher->capture(
			'user_logged_in',
			$distinct_id,
			array( 'login_source' => 'wordpress' )
		);
	}

	/**
	 * Handle a new user registration.
	 *
	 * @param int $user_id The new user id.
	 * @return void
	 */
	public function on_register( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$distinct_id = Identity::stable_id_for_user( $user->ID );
		$this->sync_person( $user, $distinct_id );

		$this->dispatcher->capture(
			'user_registered',
			$distinct_id,
			array( 'registration_source' => 'wordpress' )
		);
	}

	/**
	 * Identify the person and merge any prior anonymous activity.
	 *
	 * @param \WP_User $user        The user.
	 * @param string   $distinct_id The user's stable distinct id.
	 * @return void
	 */
	private function sync_person( \WP_User $user, $distinct_id ) {
		$identity = Identity::identity_for( $user );
		if ( null === $identity ) {
			return;
		}

		$this->dispatcher->identify( $distinct_id, $identity['properties'] );

		// Merge the visitor's anonymous browser id into the identified person.
		$anon = Identity::cookie_distinct_id();
		if ( null !== $anon ) {
			$this->dispatcher->alias( $distinct_id, $anon );
		}
	}
}
