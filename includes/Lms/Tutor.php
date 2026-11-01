<?php
/**
 * Tutor LMS adapter: courses → topics (sections) → lessons, tutor_quiz with questions in Tutor's own
 * tables (saved through TUTOR\QuizBuilder when available).
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Lms;

defined( 'ABSPATH' ) || exit;

/**
 * Tutor LMS.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery
 */
class Tutor extends Adapter {

	const TYPE_MAP = array(
		'single'       => 'single_choice',
		'multiple'     => 'multiple_choice',
		'true_false'   => 'true_false',
		'fill_blank'   => 'fill_in_the_blank',
		'short_answer' => 'short_answer',
		'essay'        => 'open_ended',
		'ordering'     => 'ordering',
		'matching'     => 'matching',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'tutor';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'Tutor LMS';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function available() {
		return function_exists( 'tutor' ) && post_type_exists( $this->pt( 'course' ) );
	}

	/**
	 * Supports.
	 *
	 * @return array
	 */
	public function supports() {
		return array(
			'topics'       => false,
			'certificate'  => false,
			'section_quiz' => true,
			'types'        => array_keys( self::TYPE_MAP ),
		);
	}

	/**
	 * Tutor post type names (filterable inside Tutor).
	 *
	 * @param string $kind course|lesson|topic|quiz.
	 * @return string
	 */
	public function pt( $kind ) {
		$defaults = array(
			'course' => 'courses',
			'lesson' => 'lesson',
			'topic'  => 'topics',
			'quiz'   => 'tutor_quiz',
		);
		if ( function_exists( 'tutor' ) ) {
			$t   = tutor();
			$map = array(
				'course' => 'course_post_type',
				'lesson' => 'lesson_post_type',
				'topic'  => 'topics_post_type',
				'quiz'   => 'quiz_post_type',
			);
			$key = $map[ $kind ];
			if ( is_object( $t ) && ! empty( $t->$key ) ) {
				return (string) $t->$key;
			}
		}
		return $defaults[ $kind ];
	}

	/**
	 * Course.
	 *
	 * @param array $course Course.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error
	 */
	public function create_course( array $course, array $ctx ) {
		$id = $this->insert(
			array(
				'post_type'    => $this->pt( 'course' ),
				'post_title'   => $course['title'],
				'post_content' => $this->course_body( $course ),
				'post_excerpt' => $course['excerpt'],
			),
			$ctx
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_tutor_course_level', $course['level'] );
		update_post_meta( $id, '_tutor_course_price_type', 'free' );
		update_post_meta( $id, '_tutor_course_benefits', wp_slash( implode( "\n", $course['outcomes'] ) ) );
		update_post_meta( $id, '_tutor_course_requirements', wp_slash( implode( "\n", $course['requirements'] ) ) );
		update_post_meta( $id, '_tutor_course_target_audience', wp_slash( $course['audience'] ) );
		if ( $course['duration'] > 0 ) {
			update_post_meta(
				$id,
				'_course_duration',
				array(
					'hours'   => (string) floor( $course['duration'] / 60 ),
					'minutes' => (string) ( $course['duration'] % 60 ),
					'seconds' => '0',
				)
			);
		}
		return $id;
	}

	/**
	 * Section → Tutor topic.
	 *
	 * @param array $section Section.
	 * @param array $ctx     Context.
	 * @return int|\WP_Error
	 */
	public function create_section( array $section, array $ctx ) {
		return $this->insert(
			array(
				'post_type'    => $this->pt( 'topic' ),
				'post_title'   => $section['title'],
				'post_content' => $section['summary'],
				'post_parent'  => (int) $ctx['course_post'],
				'menu_order'   => (int) $ctx['order'] + 1,
				'post_status'  => 'publish', // Tutor topics are always published; the course status gates them.
			),
			$ctx
		);
	}

	/**
	 * Lesson.
	 *
	 * @param array $lesson Lesson.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error
	 */
	public function create_lesson( array $lesson, array $ctx ) {
		$id = $this->insert(
			array(
				'post_type'    => $this->pt( 'lesson' ),
				'post_title'   => $lesson['title'],
				'post_content' => $this->body( $lesson ),
				'post_parent'  => (int) $ctx['section_post'],
				'menu_order'   => (int) $ctx['order'] + 1,
				'post_status'  => 'publish',
			),
			$ctx
		);
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, '_tutor_course_id_for_lesson', (int) $ctx['course_post'] );
		}
		return $id;
	}

	/**
	 * Tutor has no sub-lessons: a topic becomes a lesson right after its parent lesson.
	 *
	 * @param array $topic Topic.
	 * @param array $ctx   Context.
	 * @return int|\WP_Error
	 */
	public function create_topic( array $topic, array $ctx ) {
		return $this->create_lesson( $topic, $ctx );
	}

	/**
	 * Quiz (inside the section's topic; a course quiz gets its own "Final assessment" topic).
	 *
	 * @param array $quiz Quiz.
	 * @param array $ctx  Context.
	 * @return int|\WP_Error
	 */
	public function create_quiz( array $quiz, array $ctx ) {
		$topic = (int) ( $ctx['section_post'] ?? 0 );
		if ( ! $topic ) {
			$topic = $this->insert(
				array(
					'post_type'   => $this->pt( 'topic' ),
					'post_title'  => '' !== $quiz['title'] ? $quiz['title'] : __( 'Final assessment', 'dev3-ai-course-builder' ),
					'post_parent' => (int) $ctx['course_post'],
					'menu_order'  => (int) $ctx['order'] + 1,
					'post_status' => 'publish',
				),
				array_merge( $ctx, array( 'node' => $quiz['id'] . '-topic' ) )
			);
			if ( is_wp_error( $topic ) ) {
				return $topic;
			}
		}
		return $this->insert_quiz( $quiz, $topic, (int) $ctx['order'] + 1, $ctx );
	}

	/**
	 * Inserts the quiz post, settings and questions.
	 *
	 * @param array $quiz       Quiz.
	 * @param int   $topic      Tutor topic id.
	 * @param int   $menu_order Order.
	 * @param array $ctx        Context.
	 * @return int|\WP_Error
	 */
	private function insert_quiz( array $quiz, $topic, $menu_order, array $ctx ) {
		$id = $this->insert(
			array(
				'post_type'    => $this->pt( 'quiz' ),
				'post_title'   => '' !== $quiz['title'] ? $quiz['title'] : __( 'Quiz', 'dev3-ai-course-builder' ),
				'post_content' => $quiz['description'],
				'post_parent'  => (int) $topic,
				'menu_order'   => (int) $menu_order,
				'post_status'  => 'publish',
			),
			$ctx
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$option                  = class_exists( '\TUTOR\Quiz' ) && method_exists( '\TUTOR\Quiz', 'get_default_quiz_settings' ) ? \TUTOR\Quiz::get_default_quiz_settings() : array(
			'time_limit'       => array(
				'time_type'  => 'minutes',
				'time_value' => 0,
			),
			'attempts_allowed' => 10,
			'questions_order'  => 'rand',
		);
		$option['passing_grade'] = (int) $quiz['passing'];
		update_post_meta( $id, 'tutor_quiz_option', $option );
		$this->save_questions( $id, $quiz['questions'] );
		do_action( 'tutor_initial_quiz_created', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Tutor's own hook, so Tutor add-ons see the new quiz.
		return $id;
	}

	/**
	 * Saves questions + answers in Tutor's tables.
	 *
	 * @param int   $quiz_id   Quiz.
	 * @param array $questions Normalised questions.
	 */
	public function save_questions( $quiz_id, array $questions ) {
		global $wpdb;
		$payload = array();
		foreach ( $questions as $q ) {
			$payload[] = $this->question_payload( $q );
		}
		if ( ! $payload ) {
			return;
		}
		$builder = class_exists( '\TUTOR\QuizBuilder' ) ? new \TUTOR\QuizBuilder( false ) : null;
		if ( $builder && method_exists( $builder, 'save_questions' ) ) {
			$builder->save_questions( $quiz_id, $payload );
		} else {
			$order = 0;
			foreach ( $payload as $q ) {
				$wpdb->insert(
					$wpdb->prefix . 'tutor_quiz_questions',
					array(
						'quiz_id'              => $quiz_id,
						'question_title'       => $q['question_title'],
						'question_description' => $q['question_description'],
						'question_type'        => $q['question_type'],
						'question_mark'        => $q['question_mark'],
						'question_settings'    => maybe_serialize( $q['question_settings'] ),
						'question_order'       => ++$order,
					)
				);
				$qid = (int) $wpdb->insert_id;
				$a   = 0;
				foreach ( $q['question_answers'] as $answer ) {
					$wpdb->insert(
						$wpdb->prefix . 'tutor_quiz_question_answers',
						array(
							'belongs_question_id'   => $qid,
							'belongs_question_type' => $q['question_type'],
							'answer_title'          => $answer['answer_title'],
							'is_correct'            => $answer['is_correct'],
							'answer_two_gap_match'  => $answer['answer_two_gap_match'],
							'answer_view_format'    => $answer['answer_view_format'],
							'answer_order'          => ++$a,
						)
					);
				}
			}
		}
		// QuizBuilder does not store explanations: add them in question order.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT question_id FROM {$wpdb->prefix}tutor_quiz_questions WHERE quiz_id = %d ORDER BY question_order ASC, question_id ASC", $quiz_id ) );
		foreach ( array_values( $questions ) as $i => $q ) {
			if ( isset( $ids[ $i ] ) && '' !== $q['explanation'] ) {
				$wpdb->update( $wpdb->prefix . 'tutor_quiz_questions', array( 'answer_explanation' => $q['explanation'] ), array( 'question_id' => (int) $ids[ $i ] ) );
			}
		}
	}

	/**
	 * Tutor question payload (QuizBuilder format).
	 *
	 * @param array $q Question.
	 * @return array
	 */
	public function question_payload( array $q ) {
		$type                      = self::TYPE_MAP[ $q['type'] ] ?? 'single_choice';
		$settings                  = class_exists( '\TUTOR\Quiz' ) && method_exists( '\TUTOR\Quiz', 'get_default_question_settings' ) ? \TUTOR\Quiz::get_default_question_settings( $type ) : array(
			'question_type' => $type,
			'question_mark' => 1,
		);
		$settings['question_mark'] = (int) $q['points'];
		if ( 'multiple_choice' === $type ) {
			$settings['has_multiple_correct_answer'] = '1';
		}
		$title   = $q['text'];
		$answers = array();
		$answer  = static function ( $title, $correct, $gap = '' ) {
			return array(
				'answer_title'         => $title,
				'is_correct'           => $correct ? 1 : 0,
				'answer_two_gap_match' => $gap,
				'answer_view_format'   => 'text',
				'image_id'             => null,
				'_data_status'         => 'new',
			);
		};
		switch ( $q['type'] ) {
			case 'fill_blank':
				$title     = __( 'Fill in the blanks', 'dev3-ai-course-builder' );
				$answers[] = $answer( str_replace( '{blank}', '{dash}', $q['text'] ), true, implode( '|', $this->blanks( $q ) ) );
				break;
			case 'matching':
				foreach ( $q['answers'] as $a ) {
					$answers[] = $answer( $a['text'], false, $a['match'] );
				}
				break;
			case 'short_answer':
			case 'essay':
				break;
			case 'ordering':
				foreach ( $q['answers'] as $a ) {
					$answers[] = $answer( $a['text'], false );
				}
				break;
			default:
				foreach ( $q['answers'] as $a ) {
					$answers[] = $answer( $a['text'], $a['correct'] );
				}
		}
		return array(
			'question_title'       => $title,
			'question_description' => '',
			'question_type'        => $type,
			'question_mark'        => (int) $q['points'],
			'question_settings'    => $settings,
			'question_answers'     => $answers,
			'_data_status'         => 'new',
		);
	}

	/**
	 * Lesson types.
	 *
	 * @return string[]
	 */
	public function lesson_types() {
		return array( $this->pt( 'lesson' ) );
	}

	/**
	 * Course of a lesson.
	 *
	 * @param int $lesson_id Lesson.
	 * @return int
	 */
	public function course_of( $lesson_id ) {
		$topic = (int) wp_get_post_parent_id( $lesson_id );
		return $topic ? (int) wp_get_post_parent_id( $topic ) : 0;
	}

	/**
	 * Adds a quiz after an existing lesson in its topic.
	 *
	 * @param int    $lesson_id Lesson.
	 * @param array  $quiz      Quiz.
	 * @param string $status    Status (Tutor quizzes are published; the course gates access).
	 * @return int|\WP_Error
	 */
	public function attach_quiz( $lesson_id, array $quiz, $status ) {
		unset( $status );
		$topic = (int) wp_get_post_parent_id( $lesson_id );
		if ( ! $topic || $this->pt( 'topic' ) !== get_post_type( $topic ) ) {
			return new \WP_Error( 'dev3lm_no_topic', __( 'This lesson is not inside a Tutor LMS topic, so the quiz has nowhere to go.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		return $this->insert_quiz( $quiz, $topic, (int) get_post_field( 'menu_order', $lesson_id ) + 1, array( 'node' => $quiz['id'] ) );
	}

	/**
	 * Deletes question rows of quizzes that are about to be deleted permanently.
	 *
	 * @param int[] $post_ids Posts.
	 */
	public function cleanup( array $post_ids ) {
		global $wpdb;
		foreach ( $post_ids as $id ) {
			if ( $this->pt( 'quiz' ) !== get_post_type( $id ) ) {
				continue;
			}
			$qids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT question_id FROM {$wpdb->prefix}tutor_quiz_questions WHERE quiz_id = %d", $id ) ) );
			foreach ( $qids as $qid ) {
				$wpdb->delete( $wpdb->prefix . 'tutor_quiz_question_answers', array( 'belongs_question_id' => $qid ) );
			}
			$wpdb->delete( $wpdb->prefix . 'tutor_quiz_questions', array( 'quiz_id' => (int) $id ) );
		}
	}

	/**
	 * Links (Tutor's own course builder for editing).
	 *
	 * @param int $course_id Course.
	 * @return array
	 */
	public function links( $course_id ) {
		return array(
			'edit' => add_query_arg(
				array(
					'page'      => 'create-course',
					'course_id' => (int) $course_id,
				),
				admin_url( 'admin.php' )
			),
			'view' => (string) get_permalink( $course_id ),
		);
	}
}
