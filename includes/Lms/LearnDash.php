<?php
/**
 * LearnDash adapter (LearnDash is a paid plugin: coded against its documented post types, the
 * learndash_update_setting() API, the course-steps model and the ProQuiz models; tested with stubs).
 *
 * - sfwd-courses / sfwd-lessons / sfwd-topic / sfwd-quiz / sfwd-question / sfwd-certificates
 * - relations: learndash_update_setting( $post, 'course'|'lesson', $id ) + course_id / lesson_id meta
 * - course builder: LDLMS_Factory_Post::course_steps( $course )->set_steps( $h ) (or ld_course_steps meta)
 * - section headings: course_sections meta (JSON)
 * - questions: WpProQuiz_Model_Question + WpProQuiz_Model_AnswerTypes, saved by WpProQuiz_Model_QuestionMapper
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Lms;

defined( 'ABSPATH' ) || exit;

/**
 * LearnDash LMS.
 */
class LearnDash extends Adapter {

	const TYPE_MAP = array(
		'single'       => 'single',
		'multiple'     => 'multiple',
		'true_false'   => 'single',
		'fill_blank'   => 'cloze_answer',
		'short_answer' => 'free_answer',
		'essay'        => 'essay',
		'ordering'     => 'sort_answer',
		'matching'     => 'matrix_sort_answer',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'learndash';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return 'LearnDash';
	}

	/**
	 * Active?
	 *
	 * @return bool
	 */
	public function available() {
		return defined( 'LEARNDASH_VERSION' ) && post_type_exists( 'sfwd-courses' );
	}

	/**
	 * Supports.
	 *
	 * @return array
	 */
	public function supports() {
		return array(
			'topics'       => true,
			'certificate'  => true,
			'section_quiz' => false,
			'types'        => array_keys( self::TYPE_MAP ),
		);
	}

	/**
	 * Writes a LearnDash setting (and the relation meta LearnDash queries by).
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Setting key (course, lesson, quiz, certificate…).
	 * @param mixed  $value   Value.
	 */
	public function setting( $post_id, $key, $value ) {
		if ( function_exists( 'learndash_update_setting' ) ) {
			learndash_update_setting( $post_id, $key, $value );
		} else {
			$type                       = (string) get_post_type( $post_id );
			$meta                       = (array) get_post_meta( $post_id, '_' . $type, true );
			$meta[ $type . '_' . $key ] = $value;
			update_post_meta( $post_id, '_' . $type, $meta );
		}
		if ( in_array( $key, array( 'course', 'lesson' ), true ) ) {
			update_post_meta( $post_id, $key . '_id', (int) $value );
		}
		if ( 'course' === $key && $value ) {
			update_post_meta( $post_id, 'ld_course_' . (int) $value, (int) $value );
		}
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
				'post_type'    => 'sfwd-courses',
				'post_title'   => $course['title'],
				'post_content' => $this->course_body( $course, true ),
				'post_excerpt' => $course['excerpt'],
			),
			$ctx
		);
		if ( ! is_wp_error( $id ) ) {
			$this->setting( $id, 'course_price_type', 'open' );
			if ( $course['requirements'] ) {
				$this->setting( $id, 'course_prerequisite_enabled', '' );
			}
		}
		return $id;
	}

	/**
	 * Sections are headings in the course builder (no post).
	 *
	 * @param array $section Section.
	 * @param array $ctx     Context.
	 * @return int
	 */
	public function create_section( array $section, array $ctx ) {
		unset( $section, $ctx );
		return 0;
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
				'post_type'    => 'sfwd-lessons',
				'post_title'   => $lesson['title'],
				'post_content' => $this->body( $lesson ),
				'post_excerpt' => mb_substr( (string) strtok( (string) $lesson['summary'], "\n" ), 0, 300 ),
				'menu_order'   => (int) $ctx['order'] + 1,
			),
			$ctx
		);
		if ( ! is_wp_error( $id ) ) {
			$this->setting( $id, 'course', (int) $ctx['course_post'] );
		}
		return $id;
	}

	/**
	 * Topic.
	 *
	 * @param array $topic Topic.
	 * @param array $ctx   Context.
	 * @return int|\WP_Error
	 */
	public function create_topic( array $topic, array $ctx ) {
		$id = $this->insert(
			array(
				'post_type'    => 'sfwd-topic',
				'post_title'   => $topic['title'],
				'post_content' => $this->body( $topic ),
				'menu_order'   => (int) $ctx['order'] + 1,
			),
			$ctx
		);
		if ( ! is_wp_error( $id ) ) {
			$this->setting( $id, 'course', (int) $ctx['course_post'] );
			$this->setting( $id, 'lesson', (int) $ctx['lesson_post'] );
		}
		return $id;
	}

	/**
	 * Quiz. LearnDash has no section quizzes: a section quiz becomes a lesson quiz of the section's
	 * last lesson; a course quiz is a course-level (final) quiz.
	 *
	 * @param array $quiz Quiz.
	 * @param array $ctx  Context.
	 * @return int|\WP_Error
	 */
	public function create_quiz( array $quiz, array $ctx ) {
		$lesson = (int) ( $ctx['lesson_post'] ?? 0 );
		if ( ! $lesson && ! empty( $ctx['section_quiz'] ) ) {
			$lesson = (int) ( $ctx['last_lesson_post'] ?? 0 );
		}
		return $this->insert_quiz( $quiz, (int) $ctx['course_post'], $lesson, (int) $ctx['order'] + 1, $ctx );
	}

	/**
	 * Inserts quiz + ProQuiz master + questions.
	 *
	 * @param array $quiz       Quiz.
	 * @param int   $course     Course.
	 * @param int   $lesson     Lesson/topic (0 = course quiz).
	 * @param int   $menu_order Order.
	 * @param array $ctx        Context.
	 * @return int|\WP_Error
	 */
	private function insert_quiz( array $quiz, $course, $lesson, $menu_order, array $ctx ) {
		$id = $this->insert(
			array(
				'post_type'    => 'sfwd-quiz',
				'post_title'   => '' !== $quiz['title'] ? $quiz['title'] : __( 'Quiz', 'dev3-ai-course-builder' ),
				'post_content' => $quiz['description'],
				'menu_order'   => (int) $menu_order,
			),
			$ctx
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$this->setting( $id, 'course', (int) $course );
		$this->setting( $id, 'lesson', (int) $lesson );
		$this->setting( $id, 'passingpercentage', (int) $quiz['passing'] );
		$pro_id = 0;
		if ( class_exists( '\WpProQuiz_Model_Quiz' ) && class_exists( '\WpProQuiz_Model_QuizMapper' ) ) {
			$model = new \WpProQuiz_Model_Quiz();
			$model->setName( get_the_title( $id ) );
			$model->setText( 'AAZZAAZZ' );
			$model->setTitleHidden( true );
			$saved  = ( new \WpProQuiz_Model_QuizMapper() )->save( $model );
			$pro_id = (int) ( is_object( $saved ) && method_exists( $saved, 'getId' ) ? $saved->getId() : $model->getId() );
			update_post_meta( $id, 'quiz_pro_id', $pro_id );
			update_post_meta( $id, 'quiz_pro_id_' . $pro_id, $pro_id );
			$this->setting( $id, 'quiz_pro', $pro_id );
		}
		$this->add_questions( $id, $pro_id, $quiz['questions'], $ctx );
		return $id;
	}

	/**
	 * Creates question posts (+ ProQuiz questions) and links them to the quiz.
	 *
	 * @param int   $quiz_id   Quiz post.
	 * @param int   $pro_id    ProQuiz master id.
	 * @param array $questions Questions.
	 * @param array $ctx       Context.
	 */
	private function add_questions( $quiz_id, $pro_id, array $questions, array $ctx ) {
		$links = array();
		$sort  = 0;
		foreach ( $questions as $q ) {
			$type   = self::TYPE_MAP[ $q['type'] ] ?? 'single';
			$q_post = $this->insert(
				array(
					'post_type'    => 'sfwd-question',
					'post_title'   => mb_substr( wp_strip_all_tags( str_replace( '{blank}', '___', $q['text'] ) ), 0, 180 ),
					'post_content' => wpautop( esc_html( $this->question_text( $q ) ) ),
					'menu_order'   => ++$sort,
				),
				array_merge( $ctx, array( 'node' => $q['id'] ) )
			);
			if ( is_wp_error( $q_post ) ) {
				continue;
			}
			$this->setting( $q_post, 'quiz', $quiz_id );
			update_post_meta( $q_post, 'quiz_id', $quiz_id );
			update_post_meta( $q_post, 'question_type', $type );
			update_post_meta( $q_post, 'question_points', (int) $q['points'] );
			$pro_q = 0;
			if ( $pro_id && class_exists( '\WpProQuiz_Model_Question' ) && class_exists( '\WpProQuiz_Model_QuestionMapper' ) ) {
				$model = new \WpProQuiz_Model_Question();
				$model->setQuizId( $pro_id );
				$model->setTitle( get_the_title( $q_post ) );
				$model->setQuestion( $this->question_text( $q ) );
				$model->setPoints( (int) $q['points'] );
				$model->setAnswerType( $type );
				$model->setSort( $sort );
				$model->setCorrectMsg( $q['explanation'] );
				$model->setIncorrectMsg( $q['explanation'] );
				$model->setCorrectSameText( true );
				$model->setAnswerData( $this->answer_data( $q ) );
				$saved = ( new \WpProQuiz_Model_QuestionMapper() )->save( $model );
				$pro_q = (int) ( is_object( $saved ) && method_exists( $saved, 'getId' ) ? $saved->getId() : $model->getId() );
				update_post_meta( $q_post, 'question_pro_id', $pro_q );
			} else {
				// Without ProQuiz the answers are still kept so nothing is lost.
				update_post_meta( $q_post, '_dev3lm_answers', wp_slash( (string) wp_json_encode( $q['answers'] ) ) );
			}
			$links[ $q_post ] = $pro_q;
		}
		update_post_meta( $quiz_id, 'ld_quiz_questions', $links );
	}

	/**
	 * Question text in LearnDash's format (cloze: answers in braces).
	 *
	 * @param array $q Question.
	 * @return string
	 */
	public function question_text( array $q ) {
		if ( 'fill_blank' !== $q['type'] ) {
			return $q['text'];
		}
		$blanks = $this->blanks( $q );
		$i      = 0;
		return (string) preg_replace_callback(
			'/\{blank\}/',
			static function () use ( $blanks, &$i ) {
				$word = $blanks[ $i ] ?? '';
				++$i;
				return '{' . $word . '}';
			},
			$q['text']
		);
	}

	/**
	 * ProQuiz answer models (WpProQuiz_Model_AnswerTypes) for a question.
	 *
	 * @param array $q Question.
	 * @return array
	 */
	public function answer_data( array $q ) {
		$make = static function ( $text, $correct, $sort = '' ) {
			$a = new \WpProQuiz_Model_AnswerTypes();
			$a->setAnswer( $text );
			$a->setCorrect( (bool) $correct );
			$a->setPoints( $correct ? 1 : 0 );
			$a->setSortString( $sort );
			return $a;
		};
		switch ( $q['type'] ) {
			case 'fill_blank':
				return array( $make( $this->question_text( $q ), true ) );
			case 'short_answer':
				return array( $make( implode( "\n", wp_list_pluck( $q['answers'], 'text' ) ), true ) );
			case 'essay':
				$a = $make( '', true );
				if ( method_exists( $a, 'setGraded' ) ) {
					$a->setGraded( true );
					$a->setGradedType( 'text' );
					$a->setGradingProgression( 'not-graded-none' );
				}
				return array( $a );
			case 'matching':
				$out = array();
				foreach ( $q['answers'] as $a ) {
					$out[] = $make( $a['text'], true, $a['match'] );
				}
				return $out;
			case 'ordering':
				$out = array();
				foreach ( $q['answers'] as $a ) {
					$out[] = $make( $a['text'], true );
				}
				return $out;
			default:
				$out = array();
				foreach ( $q['answers'] as $a ) {
					$out[] = $make( $a['text'], $a['correct'] );
				}
				return $out;
		}
	}

	/**
	 * Certificate post, linked as the course certificate.
	 *
	 * @param array $course Course.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error
	 */
	public function create_certificate( array $course, array $ctx ) {
		$body = strtr(
			esc_html( $course['certificate']['body'] ),
			array(
				'{learner}' => '[usermeta field="display_name"]',
				'{course}'  => '[courseinfo show="course_title"]',
				'{date}'    => '[courseinfo show="completed_on" format="F j, Y"]',
			)
		);
		$id   = $this->insert(
			array(
				'post_type'    => 'sfwd-certificates',
				'post_title'   => '' !== $course['certificate']['title'] ? $course['certificate']['title'] . ' — ' . $course['title'] : $course['title'],
				'post_content' => '<!-- wp:heading {"textAlign":"center"} -->' . "\n" . '<h2 class="wp-block-heading has-text-align-center">' . esc_html( $course['certificate']['title'] ) . '</h2>' . "\n<!-- /wp:heading -->\n\n" . '<!-- wp:paragraph {"align":"center"} -->' . "\n" . '<p class="has-text-align-center">' . $body . "</p>\n<!-- /wp:paragraph -->",
				'post_status'  => 'publish',
			),
			$ctx
		);
		if ( ! is_wp_error( $id ) ) {
			$this->setting( (int) $ctx['course_post'], 'certificate', $id );
		}
		return $id;
	}

	/**
	 * Course steps + section headings.
	 *
	 * @param int   $course_id Course.
	 * @param array $map       Map.
	 * @param array $tree      Tree.
	 * @param array $ctx       Context.
	 * @return true
	 */
	public function finalize( $course_id, array $map, array $tree, array $ctx ) {
		unset( $ctx );
		$h        = array(
			'sfwd-lessons' => array(),
			'sfwd-quiz'    => array(),
		);
		$sections = array();
		$position = 0;
		foreach ( $tree['sections'] as $si => $s ) {
			$sections[] = array(
				'order'      => $position,
				'ID'         => (int) ( crc32( $s['id'] ) % 1000000 ) + $si,
				'post_title' => $s['title'],
				'url'        => '',
				'edit_link'  => '',
				'type'       => 'section-heading',
				'expanded'   => true,
				'tree'       => array(),
			);
			$last       = null;
			foreach ( $s['lessons'] as $l ) {
				$lid = (int) ( $map[ $l['id'] ] ?? 0 );
				if ( ! $lid ) {
					continue;
				}
				$entry = array(
					'sfwd-topic' => array(),
					'sfwd-quiz'  => array(),
				);
				foreach ( $l['topics'] as $t ) {
					if ( ! empty( $map[ $t['id'] ] ) ) {
						$entry['sfwd-topic'][ (int) $map[ $t['id'] ] ] = array( 'sfwd-quiz' => array() );
					}
				}
				if ( $l['quiz'] && ! empty( $map[ $l['quiz']['id'] ] ) ) {
					$entry['sfwd-quiz'][ (int) $map[ $l['quiz']['id'] ] ] = array();
				}
				$h['sfwd-lessons'][ $lid ] = $entry;
				$last                      = $lid;
				++$position;
			}
			if ( $s['quiz'] && ! empty( $map[ $s['quiz']['id'] ] ) && $last ) {
				$h['sfwd-lessons'][ $last ]['sfwd-quiz'][ (int) $map[ $s['quiz']['id'] ] ] = array();
			}
		}
		if ( $tree['quiz'] && ! empty( $map[ $tree['quiz']['id'] ] ) ) {
			$h['sfwd-quiz'][ (int) $map[ $tree['quiz']['id'] ] ] = array();
		}
		$this->set_steps( $course_id, $h );
		update_post_meta( $course_id, 'course_sections', wp_slash( (string) wp_json_encode( $sections ) ) );
		return true;
	}

	/**
	 * Writes the course steps through LearnDash's model (or the meta it reads).
	 *
	 * @param int   $course_id Course.
	 * @param array $h         Hierarchy.
	 */
	public function set_steps( $course_id, array $h ) {
		if ( class_exists( '\LDLMS_Factory_Post' ) && method_exists( '\LDLMS_Factory_Post', 'course_steps' ) ) {
			$steps = \LDLMS_Factory_Post::course_steps( (int) $course_id );
			if ( is_object( $steps ) && method_exists( $steps, 'set_steps' ) ) {
				$steps->set_steps( $h );
				return;
			}
		}
		update_post_meta(
			$course_id,
			'ld_course_steps',
			array(
				'steps'                       => array( 'h' => $h ),
				'course_id'                   => (int) $course_id,
				'version'                     => defined( 'LEARNDASH_VERSION' ) ? LEARNDASH_VERSION : '',
				'empty'                       => empty( $h['sfwd-lessons'] ) && empty( $h['sfwd-quiz'] ),
				'course_builder_enabled'      => true,
				'course_shared_steps_enabled' => false,
			)
		);
	}

	/**
	 * Reads the stored hierarchy.
	 *
	 * @param int $course_id Course.
	 * @return array
	 */
	public function get_steps( $course_id ) {
		if ( class_exists( '\LDLMS_Factory_Post' ) && method_exists( '\LDLMS_Factory_Post', 'course_steps' ) ) {
			$steps = \LDLMS_Factory_Post::course_steps( (int) $course_id );
			if ( is_object( $steps ) && method_exists( $steps, 'get_steps' ) ) {
				return (array) $steps->get_steps( 'h' );
			}
		}
		$meta = get_post_meta( $course_id, 'ld_course_steps', true );
		return isset( $meta['steps']['h'] ) ? (array) $meta['steps']['h'] : array(
			'sfwd-lessons' => array(),
			'sfwd-quiz'    => array(),
		);
	}

	/**
	 * Lesson types.
	 *
	 * @return string[]
	 */
	public function lesson_types() {
		return array( 'sfwd-lessons', 'sfwd-topic' );
	}

	/**
	 * Course of a lesson/topic.
	 *
	 * @param int $lesson_id Lesson.
	 * @return int
	 */
	public function course_of( $lesson_id ) {
		if ( function_exists( 'learndash_get_course_id' ) ) {
			return (int) learndash_get_course_id( $lesson_id );
		}
		return (int) get_post_meta( $lesson_id, 'course_id', true );
	}

	/**
	 * Adds a lesson/topic quiz to an existing lesson and to the course steps.
	 *
	 * @param int    $lesson_id Lesson/topic.
	 * @param array  $quiz      Quiz.
	 * @param string $status    Status.
	 * @return int|\WP_Error
	 */
	public function attach_quiz( $lesson_id, array $quiz, $status ) {
		$course = $this->course_of( $lesson_id );
		$id     = $this->insert_quiz(
			$quiz,
			$course,
			$lesson_id,
			(int) get_post_field( 'menu_order', $lesson_id ) + 1,
			array(
				'status' => $status,
				'node'   => $quiz['id'],
			)
		);
		if ( is_wp_error( $id ) || ! $course ) {
			return $id;
		}
		$h    = $this->get_steps( $course );
		$type = get_post_type( $lesson_id );
		if ( 'sfwd-lessons' === $type && isset( $h['sfwd-lessons'][ $lesson_id ] ) ) {
			$h['sfwd-lessons'][ $lesson_id ]['sfwd-quiz'][ $id ] = array();
		} elseif ( 'sfwd-topic' === $type ) {
			$parent = (int) get_post_meta( $lesson_id, 'lesson_id', true );
			if ( isset( $h['sfwd-lessons'][ $parent ]['sfwd-topic'][ $lesson_id ] ) ) {
				$h['sfwd-lessons'][ $parent ]['sfwd-topic'][ $lesson_id ]['sfwd-quiz'][ $id ] = array();
			}
		}
		$this->set_steps( $course, $h );
		return $id;
	}
}
