<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Admin;

use Dev3\AiCourseBuilder\Core3\Core\Assets;
use Dev3\AiCourseBuilder\Core3\Core\Capabilities;
use Dev3\AiCourseBuilder\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3LM_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3LM_NAME, __( 'DEV3 AI Courses', 'dev3-ai-course-builder' ), Capabilities::manage(), DEV3LM_SLUG, array( $this, 'render' ), 'dashicons-welcome-learn-more', 58 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3lm-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3LM_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3lm-admin-root" class="dev3lm-root dev3lm-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Plugin::assets();
			wp_enqueue_media();
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3LM_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-course-builder' ) . '</a>' );
		return $links;
	}
}
