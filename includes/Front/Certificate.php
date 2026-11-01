<?php
/**
 * Printable course certificate: block "dev3lm/certificate" + shortcode [dev3lm_certificate course="ID"].
 * Shows to learners who completed the course (Tutor LMS / LearnDash completion checks; for page
 * courses any logged-in reader). Editors see a preview. Learner-facing text is neutral (no branding).
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Front;

use Dev3\AiCourseBuilder\Lms\Adapter;

defined( 'ABSPATH' ) || exit;

/**
 * Certificate output.
 */
class Certificate {

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Registers the shortcode, block and styles.
	 */
	public function init() {
		add_shortcode( 'dev3lm_certificate', array( $this, 'shortcode' ) );
		wp_register_style( 'dev3lm-certificate', DEV3LM_URL . 'assets/css/certificate.css', array(), DEV3LM_VERSION );
		if ( function_exists( 'register_block_type' ) && is_readable( DEV3LM_PATH . 'blocks/certificate/block.json' ) ) {
			$asset = is_readable( DEV3LM_PATH . 'build/blocks.asset.php' ) ? include DEV3LM_PATH . 'build/blocks.asset.php' : null;
			if ( $asset ) {
				wp_register_script( 'dev3lm-blocks', DEV3LM_URL . 'build/blocks.js', $asset['dependencies'], $asset['version'], true );
				wp_set_script_translations( 'dev3lm-blocks', 'dev3-ai-course-builder', DEV3LM_PATH . 'languages' );
			}
			register_block_type(
				DEV3LM_PATH . 'blocks/certificate',
				array(
					'render_callback' => array( $this, 'render_block' ),
					'editor_script'   => $asset ? 'dev3lm-blocks' : null,
					'style'           => 'dev3lm-certificate',
				)
			);
		}
	}

	/**
	 * Block render.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public function render_block( $attrs ) {
		return $this->render( (int) ( $attrs['courseId'] ?? 0 ) );
	}

	/**
	 * Shortcode.
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'course' => 0 ), $atts, 'dev3lm_certificate' );
		wp_enqueue_style( 'dev3lm-certificate' );
		return $this->render( (int) $atts['course'] );
	}

	/**
	 * Resolves the course: explicit id, or the current post when it is a course (or a page course child).
	 *
	 * @param int $course Course id.
	 * @return int
	 */
	public static function course_id( $course ) {
		if ( $course > 0 ) {
			return $course;
		}
		$post = get_post();
		if ( ! $post ) {
			return 0;
		}
		if ( get_post_meta( $post->ID, Adapter::META_CERT, true ) ) {
			return (int) $post->ID;
		}
		if ( $post->post_parent && get_post_meta( $post->post_parent, Adapter::META_CERT, true ) ) {
			return (int) $post->post_parent;
		}
		return 0;
	}

	/**
	 * Whether a user completed a course.
	 *
	 * @param int $course  Course.
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function completed( $course, $user_id ) {
		$type      = get_post_type( $course );
		$completed = false;
		if ( 'courses' === $type && function_exists( 'tutor_utils' ) ) {
			$completed = (bool) tutor_utils()->is_completed_course( $course, $user_id );
		} elseif ( 'sfwd-courses' === $type && function_exists( 'learndash_course_completed' ) ) {
			$completed = (bool) learndash_course_completed( $user_id, $course );
		} elseif ( 'page' === $type ) {
			$completed = $user_id > 0;
		}
		/**
		 * Whether a learner may see the certificate of a course.
		 *
		 * @param bool $completed Completed.
		 * @param int  $course    Course id.
		 * @param int  $user_id   User id.
		 */
		return (bool) apply_filters( 'dev3lm_certificate_completed', $completed, $course, $user_id );
	}

	/**
	 * Fills {learner} {course} {date} {site}.
	 *
	 * @param string $text   Text.
	 * @param string $name   Learner.
	 * @param string $course Course title.
	 * @param string $date   Date.
	 * @return string
	 */
	public static function fill( $text, $name, $course, $date ) {
		return strtr(
			(string) $text,
			array(
				'{learner}' => $name,
				'{course}'  => $course,
				'{date}'    => $date,
				'{site}'    => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
	}

	/**
	 * Renders the certificate (or a neutral notice).
	 *
	 * @param int $course Course id (0 = current).
	 * @return string
	 */
	public function render( $course ) {
		wp_enqueue_style( 'dev3lm-certificate' );
		$course = self::course_id( $course );
		$cert   = $course ? json_decode( (string) get_post_meta( $course, Adapter::META_CERT, true ), true ) : null;
		if ( ! $course || ! is_array( $cert ) ) {
			return current_user_can( 'edit_posts' ) ? '<p class="dev3lm-cert-note">' . esc_html__( 'Certificate: choose a course that has certificate text.', 'dev3-ai-course-builder' ) . '</p>' : '';
		}
		$user    = wp_get_current_user();
		$preview = current_user_can( 'edit_post', $course );
		if ( ! $user->exists() ) {
			return '<p class="dev3lm-cert-note">' . esc_html__( 'Log in to see your certificate.', 'dev3-ai-course-builder' ) . '</p>';
		}
		if ( ! self::completed( $course, $user->ID ) && ! $preview ) {
			return '<p class="dev3lm-cert-note">' . esc_html__( 'Complete the course to unlock your certificate.', 'dev3-ai-course-builder' ) . '</p>';
		}
		$title = html_entity_decode( get_the_title( $course ), ENT_QUOTES );
		$name  = $user->display_name;
		$date  = wp_date( (string) get_option( 'date_format' ) );
		$head  = '' !== ( $cert['title'] ?? '' ) ? $cert['title'] : __( 'Certificate of Completion', 'dev3-ai-course-builder' );
		$body  = self::fill( $cert['body'] ?? '', $name, $title, $date );
		$is_ok = self::completed( $course, $user->ID );
		$html  = '<div class="dev3lm-cert" role="group" aria-label="' . esc_attr( $head ) . '">';
		$html .= '<div class="dev3lm-cert__frame">';
		if ( ! $is_ok ) {
			$html .= '<p class="dev3lm-cert__preview">' . esc_html__( 'Preview (only editors see this until the course is completed)', 'dev3-ai-course-builder' ) . '</p>';
		}
		$html .= '<p class="dev3lm-cert__eyebrow">' . esc_html( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</p>';
		$html .= '<h2 class="dev3lm-cert__title">' . esc_html( $head ) . '</h2>';
		$html .= '<p class="dev3lm-cert__name">' . esc_html( $name ) . '</p>';
		$html .= '<p class="dev3lm-cert__course">' . esc_html( $title ) . '</p>';
		if ( '' !== trim( $body ) ) {
			$html .= '<p class="dev3lm-cert__body">' . esc_html( $body ) . '</p>';
		}
		$html .= '<p class="dev3lm-cert__date">' . esc_html( $date ) . '</p>';
		$html .= '</div>';
		$html .= '<p class="dev3lm-cert__actions"><button type="button" class="dev3lm-cert__print" onclick="window.print()">' . esc_html__( 'Print or save as PDF', 'dev3-ai-course-builder' ) . '</button></p>';
		$html .= '</div>';
		return $html;
	}
}
