<?php
/**
 * Plugin Name:       DEV3 AI Course Builder for LearnDash & Tutor LMS
 * Plugin URI:        https://dev3.tech/item/dev3-ai-course-builder
 * Description:       AI course builder for LearnDash, Tutor LMS or plain pages: outline, lessons, quizzes and certificate text from a topic, outline or PDF/DOCX, reviewed before anything is created.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-course-builder
 * Domain Path:       /languages
 *
 * @package Dev3\AiCourseBuilder
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3LM_VERSION', '1.0.0' );
define( 'DEV3LM_FILE', __FILE__ );
define( 'DEV3LM_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3LM_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3LM_SLUG', 'dev3-ai-course-builder' );
define( 'DEV3LM_NAME', 'DEV3 AI Course Builder for LearnDash & Tutor LMS' );
define( 'DEV3LM_MIN_PHP', '7.4' );
define( 'DEV3LM_MIN_WP', '6.0' );
define( 'DEV3LM_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3LM_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiCourseBuilder\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3LM_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiCourseBuilder\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiCourseBuilder\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiCourseBuilder\Plugin', 'instance' ) );
