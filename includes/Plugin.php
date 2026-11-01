<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder;

use Dev3\AiCourseBuilder\Ai\Prompt_Library;
use Dev3\AiCourseBuilder\Core3\Core\Assets;
use Dev3\AiCourseBuilder\Core3\Core\Privacy;
use Dev3\AiCourseBuilder\Core3\Core\Schema;
use Dev3\AiCourseBuilder\Data\Blueprints;
use Dev3\AiCourseBuilder\Lms\Adapter;
use Dev3\AiCourseBuilder\Lms\Adapters;
use Dev3\AiCourseBuilder\Service\Builder;
use Dev3\AiCourseBuilder\Service\Content_Job;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3lm_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether the admin bundle was registered this request.
	 *
	 * @var bool
	 */
	private static $assets = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3LM_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_unschedule_hook( 'dev3lm_run_job' );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3lm_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3lm_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3lm_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3lm_schema_sql', array( Blueprints::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Registers the admin bundle once per request with the plugin's boot data.
	 */
	public static function assets() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		Assets::register(
			array( 'admin' ),
			array(
				'adapters'      => is_admin() ? Adapters::describe() : array(),
				'questionTypes' => Settings::QUESTION_TYPES,
				'maxUpload'     => (int) wp_max_upload_size(),
				'mediaUrl'      => admin_url( 'upload.php' ),
			)
		);
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ) );
		add_action( 'init', array( Prompt_Library::class, 'register' ), 5 );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3LM_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_filter( 'upload_mimes', array( $this, 'mimes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'front_styles' ) );
		Privacy::init();
		// Background job types must be registered on every request (the runner looks them up).
		Builder::register();
		Content_Job::register();
		( new Front\Certificate() )->register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
			( new Admin\Row_Actions() )->register();
		}
	}

	/**
	 * Translations (on init, as WP 6.7+ expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-course-builder', false, dirname( plugin_basename( DEV3LM_FILE ) ) . '/languages' );
	}

	/**
	 * Lets course managers upload Markdown outlines (.md as text/plain).
	 *
	 * @param array $mimes Mimes.
	 * @return array
	 */
	public function mimes( $mimes ) {
		if ( current_user_can( Core3\Core\Capabilities::manage() ) ) {
			$mimes['md|markdown'] = 'text/plain';
		}
		return $mimes;
	}

	/**
	 * Small stylesheet for callouts, page-course navigation and certificates on DEV3-built content.
	 */
	public function front_styles() {
		if ( is_singular() && get_post_meta( (int) get_queried_object_id(), Adapter::META_BLUEPRINT, true ) ) {
			wp_enqueue_style( 'dev3lm-certificate' );
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Blueprint_Controller() )->register_routes();
		( new Rest\Tools_Controller() )->register_routes();
	}
}
