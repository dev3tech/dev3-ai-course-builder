<?php
/**
 * REST: permissions, blueprint lifecycle, DEV3 AI drafts (mocked Gemini), jobs, tools, rewrite undo.
 *
 * @package Dev3\AiCourseBuilder
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiCourseBuilder\Core3\Core\Jobs;
use Dev3\AiCourseBuilder\Core3\Core\Settings_Store;
use Dev3\AiCourseBuilder\Data\Blueprints;
use Dev3\AiCourseBuilder\Data\Rewrites;
use Dev3\AiCourseBuilder\Lms\Adapter;

class RestTest extends Dev3lm_Base_Case {

	public function test_routes_require_manager_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 403, $this->rest( 'GET', 'blueprints' )->get_status() );
		$this->assertSame( 403, $this->rest( 'POST', 'tools/rewrite', array( 'lesson_id' => 1 ) )->get_status() );
		wp_set_current_user( $this->admin );
		$req = new WP_REST_Request( 'GET', '/dev3lm/v1/blueprints' );
		$this->assertSame( 403, rest_do_request( $req )->get_status(), 'Nonce is required' );
		$this->assertSame( 200, $this->rest( 'GET', 'blueprints' )->get_status() );
		$status = $this->rest( 'GET', 'status' )->get_data();
		$this->assertSame( array( 'tutor', 'learndash', 'pages' ), wp_list_pluck( $status['adapters'], 'id' ) );
		$this->assertTrue( $status['ai']['key_set'] );
	}

	public function test_create_from_outline_without_ai_and_save_tree() {
		$res = $this->rest(
			'POST',
			'blueprints',
			array(
				'mode'    => 'outline',
				'outline' => "# Knife Skills\n## Basics\n### Holding the knife\nPinch grip.\n### Board setup",
				'options' => array( 'quiz_placement' => 'section' ),
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$bp = $res->get_data();
		$this->assertSame( 'Knife Skills', $bp['title'] );
		$this->assertSame( 'outline', $bp['source_type'] );
		$this->assertSame( 'Pinch grip.', $bp['tree']['sections'][0]['lessons'][0]['content'] );
		$this->assertNotNull( $bp['tree']['sections'][0]['quiz'], 'Quiz placement applied' );
		$this->assertCount( 0, $this->requests, 'No AI call' );
		$tree                                   = $bp['tree'];
		$tree['sections'][0]['lessons'][1]['title'] = 'Cutting board setup';
		$tree['course']['title']                    = 'Knife Skills 101';
		$saved                                      = $this->rest(
			'PUT',
			'blueprints/' . $bp['id'],
			array(
				'tree' => $tree,
				'lms'  => 'pages',
			)
		)->get_data();
		$this->assertSame( 'Knife Skills 101', $saved['title'] );
		$this->assertSame( 'pages', $saved['lms'] );
		$this->assertSame( 'Cutting board setup', $saved['tree']['sections'][0]['lessons'][1]['title'] );
		$this->assertArrayHasKey( 'issues', $saved );
		$dup = $this->rest( 'POST', 'blueprints/' . $bp['id'] . '/duplicate' )->get_data();
		$this->assertSame( 'Knife Skills 101 (copy)', $dup['title'] );
		$this->assertSame( 2, $this->rest( 'GET', 'blueprints' )->get_data()['total'] );
		$this->assertSame( 200, $this->rest( 'DELETE', 'blueprints/' . $dup['id'] )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', 'blueprints/' . $dup['id'] )->get_status() );
	}

	public function test_topic_template_blank_and_import() {
		$tpl = $this->rest(
			'POST',
			'blueprints',
			array(
				'mode'     => 'topic',
				'topic'    => 'Sourdough baking',
				'template' => true,
				'options'  => array(
					'sections'            => 2,
					'lessons_per_section' => 2,
					'quiz_placement'      => 'course',
				),
			)
		)->get_data();
		$this->assertSame( 4, $tpl['counts']['lessons'] );
		$this->assertNotNull( $tpl['tree']['quiz'] );
		$this->assertSame( 400, $this->rest( 'POST', 'blueprints', array( 'mode' => 'topic' ) )->get_status() );
		$blank = $this->rest(
			'POST',
			'blueprints',
			array(
				'mode'  => 'blank',
				'title' => 'Empty',
			)
		)->get_data();
		$this->assertSame( 0, $blank['counts']['sections'] );
		$import = $this->rest(
			'POST',
			'blueprints',
			array(
				'mode' => 'import',
				'tree' => $this->sample_tree(),
			)
		)->get_data();
		$this->assertSame( 3, $import['counts']['lessons'] );
		$this->assertSame( 400, $this->rest( 'POST', 'blueprints', array( 'mode' => 'import', 'tree' => array() ) )->get_status() );
	}

	public function test_create_from_uploaded_text_file_and_parse_preview() {
		$upload = wp_upload_bits( 'notes.md', null, "# Python\n## Basics\n### Variables\nA variable stores a value.\n### Loops" );
		$id     = self::factory()->attachment->create_object( $upload['file'], 0, array( 'post_mime_type' => 'text/plain' ) );
		$prev   = $this->rest( 'POST', 'source/parse', array( 'attachment_id' => $id ) )->get_data();
		$this->assertSame( 2, $prev['counts']['lessons'] );
		$this->assertFalse( $prev['scanned'] );
		$bp = $this->rest(
			'POST',
			'blueprints',
			array(
				'mode'          => 'file',
				'attachment_id' => $id,
			)
		)->get_data();
		$this->assertSame( 'Python', $bp['title'] );
		$this->assertStringStartsWith( 'notes', $bp['source']['file_name'] );
		$stored = Blueprints::get( $bp['id'] );
		$this->assertStringContainsString( 'A variable stores a value.', $stored['source']['text'] );
	}

	public function test_ai_outline_is_a_draft_until_saved() {
		$bp  = $this->rest(
			'POST',
			'blueprints',
			array(
				'mode'    => 'topic',
				'topic'   => 'Coffee at home',
				'options' => array(
					'quiz_placement'    => 'section',
					'topics_per_lesson' => 1,
				),
			)
		)->get_data();
		$res = $this->rest( 'POST', 'blueprints/' . $bp['id'] . '/outline' );
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $res->get_data() ) );
		$draft = $res->get_data();
		$this->assertSame( 'Home Coffee Brewing', $draft['tree']['course']['title'] );
		$this->assertSame( 95, $draft['tree']['course']['duration'] );
		$this->assertSame( 3, $draft['counts']['lessons'] );
		$this->assertSame( 'Reading a bag label', $draft['tree']['sections'][0]['lessons'][0]['topics'][0]['title'] );
		$this->assertStringContainsString( '- Roast date', $draft['tree']['sections'][0]['lessons'][0]['summary'] );
		$this->assertNotNull( $draft['tree']['sections'][1]['quiz'] );
		$this->assertSame( 'gemini-2.5-pro', $draft['model'] );
		$body = end( $this->requests )['body'];
		$this->assertSame( 2048, $body['generationConfig']['thinkingConfig']['thinkingBudget'] );
		$this->assertStringContainsString( 'Coffee at home', $body['contents'][0]['parts'][0]['text'] );
		// Not saved yet.
		$this->assertSame( 0, Blueprints::get( $bp['id'] )['counts']['lessons'] );
	}

	public function test_lesson_quiz_and_details_drafts() {
		$id   = Blueprints::create( array( 'tree' => $this->sample_tree() ) );
		$res  = $this->rest( 'POST', 'blueprints/' . $id . '/lesson', array( 'node' => 'lpour' ) );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status(), wp_json_encode( $data ) );
		$this->assertStringStartsWith( 'Fresh beans are the single biggest upgrade.', $data['content'] );
		$this->assertStringContainsString( "## Why freshness matters\n\n- Look for a roast date", $data['content'] );
		$this->assertStringContainsString( '> Tip: Store beans', $data['content'] );
		$prompt = end( $this->requests )['body']['contents'][0]['parts'][0]['text'];
		$this->assertStringContainsString( 'Lesson: Pour-over', $prompt );
		$this->assertStringContainsString( 'Section: Brewing', $prompt );
		$this->assertSame( 404, $this->rest( 'POST', 'blueprints/' . $id . '/lesson', array( 'node' => 'nope' ) )->get_status() );
		// Quiz: broken AI questions are dropped, only allowed types kept.
		$quiz = $this->rest(
			'POST',
			'blueprints/' . $id . '/quiz',
			array(
				'node'  => 'qgrind',
				'count' => 10,
				'types' => array( 'single', 'fill_blank', 'true_false' ),
			)
		)->get_data();
		$this->assertSame( array( 'single', 'fill_blank', 'true_false' ), wp_list_pluck( $quiz['questions'], 'type' ) );
		$this->assertSame( array( false, true ), wp_list_pluck( $quiz['questions'][2]['answers'], 'correct' ) );
		$basic = $this->rest(
			'POST',
			'blueprints/' . $id . '/quiz',
			array(
				'node' => 'qbasics',
				'mode' => 'basic',
			)
		)->get_data();
		$this->assertTrue( $basic['basic'] );
		$this->assertNotEmpty( $basic['questions'] );
		$details = $this->rest( 'POST', 'blueprints/' . $id . '/details' )->get_data();
		$this->assertSame( 'Certified Home Barista', $details['draft']['certificate_title'] );
		$this->assertSame( "One\nTwo", $details['draft']['outcomes'] );
		$basic = $this->rest( 'POST', 'blueprints/' . $id . '/details', array( 'mode' => 'basic' ) )->get_data();
		$this->assertStringContainsString( 'Coffee 101', $basic['draft']['description'] );
		$this->assertStringContainsString( '{learner}', $basic['draft']['certificate_body'] );
		// Drafts never change the stored tree.
		$this->assertSame( 'Bloom for 30 seconds.', Blueprints::get( $id )['tree']['sections'][1]['lessons'][0]['content'] );
	}

	public function test_ai_off_returns_errors_but_basic_paths_work() {
		Settings_Store::update( array( 'ai_enabled' => false ) );
		$id = Blueprints::create( array( 'tree' => $this->sample_tree() ) );
		$this->assertSame( 'dev3lm_ai_disabled', $this->rest( 'POST', 'blueprints/' . $id . '/lesson', array( 'node' => 'lpour' ) )->get_data()['code'] );
		$this->assertSame( 400, $this->rest( 'POST', 'blueprints/' . $id . '/draft-all' )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', 'blueprints/' . $id . '/details', array( 'mode' => 'basic' ) )->get_status() );
		$this->assertSame( 201, $this->rest( 'POST', 'blueprints/' . $id . '/build', array( 'lms' => 'pages' ) )->get_status() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_draft_all_job_writes_empty_lessons_as_ai_drafts() {
		$tree                                      = $this->sample_tree();
		$tree['sections'][1]['lessons'][0]['content'] = '';
		$tree['sections'][0]['lessons'][0]['topics'][0]['content'] = '';
		$id  = Blueprints::create( array( 'tree' => $tree ) );
		$res = $this->rest( 'POST', 'blueprints/' . $id . '/draft-all' );
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 2, $res->get_data()['total'] );
		$job = $this->finish_job( $res->get_data()['id'] );
		$this->assertSame( 'done', $job['status'] );
		$bp     = Blueprints::get( $id );
		$lesson = $bp['tree']['sections'][1]['lessons'][0];
		$this->assertStringStartsWith( 'Fresh beans', $lesson['content'] );
		$this->assertTrue( $lesson['ai_draft'] );
		$this->assertStringStartsWith( 'A burr grinder is consistent.', $bp['tree']['sections'][0]['lessons'][1]['content'], 'Written lessons untouched' );
		$this->assertSame( 'dev3lm_nothing', $this->rest( 'POST', 'blueprints/' . $id . '/draft-all' )->get_data()['code'] );
	}

	public function test_build_and_unbuild_over_rest() {
		$id  = Blueprints::create( array( 'tree' => $this->sample_tree() ) );
		$res = $this->rest(
			'POST',
			'blueprints/' . $id . '/build',
			array(
				'lms'    => 'pages',
				'status' => 'draft',
			)
		);
		$this->assertSame( 201, $res->get_status() );
		$this->assertSame( 409, $this->rest( 'PUT', 'blueprints/' . $id, array( 'tree' => $this->sample_tree() ) )->get_status(), 'No edits while building' );
		$this->finish_job( $res->get_data()['id'] );
		$bp = $this->rest( 'GET', 'blueprints/' . $id )->get_data();
		$this->assertSame( 'built', $bp['status'] );
		$this->assertSame( 'draft', get_post_status( $bp['result']['course_id'] ) );
		$this->assertTrue( $bp['result']['exists'] );
		$undo = $this->rest( 'POST', 'blueprints/' . $id . '/unbuild' )->get_data();
		$this->assertGreaterThan( 5, $undo['removed'] );
		$this->assertSame( 'draft', $undo['blueprint']['status'] );
	}

	public function test_quiz_tool_creates_quiz_for_existing_page() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Espresso basics',
				'post_content' => '<!-- wp:paragraph --><p>Espresso is a concentrated coffee brewed under pressure. A tamper compresses the coffee grounds evenly.</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $page, Adapter::META_BLUEPRINT, 1 );
		$list = $this->rest( 'GET', 'lessons', array( 'search' => 'Espresso' ) )->get_data();
		$this->assertSame( array( $page ), wp_list_pluck( $list['items'], 'id' ) );
		$one = $this->rest( 'GET', 'lessons/' . $page )->get_data();
		$this->assertStringContainsString( 'Espresso is a concentrated coffee', $one['markdown'] );
		$draft = $this->rest(
			'POST',
			'tools/quiz',
			array(
				'lesson_id' => $page,
				'count'     => 3,
				'types'     => array( 'single', 'matching' ),
			)
		)->get_data();
		$this->assertSame( array( 'single', 'matching' ), wp_list_pluck( $draft['questions'], 'type' ) );
		$this->assertStringContainsString( 'Espresso is a concentrated', end( $this->requests )['body']['contents'][0]['parts'][0]['text'] );
		$basic = $this->rest(
			'POST',
			'tools/quiz',
			array(
				'lesson_id' => $page,
				'mode'      => 'basic',
				'types'     => array( 'fill_blank' ),
			)
		)->get_data();
		$this->assertSame( 'fill_blank', $basic['questions'][0]['type'] );
		$this->assertSame( 400, $this->rest( 'POST', 'tools/quiz/create', array( 'lesson_id' => $page ) )->get_status() );
		$created = $this->rest(
			'POST',
			'tools/quiz/create',
			array(
				'lesson_id' => $page,
				'questions' => $draft['questions'],
				'status'    => 'publish',
			)
		);
		$this->assertSame( 201, $created->get_status() );
		$quiz = $created->get_data();
		$this->assertSame( 'Quiz: Espresso basics', $quiz['title'] );
		$this->assertSame( 2, $quiz['questions'] );
		$this->assertSame( $page, wp_get_post_parent_id( $quiz['quiz_id'] ) );
		$this->assertSame( (string) $page, get_post_meta( $quiz['quiz_id'], '_dev3lm_source_lesson', true ) );
		$this->assertSame( 404, $this->rest( 'POST', 'tools/quiz', array( 'lesson_id' => self::factory()->attachment->create() ) )->get_status() );
	}

	public function test_rewrite_apply_and_undo() {
		$original = '<!-- wp:paragraph --><p>Long complicated text about extraction kinetics and particle distribution.</p><!-- /wp:paragraph -->';
		$page     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Extraction',
				'post_content' => $original,
			)
		);
		$draft    = $this->rest(
			'POST',
			'tools/rewrite',
			array(
				'lesson_id' => $page,
				'mode'      => 'simplify',
				'extra'     => 'Use coffee examples',
			)
		)->get_data();
		$this->assertSame( "## Simple version\n\nBuy fresh beans. Grind them just before brewing.", $draft['content'] );
		$this->assertStringContainsString( 'extraction kinetics', $draft['original'] );
		$prompt = end( $this->requests )['body']['contents'][0]['parts'][0]['text'];
		$this->assertStringContainsString( 'Simplify the language', $prompt );
		$this->assertStringContainsString( 'Use coffee examples', $prompt );
		$this->assertSame( 400, $this->rest( 'POST', 'tools/rewrite', array( 'lesson_id' => $page, 'mode' => 'shout' ) )->get_status() );
		$this->assertSame( $original, get_post_field( 'post_content', $page ), 'Draft does not save' );
		$applied = $this->rest(
			'POST',
			'tools/rewrite/apply',
			array(
				'lesson_id' => $page,
				'mode'      => 'simplify',
				'content'   => $draft['content'] . "\n\n- edited by admin",
			)
		)->get_data();
		$content = get_post_field( 'post_content', $page );
		$this->assertStringContainsString( '<!-- wp:heading -->', $content );
		$this->assertStringContainsString( '<li>edited by admin</li>', $content );
		$this->assertTrue( $applied['history'][0]['can_undo'] );
		$this->assertSame( 1, Rewrites::count() );
		// Changed after the rewrite: undo asks for force.
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => $content . '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);
		$this->assertSame( 409, $this->rest( 'POST', 'rewrites/' . $applied['history_id'] . '/undo' )->get_status() );
		$this->assertSame( 200, $this->rest( 'POST', 'rewrites/' . $applied['history_id'] . '/undo', array( 'force' => true ) )->get_status() );
		$this->assertSame( $original, get_post_field( 'post_content', $page ) );
		$this->assertTrue( $this->rest( 'GET', 'rewrites' )->get_data()['items'][0]['undone'] );
		$this->assertSame( 404, $this->rest( 'POST', 'rewrites/' . $applied['history_id'] . '/undo' )->get_status() );
	}

	public function test_jobs_have_handlers_and_privacy_policy() {
		$this->assertTrue( Jobs::has( 'dev3lm_build' ) );
		$this->assertTrue( Jobs::has( 'dev3lm_content' ) );
		$this->assertTrue( shortcode_exists( 'dev3lm_certificate' ) );
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'dev3lm/certificate' ) );
	}
}
