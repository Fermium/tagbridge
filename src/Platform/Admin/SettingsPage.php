<?php
/**
 * Settings page shell.
 *
 * Owns the admin page chrome: the menu entry, asset loading, the brand hero, the
 * nonce, the save flow, and flash messages. It is module-agnostic: each
 * configurable module supplies its own defaults, sanitizer, optional pre-save
 * validation, and the body of its settings panel. Adding an integration adds a
 * module; this shell does not change.
 *
 * @package Tagbridge\Platform
 */

namespace Tagbridge\Platform\Admin;

use Tagbridge\Platform\Modules\ConfigurableModule;
use Tagbridge\Platform\Modules\Registry;
use Tagbridge\Platform\Settings\Options;

/**
 * The plugin's settings screen, under the WordPress Settings menu.
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
	 * The module registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The module registry.
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

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
	 * Add the settings page as a submenu under the WordPress Settings menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Tagbridge', 'tagbridge' ),
			__( 'Tagbridge', 'tagbridge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The admin page hook suffix for our screen.
	 *
	 * @return string
	 */
	public static function hook_suffix() {
		return 'settings_page_' . self::SLUG;
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
	 * Configurable modules, keyed by id, in registration order.
	 *
	 * @return array<string,ConfigurableModule>
	 */
	private function configurable_modules() {
		$modules = array();
		foreach ( $this->registry->all() as $id => $module ) {
			if ( $module instanceof ConfigurableModule ) {
				$modules[ $id ] = $module;
			}
		}
		return $modules;
	}

	/**
	 * Handle the settings save: per module, sanitize, validate, then persist.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! $this->can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'tagbridge' ), 403 );
		}

		check_admin_referer( self::NONCE, 'tagbridge_nonce' );

		// Nonce verified above; each module sanitizes its own slice field-by-field.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = isset( $_POST['tagbridge'] ) && is_array( $_POST['tagbridge'] ) ? wp_unslash( $_POST['tagbridge'] ) : array();

		// Module enable/disable map (checkbox present only when enabled).
		$enabled = isset( $raw['modules'] ) && is_array( $raw['modules'] ) ? $raw['modules'] : array();

		$saved_message = __( 'Settings saved.', 'tagbridge' );

		foreach ( $this->configurable_modules() as $id => $module ) {
			// Persist the module's enabled state.
			Options::set_module_enabled( $id, ! empty( $enabled[ $id ] ) );

			$input     = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();
			$sanitized = $module->sanitize( $input );

			$result = $module->before_save( $sanitized );
			if ( empty( $result['ok'] ) ) {
				$this->flash( 'error', $result['message'] );
				$this->redirect_back();
				return;
			}

			Options::save_module_data( $id, $sanitized );

			if ( ! empty( $result['message'] ) ) {
				$saved_message = $result['message'];
			}
		}

		$this->flash( 'success', $saved_message );
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
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
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

		$flash   = $this->read_flash();
		$modules = $this->configurable_modules();
		?>
		<div class="wrap tagbridge-wrap">
			<header class="tagbridge-hero">
				<div class="tagbridge-hero__brand">
					<span class="tagbridge-hero__titles">
						<span class="tagbridge-wordmark">Tagbridge</span>
						<span class="tagbridge-hero__subtitle"><?php esc_html_e( 'Connect WordPress to the tools you already use.', 'tagbridge' ); ?></span>
					</span>
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

				<?php foreach ( $modules as $id => $module ) : ?>
					<section class="tagbridge-module" data-tagbridge-module="<?php echo esc_attr( $id ); ?>">
						<header class="tagbridge-module__head">
							<span class="tagbridge-module__icon dashicons <?php echo esc_attr( $module->icon() ); ?>" aria-hidden="true"></span>
							<span class="tagbridge-module__titles">
								<span class="tagbridge-module__name"><?php echo esc_html( $module->label() ); ?></span>
								<span class="tagbridge-module__desc"><?php echo esc_html( $module->description() ); ?></span>
							</span>
							<label class="tagbridge-headswitch tagbridge-module__toggle">
								<span class="screen-reader-text">
									<?php
									/* translators: %s: integration name. */
									printf( esc_html__( 'Enable %s', 'tagbridge' ), esc_html( $module->label() ) );
									?>
								</span>
								<input
									type="checkbox"
									class="tagbridge-switch__input"
									name="tagbridge[modules][<?php echo esc_attr( $id ); ?>]"
									value="1"
									<?php checked( $this->registry->is_enabled( $id ) ); ?>
								/>
								<span class="tagbridge-switch__track"><span class="tagbridge-switch__thumb"></span></span>
							</label>
						</header>
						<div class="tagbridge-module__body">
							<?php $module->render_settings(); ?>
						</div>
					</section>
				<?php endforeach; ?>

				<div class="tagbridge-actionbar">
					<button type="submit" class="button button-primary button-hero tagbridge-btn">
						<?php esc_html_e( 'Validate and save', 'tagbridge' ); ?>
					</button>
					<span class="tagbridge-actionbar__note"><?php esc_html_e( 'We check your connection before saving.', 'tagbridge' ); ?></span>
				</div>
			</form>
		</div>
		<?php
	}
}
