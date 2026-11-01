<?php
/**
 * Tools for existing lessons: quiz generator and lesson rewriter (with undo).
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Rest;

use Dev3\AiCourseBuilder\Ai\Generator;
use Dev3\AiCourseBuilder\Ai\Prompt_Library;
use Dev3\AiCourseBuilder\Core3\Rest\Base_Controller;
use Dev3\AiCourseBuilder\Course\Basic_Quiz;
use Dev3\AiCourseBuilder\Course\Markdown;
use Dev3\AiCourseBuilder\Course\Tree;
use Dev3\AiCourseBuilder\Data\Rewrites;
use Dev3\AiCourseBuilder\Lms\Adapters;
use Dev3\AiCourseBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3lm/v1.
 */
class Tools_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$routes = array(
			'/lessons'                   => array( 'GET', 'lessons' ),
			'/lessons/(?P<id>\d+)'       => array( 'GET', 'lesson' ),
			'/tools/quiz'                => array( 'POST', 'quiz' ),
			'/tools/quiz/create'         => array( 'POST', 'quiz_create' ),
			'/tools/rewrite'             => array( 'POST', 'rewrite' ),
			'/tools/rewrite/apply'       => array( 'POST', 'rewrite_apply' ),
			'/rewrites'                  => array( 'GET', 'rewrites' ),
			'/rewrites/(?P<id>\d+)/undo' => array( 'POST', 'undo' ),
		);
		foreach ( $routes as $path => $h ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $h[0],
					'callback'            => array( $this, $h[1] ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}
	}

	/**
	 * Every post type the tools can work on (active LMS lesson types + pages/posts).
	 *
	 * @return string[]
	 */
	public static function lesson_types() {
		$types = array();
		foreach ( Adapters::all() as $a ) {
			if ( $a->available() ) {
				$types = array_merge( $types, $a->lesson_types() );
			}
		}
		return array_values( array_unique( array_filter( $types, 'post_type_exists' ) ) );
	}

	/**
	 * Loads a lesson the user may edit.
	 *
	 * @param int $id Post id.
	 * @return \WP_Post|\WP_Error
	 */
	private function post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, self::lesson_types(), true ) || 'trash' === $post->post_status ) {
			return new \WP_Error( 'dev3lm_lesson', __( 'Choose a lesson first.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'dev3lm_forbidden', __( 'You cannot edit this lesson.', 'dev3-ai-course-builder' ), array( 'status' => 403 ) );
		}
		return $post;
	}

	/**
	 * Lesson row.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private function row( \WP_Post $post ) {
		$adapter = Adapters::for_post_type( $post->post_type );
		$course  = $adapter ? $adapter->course_of( $post->ID ) : 0;
		$type    = get_post_type_object( $post->post_type );
		return array(
			'id'         => $post->ID,
			'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
			'type'       => $post->post_type,
			'type_label' => $type ? (string) $type->labels->singular_name : $post->post_type,
			'lms'        => $adapter ? $adapter->id() : 'pages',
			'lms_label'  => $adapter ? $adapter->label() : '',
			'course'     => $course ? html_entity_decode( get_the_title( $course ), ENT_QUOTES ) : '',
			'status'     => $post->post_status,
			'words'      => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'edit'       => (string) get_edit_post_link( $post->ID, 'raw' ),
			'view'       => (string) get_permalink( $post->ID ),
			'modified'   => mysql_to_rfc3339( $post->post_modified_gmt ),
		);
	}

	/**
	 * GET lessons { search, type, page }.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function lessons( \WP_REST_Request $r ) {
		$types = self::lesson_types();
		$type  = sanitize_key( (string) $r['type'] );
		if ( $type && in_array( $type, $types, true ) ) {
			$types = array( $type );
		}
		$q     = new \WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				's'              => sanitize_text_field( (string) $r['search'] ),
				'posts_per_page' => 20,
				'paged'          => max( 1, (int) $r['page'] ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = $this->row( $post );
		}
		$labels = array();
		foreach ( self::lesson_types() as $t ) {
			$obj      = get_post_type_object( $t );
			$labels[] = array(
				'value' => $t,
				'label' => $obj ? (string) $obj->labels->name : $t,
			);
		}
		return $this->respond(
			array(
				'items' => $items,
				'total' => (int) $q->found_posts,
				'types' => $labels,
			)
		);
	}

	/**
	 * GET lessons/<id>: row + content as Markdown-lite.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function lesson( \WP_REST_Request $r ) {
		$post = $this->post( $r['id'] );
		if ( is_wp_error( $post ) ) {
			return $this->respond( $post );
		}
		return $this->respond(
			array_merge(
				$this->row( $post ),
				array(
					'markdown' => Markdown::from_html( $post->post_content ),
					'history'  => Rewrites::recent( $post->ID, 10 ),
				)
			)
		);
	}

	/**
	 * POST tools/quiz { lesson_id, count, types, mode }.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function quiz( \WP_REST_Request $r ) {
		$post = $this->post( $r['lesson_id'] );
		if ( is_wp_error( $post ) ) {
			return $this->respond( $post );
		}
		$adapter  = Adapters::for_post_type( $post->post_type );
		$allowed  = $adapter ? $adapter->supports()['types'] : Settings::QUESTION_TYPES;
		$types    = is_array( $r['types'] ) ? array_map( 'strval', $r['types'] ) : (array) Settings::get( 'question_types', array( 'single' ) );
		$types    = array_values( array_intersect( $types, $allowed ) );
		$count    = $r['count'] ? (int) $r['count'] : (int) Settings::get( 'questions_per_quiz', 5 );
		$material = '# ' . get_the_title( $post ) . "\n\n" . Markdown::from_html( $post->post_content );
		if ( 'basic' === $r['mode'] ) {
			$questions = Basic_Quiz::from_text( $material, $count, $types ? $types : array( 'fill_blank', 'true_false' ) );
			if ( ! $questions ) {
				return $this->respond( new \WP_Error( 'dev3lm_empty', __( 'Not enough lesson text for basic questions. Add questions by hand.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) ) );
			}
			return $this->respond(
				array(
					'questions' => $questions,
					'basic'     => true,
					'model'     => '',
					'tokens'    => 0,
					'cached'    => false,
				)
			);
		}
		$course = $adapter ? $adapter->course_of( $post->ID ) : 0;
		return $this->respond(
			Generator::quiz(
				$material,
				array(
					'count'  => $count,
					'types'  => $types,
					'title'  => get_the_title( $post ),
					'course' => $course ? get_the_title( $course ) : '',
				)
			)
		);
	}

	/**
	 * POST tools/quiz/create { lesson_id, title, questions, passing, status }.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function quiz_create( \WP_REST_Request $r ) {
		$post = $this->post( $r['lesson_id'] );
		if ( is_wp_error( $post ) ) {
			return $this->respond( $post );
		}
		$adapter = Adapters::for_post_type( $post->post_type );
		if ( ! $adapter ) {
			return $this->respond( new \WP_Error( 'dev3lm_lms', __( 'No LMS handles this lesson type.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) ) );
		}
		$quiz = Tree::quiz(
			array(
				/* translators: %s: lesson title */
				'title'     => '' !== trim( (string) $r['title'] ) ? $r['title'] : sprintf( __( 'Quiz: %s', 'dev3-ai-course-builder' ), get_the_title( $post ) ),
				'passing'   => $r['passing'] ? (int) $r['passing'] : (int) Settings::get( 'passing_grade', 70 ),
				'questions' => is_array( $r['questions'] ) ? $r['questions'] : array(),
			)
		);
		if ( ! $quiz['questions'] ) {
			return $this->respond( new \WP_Error( 'dev3lm_empty', __( 'Add at least one question.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) ) );
		}
		$status = in_array( $r['status'], array( 'draft', 'publish', 'pending', 'private' ), true ) ? $r['status'] : (string) Settings::get( 'post_status', 'draft' );
		$id     = $adapter->attach_quiz( $post->ID, $quiz, $status );
		if ( is_wp_error( $id ) ) {
			return $this->respond( $id );
		}
		update_post_meta( $id, '_dev3lm_source_lesson', $post->ID );
		return $this->respond(
			array(
				'quiz_id'   => (int) $id,
				'title'     => get_the_title( $id ),
				'questions' => count( $quiz['questions'] ),
				'edit'      => (string) get_edit_post_link( $id, 'raw' ),
				'view'      => (string) get_permalink( $id ),
				'lms'       => $adapter->label(),
			),
			201
		);
	}

	/**
	 * POST tools/rewrite { lesson_id, mode, extra, tone, reading_level, target_language }.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function rewrite( \WP_REST_Request $r ) {
		$post = $this->post( $r['lesson_id'] );
		if ( is_wp_error( $post ) ) {
			return $this->respond( $post );
		}
		$md    = Markdown::from_html( $post->post_content );
		$draft = Generator::rewrite(
			get_the_title( $post ),
			$md,
			sanitize_key( (string) $r['mode'] ),
			array(
				'extra'           => sanitize_textarea_field( (string) $r['extra'] ),
				'tone'            => in_array( $r['tone'], Settings::TONES, true ) ? $r['tone'] : Settings::get( 'tone', 'friendly' ),
				'reading_level'   => mb_substr( sanitize_text_field( (string) ( $r['reading_level'] ? $r['reading_level'] : 'grade 8 (plain English)' ) ), 0, 60 ),
				'target_language' => mb_substr( sanitize_text_field( (string) ( $r['target_language'] ? $r['target_language'] : 'Spanish' ) ), 0, 40 ),
			)
		);
		if ( is_wp_error( $draft ) ) {
			return $this->respond( $draft );
		}
		$draft['original'] = $md;
		return $this->respond( $draft );
	}

	/**
	 * POST tools/rewrite/apply { lesson_id, content, mode }: saves native blocks + history.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function rewrite_apply( \WP_REST_Request $r ) {
		$post = $this->post( $r['lesson_id'] );
		if ( is_wp_error( $post ) ) {
			return $this->respond( $post );
		}
		$md = trim( str_replace( "\r", '', (string) $r['content'] ) );
		if ( '' === $md ) {
			return $this->respond( new \WP_Error( 'dev3lm_empty', __( 'The rewritten lesson is empty.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) ) );
		}
		$mode   = array_key_exists( (string) $r['mode'], Prompt_Library::rewrite_modes() ) ? (string) $r['mode'] : 'manual';
		$blocks = Markdown::to_blocks( mb_substr( $md, 0, Tree::MAX_CONTENT ) );
		$before = $post->post_content;
		$ok     = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $blocks ),
			),
			true
		);
		if ( is_wp_error( $ok ) ) {
			return $this->respond( $ok );
		}
		$log = Rewrites::log( $post->ID, $mode, $before, $blocks );
		return $this->respond(
			array(
				'history_id' => $log,
				'lesson'     => $this->row( get_post( $post->ID ) ),
				'history'    => Rewrites::recent( $post->ID, 10 ),
			)
		);
	}

	/**
	 * GET rewrites.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function rewrites( \WP_REST_Request $r ) {
		return $this->respond( array( 'items' => Rewrites::recent( (int) $r['post_id'], 50 ) ) );
	}

	/**
	 * POST rewrites/<id>/undo: restores the content before the rewrite (latest rewrite of a lesson only,
	 * and only while the lesson still holds the rewritten content unless force is set).
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return \WP_REST_Response
	 */
	public function undo( \WP_REST_Request $r ) {
		$row = Rewrites::get( (int) $r['id'] );
		if ( ! $row || ! empty( $row['undone_at'] ) ) {
			return $this->respond( new \WP_Error( 'dev3lm_not_found', __( 'Nothing to undo.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) ) );
		}
		$post = $this->post( (int) $row['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $this->respond( $post );
		}
		if ( ! $r['force'] && trim( $post->post_content ) !== trim( $row['after_content'] ) ) {
			return $this->respond( new \WP_Error( 'dev3lm_changed', __( 'The lesson was edited after this rewrite. Undo anyway to restore the older version?', 'dev3-ai-course-builder' ), array( 'status' => 409 ) ) );
		}
		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => wp_slash( $row['before_content'] ),
			)
		);
		Rewrites::mark_undone( (int) $row['id'] );
		return $this->respond(
			array(
				'undone'  => true,
				'history' => Rewrites::recent( $post->ID, 10 ),
				'items'   => Rewrites::recent( 0, 50 ),
			)
		);
	}
}
