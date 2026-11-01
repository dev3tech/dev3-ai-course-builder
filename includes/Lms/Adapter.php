<?php
/**
 * LMS adapter contract + shared helpers. An adapter turns tree nodes into the LMS's own objects.
 *
 * Context passed to create_*(): { blueprint, status, node, order, course_post, section_post,
 * lesson_post, last_lesson_post, tree }.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Lms;

use Dev3\AiCourseBuilder\Course\Markdown;

defined( 'ABSPATH' ) || exit;

/**
 * Base adapter.
 */
abstract class Adapter {

	const META_BLUEPRINT = '_dev3lm_blueprint';
	const META_NODE      = '_dev3lm_node';
	const META_CERT      = '_dev3lm_certificate';

	/**
	 * Adapter id (tutor, learndash, pages).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Display name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether the LMS is active.
	 *
	 * @return bool
	 */
	abstract public function available();

	/**
	 * Capabilities: { topics, certificate, section_quiz, types[] }.
	 *
	 * @return array
	 */
	abstract public function supports();

	/**
	 * Creates the course.
	 *
	 * @param array $course Course node.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error
	 */
	abstract public function create_course( array $course, array $ctx );

	/**
	 * Creates a section (0 = virtual section without its own post).
	 *
	 * @param array $section Section node.
	 * @param array $ctx     Context.
	 * @return int|\WP_Error
	 */
	abstract public function create_section( array $section, array $ctx );

	/**
	 * Creates a lesson.
	 *
	 * @param array $lesson Lesson node.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error
	 */
	abstract public function create_lesson( array $lesson, array $ctx );

	/**
	 * Creates a topic (sub-lesson).
	 *
	 * @param array $topic Topic node.
	 * @param array $ctx   Context.
	 * @return int|\WP_Error
	 */
	abstract public function create_topic( array $topic, array $ctx );

	/**
	 * Creates a quiz with its questions.
	 *
	 * @param array $quiz Quiz node.
	 * @param array $ctx  Context.
	 * @return int|\WP_Error
	 */
	abstract public function create_quiz( array $quiz, array $ctx );

	/**
	 * Post types listed by the quiz generator / rewriter for this LMS.
	 *
	 * @return string[]
	 */
	abstract public function lesson_types();

	/**
	 * Adds a new quiz to an existing lesson.
	 *
	 * @param int    $lesson_id Lesson post.
	 * @param array  $quiz      Quiz node.
	 * @param string $status    Post status.
	 * @return int|\WP_Error Quiz post id.
	 */
	abstract public function attach_quiz( $lesson_id, array $quiz, $status );

	/**
	 * Certificate (default: course meta only, printed by the DEV3 certificate block).
	 *
	 * @param array $course Course node.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error 0 when no post is created.
	 */
	public function create_certificate( array $course, array $ctx ) {
		unset( $course, $ctx );
		return 0;
	}

	/**
	 * Runs after every node exists (course steps, tables of contents…).
	 *
	 * @param int   $course_id Course post.
	 * @param array $map       node id → post id.
	 * @param array $tree      Tree.
	 * @param array $ctx       Context.
	 * @return true|\WP_Error
	 */
	public function finalize( $course_id, array $map, array $tree, array $ctx ) {
		unset( $course_id, $map, $tree, $ctx );
		return true;
	}

	/**
	 * Removes LMS data that lives outside posts (before posts are deleted permanently).
	 *
	 * @param int[] $post_ids Posts created by a build.
	 */
	public function cleanup( array $post_ids ) {
		unset( $post_ids );
	}

	/**
	 * Edit/view links for a created course.
	 *
	 * @param int $course_id Course post.
	 * @return array { edit, view }
	 */
	public function links( $course_id ) {
		return array(
			'edit' => (string) get_edit_post_link( $course_id, 'raw' ),
			'view' => (string) get_permalink( $course_id ),
		);
	}

	/**
	 * Course a lesson belongs to (0 when unknown).
	 *
	 * @param int $lesson_id Lesson.
	 * @return int
	 */
	public function course_of( $lesson_id ) {
		unset( $lesson_id );
		return 0;
	}

	/**
	 * Description for the status endpoint.
	 *
	 * @return array
	 */
	public function describe() {
		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'available' => $this->available(),
			'supports'  => $this->supports(),
			'types'     => $this->lesson_types(),
		);
	}

	/**
	 * Inserts a post tagged with the blueprint and node.
	 *
	 * @param array $postarr Post array.
	 * @param array $ctx     Context.
	 * @return int|\WP_Error
	 */
	protected function insert( array $postarr, array $ctx ) {
		$postarr               = array_merge(
			array(
				'post_status' => $ctx['status'] ?? 'draft',
				'post_author' => get_current_user_id(),
			),
			$postarr
		);
		$postarr['post_title'] = wp_slash( (string) $postarr['post_title'] );
		if ( isset( $postarr['post_content'] ) ) {
			$postarr['post_content'] = wp_slash( (string) $postarr['post_content'] );
		}
		if ( isset( $postarr['post_excerpt'] ) ) {
			$postarr['post_excerpt'] = wp_slash( (string) $postarr['post_excerpt'] );
		}
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! empty( $ctx['blueprint'] ) ) {
			update_post_meta( $id, self::META_BLUEPRINT, (int) $ctx['blueprint'] );
		}
		if ( ! empty( $ctx['node'] ) ) {
			update_post_meta( $id, self::META_NODE, sanitize_key( $ctx['node'] ) );
		}
		return (int) $id;
	}

	/**
	 * Lesson body: content, or the summary when nothing was written yet.
	 *
	 * @param array $node Lesson/topic node.
	 * @return string Block markup.
	 */
	protected function body( array $node ) {
		$md = '' !== trim( (string) $node['content'] ) ? $node['content'] : (string) ( $node['summary'] ?? '' );
		return Markdown::to_blocks( $md );
	}

	/**
	 * Course description blocks (+ outcomes list).
	 *
	 * @param array $course     Course node.
	 * @param bool  $with_lists Also print outcomes/requirements.
	 * @return string
	 */
	protected function course_body( array $course, $with_lists = false ) {
		$md = (string) $course['description'];
		if ( $with_lists && $course['outcomes'] ) {
			$md .= "\n\n## " . __( 'What you will learn', 'dev3-ai-course-builder' ) . "\n\n- " . implode( "\n- ", $course['outcomes'] );
		}
		if ( $with_lists && $course['requirements'] ) {
			$md .= "\n\n## " . __( 'Requirements', 'dev3-ai-course-builder' ) . "\n\n- " . implode( "\n- ", $course['requirements'] );
		}
		return Markdown::to_blocks( $md );
	}

	/**
	 * Fill-in-the-blank answers in order.
	 *
	 * @param array $q Question.
	 * @return string[]
	 */
	protected function blanks( array $q ) {
		return array_values( wp_list_pluck( $q['answers'], 'text' ) );
	}

	/**
	 * Stores the certificate text on the course (all adapters).
	 *
	 * @param int   $course_id Course post.
	 * @param array $course    Course node.
	 */
	public static function store_certificate( $course_id, array $course ) {
		if ( '' !== $course['certificate']['body'] || '' !== $course['certificate']['title'] ) {
			update_post_meta( $course_id, self::META_CERT, wp_slash( (string) wp_json_encode( $course['certificate'] ) ) );
		}
	}
}
