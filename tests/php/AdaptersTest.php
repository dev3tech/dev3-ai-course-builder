<?php
/**
 * Builds a blueprint end to end with each adapter: WordPress pages, Tutor LMS (real plugin in wp-env)
 * and LearnDash (documented API, stubs), plus undo build and attaching quizzes to existing lessons.
 *
 * @package Dev3\AiCourseBuilder
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiCourseBuilder\Data\Blueprints;
use Dev3\AiCourseBuilder\Front\Certificate;
use Dev3\AiCourseBuilder\Lms\Adapter;
use Dev3\AiCourseBuilder\Lms\Adapters;
use Dev3\AiCourseBuilder\Lms\LearnDash;
use Dev3\AiCourseBuilder\Lms\Pages;
use Dev3\AiCourseBuilder\Service\Builder;

class AdaptersTest extends Dev3lm_Base_Case {

	/**
	 * Builds the sample tree with an adapter and returns the blueprint.
	 *
	 * @param string $lms Adapter id.
	 * @return array
	 */
	private function build( $lms ) {
		$id  = Blueprints::create(
			array(
				'tree' => $this->sample_tree(),
				'lms'  => $lms,
			)
		);
		$job = Builder::start( $id, $lms, 'publish' );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		$job = $this->finish_job( $job['id'] );
		$this->assertSame( 'done', $job['status'], wp_json_encode( $job['errors'] ) );
		$this->assertSame( 0, $job['failed'], wp_json_encode( $job['errors'] ) );
		$bp = Blueprints::get( $id );
		$this->assertSame( 'built', $bp['status'] );
		return $bp;
	}

	public function test_steps_follow_creation_order() {
		$tree  = \Dev3\AiCourseBuilder\Course\Tree::normalize( $this->sample_tree() );
		$steps = Builder::steps( $tree, Adapters::get( 'pages' ), true );
		$this->assertSame( array( 'course', 's:sbasics', 'l:lbeans', 't:tlabel', 'l:lgrind', 'q:qgrind', 'q:qbasics', 's:sbrew', 'l:lpour', 'q:qfinal', 'cert', 'final' ), $steps );
		$tree['sections'][0]['quiz']['questions'] = array();
		$this->assertNotContains( 'q:qbasics', Builder::steps( $tree, Adapters::get( 'pages' ), false ) );
	}

	public function test_pages_build_creates_linked_pages_quizzes_and_certificate() {
		$bp     = $this->build( 'pages' );
		$map    = $bp['result']['map'];
		$course = get_post( $map['__course'] );
		$this->assertSame( 'page', $course->post_type );
		$this->assertSame( 'publish', $course->post_status );
		$this->assertStringContainsString( Pages::TOC_MARK, $course->post_content );
		$this->assertStringContainsString( get_permalink( $map['lbeans'] ), $course->post_content );
		$this->assertSame( 0, $map['sbasics'], 'Sections are headings, not pages' );
		$this->assertSame( $course->ID, wp_get_post_parent_id( $map['lbeans'] ) );
		$this->assertSame( $map['lbeans'], wp_get_post_parent_id( $map['tlabel'] ) );
		$quiz = get_post( $map['qbasics'] );
		$this->assertStringContainsString( 'Show answer', $quiz->post_content );
		$this->assertStringContainsString( 'Fresh beans', $quiz->post_content );
		$this->assertStringContainsString( '_____', $quiz->post_content );
		$lesson = get_post( $map['lbeans'] );
		$this->assertStringContainsString( '<!-- wp:heading -->', $lesson->post_content );
		$this->assertStringContainsString( 'dev3lm-pager', $lesson->post_content );
		$this->assertSame( (string) $bp['id'], get_post_meta( $lesson->ID, Adapter::META_BLUEPRINT, true ) );
		$cert = get_post( $map['__certificate'] );
		$this->assertStringContainsString( 'wp:dev3lm/certificate', $cert->post_content );
		$this->assertNotEmpty( $bp['result']['links']['view'] );
		// Retrying a finished build is refused; nothing duplicates.
		$this->assertSame( 'dev3lm_built', Builder::start( $bp['id'], 'pages' )->get_error_code() );
	}

	public function test_certificate_block_renders_for_learners() {
		$bp     = $this->build( 'pages' );
		$course = (int) $bp['result']['course_id'];
		$cert   = new Certificate();
		wp_set_current_user( 0 );
		$this->assertStringContainsString( 'Log in', $cert->render( $course ) );
		$learner = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Ana Learner',
			)
		);
		wp_set_current_user( $learner );
		$html = $cert->render( $course );
		$this->assertStringContainsString( 'Ana Learner', $html );
		$this->assertStringContainsString( 'Ana Learner completed Coffee 101.', $html );
		$this->assertStringNotContainsString( 'DEV3', $html, 'Learner-facing output is unbranded' );
		add_filter( 'dev3lm_certificate_completed', '__return_false' );
		$this->assertStringContainsString( 'Complete the course', $cert->render( $course ) );
		remove_filter( 'dev3lm_certificate_completed', '__return_false' );
		$this->assertSame( 'X completed Y on Z.', Certificate::fill( '{learner} completed {course} on {date}.', 'X', 'Y', 'Z' ) );
	}

	public function test_unbuild_trashes_and_force_deletes() {
		$bp      = $this->build( 'pages' );
		$created = Builder::created_posts( $bp['id'] );
		$this->assertGreaterThanOrEqual( 8, count( $created ) );
		$result = Builder::unbuild( $bp['id'] );
		$this->assertSame( count( $created ), $result['removed'] );
		$this->assertSame( 'trash', get_post_status( $created[0] ) );
		$this->assertSame( 'draft', Blueprints::get( $bp['id'] )['status'] );
		// Rebuild works after an undo, then a permanent delete removes everything.
		$job = Builder::start( $bp['id'], 'pages' );
		$this->finish_job( $job['id'] );
		$this->assertSame( 'built', Blueprints::get( $bp['id'] )['status'] );
		Builder::unbuild( $bp['id'], true );
		$this->assertNull( get_post( Blueprints::get( $bp['id'] )['result']['map']['__course'] ?? $created[0] ) );
	}

	public function test_build_validates_and_rejects_inactive_lms() {
		$tree                    = $this->sample_tree();
		$tree['course']['title'] = '';
		$id                      = Blueprints::create( array( 'tree' => $tree ) );
		$this->assertSame( 'dev3lm_invalid', Builder::start( $id, 'pages' )->get_error_code() );
		$this->assertSame( 'dev3lm_lms', Adapters::resolve( 'moodle' )->get_error_code() );
		if ( ! Adapters::get( 'learndash' )->available() ) {
			$this->assertSame( 'dev3lm_lms_inactive', Adapters::resolve( 'learndash' )->get_error_code() );
		}
	}

	public function test_tutor_build_creates_native_curriculum_and_questions() {
		$tutor = Adapters::get( 'tutor' );
		if ( ! $tutor->available() ) {
			$this->markTestSkipped( 'Tutor LMS is not installed (run inside wp-env).' );
		}
		$this->assertSame( 'tutor', Adapters::resolve( 'auto' )->id() );
		$bp     = $this->build( 'tutor' );
		$map    = $bp['result']['map'];
		$course = get_post( $map['__course'] );
		$this->assertSame( 'courses', $course->post_type );
		$this->assertSame( 'beginner', get_post_meta( $course->ID, '_tutor_course_level', true ) );
		$this->assertSame( "Pick beans\nGrind well", get_post_meta( $course->ID, '_tutor_course_benefits', true ) );
		$this->assertSame( array( 'hours' => '1', 'minutes' => '15', 'seconds' => '0' ), get_post_meta( $course->ID, '_course_duration', true ) );
		$topic = get_post( $map['sbasics'] );
		$this->assertSame( 'topics', $topic->post_type );
		$this->assertSame( $course->ID, $topic->post_parent );
		$lesson = get_post( $map['lbeans'] );
		$this->assertSame( 'lesson', $lesson->post_type );
		$this->assertSame( $topic->ID, $lesson->post_parent );
		// Tutor has no sub-lessons: the topic becomes the next lesson in the same Tutor topic.
		$this->assertSame( $topic->ID, wp_get_post_parent_id( $map['tlabel'] ) );
		$this->assertGreaterThan( $lesson->menu_order, (int) get_post_field( 'menu_order', $map['tlabel'] ) );
		// Course quiz gets its own topic.
		$final = get_post( $map['qfinal'] );
		$this->assertSame( 'tutor_quiz', $final->post_type );
		$this->assertSame( 'topics', get_post_type( $final->post_parent ) );
		// Questions + answers live in Tutor's tables.
		global $wpdb;
		$quiz_id   = (int) $map['qbasics'];
		$questions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tutor_quiz_questions WHERE quiz_id = %d ORDER BY question_order", $quiz_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( 'single_choice', 'fill_in_the_blank', 'matching', 'open_ended' ), wp_list_pluck( $questions, 'question_type' ) );
		$this->assertSame( 'Fresh beans.', $questions[0]->answer_explanation );
		$answers = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tutor_quiz_question_answers WHERE belongs_question_id = %d ORDER BY answer_order", $questions[0]->question_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( array( '1', '0' ), wp_list_pluck( $answers, 'is_correct' ) );
		$blank = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tutor_quiz_question_answers WHERE belongs_question_id = %d", $questions[1]->question_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 'Use beans within a {dash} and grind {dash}.', $blank->answer_title );
		$this->assertSame( 'month|fresh', $blank->answer_two_gap_match );
		$option = get_post_meta( $quiz_id, 'tutor_quiz_option', true );
		$this->assertSame( 60, (int) $option['passing_grade'] );
		// Tutor's own model sees the curriculum.
		if ( function_exists( 'tutor_utils' ) && method_exists( tutor_utils(), 'get_topics' ) ) {
			$topics = tutor_utils()->get_topics( $course->ID );
			$this->assertGreaterThanOrEqual( 3, (int) $topics->found_posts );
		}
		// Certificate text kept on the course for the certificate block.
		$cert = json_decode( get_post_meta( $course->ID, Adapter::META_CERT, true ), true );
		$this->assertSame( 'Certificate of Completion', $cert['title'] );
		// Permanent undo removes question rows too (Tutor's own delete hooks touch Pro-only tables).
		$this->setExpectedIncorrectUsage( 'wpdb::prepare' );
		$wpdb->suppress_errors( true );
		Builder::unbuild( $bp['id'], true );
		$wpdb->suppress_errors( false );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_questions WHERE quiz_id = %d", $quiz_id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	public function test_tutor_attach_quiz_to_existing_lesson() {
		$tutor = Adapters::get( 'tutor' );
		if ( ! $tutor->available() ) {
			$this->markTestSkipped( 'Tutor LMS is not installed (run inside wp-env).' );
		}
		$course = self::factory()->post->create( array( 'post_type' => 'courses' ) );
		$topic  = self::factory()->post->create(
			array(
				'post_type'   => 'topics',
				'post_parent' => $course,
			)
		);
		$lesson = self::factory()->post->create(
			array(
				'post_type'   => 'lesson',
				'post_parent' => $topic,
				'menu_order'  => 3,
			)
		);
		$this->assertSame( $course, $tutor->course_of( $lesson ) );
		$quiz = \Dev3\AiCourseBuilder\Course\Tree::quiz(
			array(
				'title'     => 'Lesson quiz',
				'questions' => array(
					array(
						'type'    => 'ordering',
						'text'    => 'Order the steps',
						'answers' => array( 'Grind', 'Bloom', 'Pour' ),
					),
				),
			)
		);
		$id   = $tutor->attach_quiz( $lesson, $quiz, 'draft' );
		$this->assertSame( $topic, wp_get_post_parent_id( $id ) );
		$this->assertSame( 4, (int) get_post_field( 'menu_order', $id ) );
		$orphan = self::factory()->post->create( array( 'post_type' => 'lesson' ) );
		$this->assertSame( 'dev3lm_no_topic', $tutor->attach_quiz( $orphan, $quiz, 'draft' )->get_error_code() );
	}

	public function test_learndash_build_with_stubs() {
		require_once __DIR__ . '/stubs/learndash.php';
		dev3lm_test_register_learndash();
		$GLOBALS['dev3lm_ld_settings'] = array();
		$ld                            = Adapters::get( 'learndash' );
		$this->assertTrue( $ld->available() );
		$bp     = $this->build( 'learndash' );
		$map    = $bp['result']['map'];
		$course = (int) $map['__course'];
		$this->assertSame( 'sfwd-courses', get_post_type( $course ) );
		$this->assertSame( 0, $map['sbasics'] );
		$this->assertSame( 'sfwd-lessons', get_post_type( $map['lbeans'] ) );
		$this->assertSame( (string) $course, get_post_meta( $map['lbeans'], 'course_id', true ) );
		$this->assertSame( 'sfwd-topic', get_post_type( $map['tlabel'] ) );
		$this->assertSame( (string) $map['lbeans'], get_post_meta( $map['tlabel'], 'lesson_id', true ) );
		// Section quiz → lesson quiz of the section's last lesson.
		$this->assertSame( (string) $map['lgrind'], get_post_meta( $map['qbasics'], 'lesson_id', true ) );
		$this->assertSame( '0', get_post_meta( $map['qfinal'], 'lesson_id', true ) );
		// Steps and sections.
		$steps = get_post_meta( $course, 'ld_course_steps', true )['steps']['h'];
		$this->assertSame( array( $map['lbeans'], $map['lgrind'], $map['lpour'] ), array_keys( $steps['sfwd-lessons'] ) );
		$this->assertArrayHasKey( $map['tlabel'], $steps['sfwd-lessons'][ $map['lbeans'] ]['sfwd-topic'] );
		$this->assertArrayHasKey( $map['qbasics'], $steps['sfwd-lessons'][ $map['lgrind'] ]['sfwd-quiz'] );
		$this->assertArrayHasKey( $map['qgrind'], $steps['sfwd-lessons'][ $map['lgrind'] ]['sfwd-quiz'] );
		$this->assertArrayHasKey( $map['qfinal'], $steps['sfwd-quiz'] );
		$sections = json_decode( get_post_meta( $course, 'course_sections', true ), true );
		$this->assertSame( array( 'Basics', 'Brewing' ), wp_list_pluck( $sections, 'post_title' ) );
		$this->assertSame( array( 0, 2 ), wp_list_pluck( $sections, 'order' ) );
		// ProQuiz questions.
		$links = get_post_meta( $map['qbasics'], 'ld_quiz_questions', true );
		$this->assertCount( 4, $links );
		$pro = array_map( static fn( $id ) => WpProQuiz_Model_QuestionMapper::$saved[ $id ], array_values( $links ) );
		$this->assertSame( array( 'single', 'cloze_answer', 'matrix_sort_answer', 'essay' ), array_map( static fn( $m ) => $m->getAnswerType(), $pro ) );
		$this->assertSame( 'Use beans within a {month} and grind {fresh}.', $pro[1]->getQuestion() );
		$this->assertSame( 'French press', $pro[2]->getAnswerData()[0]->getSortString() );
		$this->assertTrue( $pro[0]->getAnswerData()[0]->getCorrect() );
		$this->assertNotEmpty( get_post_meta( $map['qbasics'], 'quiz_pro_id', true ) );
		// Certificate post linked as the course certificate.
		$cert = get_post( $map['__certificate'] );
		$this->assertSame( 'sfwd-certificates', $cert->post_type );
		$this->assertStringContainsString( '[usermeta field="display_name"]', $cert->post_content );
		$this->assertSame( $cert->ID, (int) learndash_get_setting( $course, 'certificate' ) );
		// Attach a quiz to an existing topic: lesson + steps updated.
		$quiz = \Dev3\AiCourseBuilder\Course\Tree::quiz(
			array(
				'title'     => 'Topic check',
				'questions' => array(
					array(
						'type'    => 'short_answer',
						'text'    => 'Where is the roast date?',
						'answers' => array( 'On the bag' ),
					),
				),
			)
		);
		$qid  = $ld->attach_quiz( (int) $map['tlabel'], $quiz, 'draft' );
		$this->assertSame( (string) $course, get_post_meta( $qid, 'course_id', true ) );
		$steps = get_post_meta( $course, 'ld_course_steps', true )['steps']['h'];
		$this->assertArrayHasKey( $qid, $steps['sfwd-lessons'][ $map['lbeans'] ]['sfwd-topic'][ $map['tlabel'] ]['sfwd-quiz'] );
		$this->assertInstanceOf( LearnDash::class, Adapters::for_post_type( 'sfwd-topic' ) );
	}

	public function test_pages_attach_quiz_to_page_and_post() {
		$pages = Adapters::get( 'pages' );
		$page  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Lesson page',
			)
		);
		$post  = self::factory()->post->create();
		$quiz  = \Dev3\AiCourseBuilder\Course\Tree::quiz(
			array(
				'title'     => 'Check',
				'questions' => array(
					array(
						'type'    => 'true_false',
						'text'    => 'Water is wet.',
						'answers' => array(
							array(
								'text'    => 'True',
								'correct' => true,
							),
						),
					),
				),
			)
		);
		$a     = $pages->attach_quiz( $page, $quiz, 'publish' );
		$this->assertSame( $page, wp_get_post_parent_id( $a ) );
		$b = $pages->attach_quiz( $post, $quiz, 'draft' );
		$this->assertSame( 0, wp_get_post_parent_id( $b ) );
		$this->assertStringContainsString( get_permalink( $post ), get_post_field( 'post_content', $b ) );
	}
}
