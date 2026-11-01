<?php
/**
 * "Rewrite with DEV3 AI" / "Quiz with DEV3 AI" row actions on lesson lists (Tutor, LearnDash, pages).
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Admin;

use Dev3\AiCourseBuilder\Core3\Core\Capabilities;
use Dev3\AiCourseBuilder\Rest\Tools_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Row actions.
 */
class Row_Actions {

	/**
	 * Hooks.
	 */
	public function register() {
		add_filter( 'post_row_actions', array( $this, 'actions' ), 20, 2 );
		add_filter( 'page_row_actions', array( $this, 'actions' ), 20, 2 );
	}

	/**
	 * Adds the actions.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function actions( $actions, $post ) {
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! current_user_can( Capabilities::manage() ) ) {
			return $actions;
		}
		if ( ! in_array( $post->post_type, Tools_Controller::lesson_types(), true ) ) {
			return $actions;
		}
		// Pages/posts get the actions only when they look like lessons (made by DEV3 or children of a course page).
		if ( in_array( $post->post_type, array( 'page', 'post' ), true ) && ! get_post_meta( $post->ID, '_dev3lm_blueprint', true ) ) {
			return $actions;
		}
		$base                      = admin_url( 'admin.php?page=' . DEV3LM_SLUG );
		$actions['dev3lm_rewrite'] = '<a href="' . esc_url( $base . '#/rewrite/' . $post->ID ) . '">' . esc_html__( 'Rewrite with DEV3 AI', 'dev3-ai-course-builder' ) . '</a>';
		$actions['dev3lm_quiz']    = '<a href="' . esc_url( $base . '#/quiz/' . $post->ID ) . '">' . esc_html__( 'Quiz with DEV3 AI', 'dev3-ai-course-builder' ) . '</a>';
		return $actions;
	}
}
