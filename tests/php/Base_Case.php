<?php
/**
 * Shared fixtures: a fake Gemini (pre_http_request) that answers by prompt, REST helpers, sample trees.
 *
 * @package Dev3\AiCourseBuilder
 */

use Dev3\AiCourseBuilder\Ai\Prompt_Library;
use Dev3\AiCourseBuilder\Core3\Api\Ai;
use Dev3\AiCourseBuilder\Core3\Api\Prompts;
use Dev3\AiCourseBuilder\Core3\Core\Cache;
use Dev3\AiCourseBuilder\Core3\Core\Schema;
use Dev3\AiCourseBuilder\Core3\Core\Settings_Store;
use Dev3\AiCourseBuilder\Core3\Data\Usage_Repository;
use Dev3\AiCourseBuilder\Lms\Adapters;

abstract class Dev3lm_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured Gemini requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Admin user.
	 *
	 * @var int
	 */
	protected $admin = 0;

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3LM_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client = null;
		Prompts::reset();
		Prompt_Library::register();
		Adapters::reset();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3lm_blueprints" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3lm_rewrites" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3lm_core_jobs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}dev3lm_core_cache" );
		// phpcs:enable
		$this->requests = array();
		add_filter( 'dev3lm_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3lm_jobs_use_action_scheduler', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3lm_retry_delay_ms', '__return_zero' );
		remove_filter( 'dev3lm_jobs_use_action_scheduler', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Fake Gemini answering by prompt keywords.
	 *
	 * @param mixed  $pre  Short-circuit.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return array|mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'generativelanguage.googleapis.com' ) ) {
			return $pre;
		}
		$body             = json_decode( (string) $args['body'], true );
		$this->requests[] = array(
			'url'  => $url,
			'body' => $body,
		);
		$prompt = '';
		foreach ( (array) ( $body['contents'][0]['parts'] ?? array() ) as $part ) {
			$prompt .= (string) ( $part['text'] ?? '' );
		}
		$data = $this->answer( $prompt );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'candidates'    => array(
						array(
							'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
							'finishReason' => 'STOP',
						),
					),
					'usageMetadata' => array(
						'promptTokenCount'     => 120,
						'candidatesTokenCount' => 80,
						'totalTokenCount'      => 200,
					),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Canned structured answers.
	 *
	 * @param string $prompt Prompt.
	 * @return array
	 */
	protected function answer( $prompt ) {
		if ( false !== strpos( $prompt, 'Design a complete online course outline' ) ) {
			return array(
				'title'            => 'Home Coffee Brewing',
				'description'      => "Brew cafe-quality coffee at home.\n\nNo fancy gear needed.",
				'excerpt'          => 'Brew better coffee at home.',
				'audience'         => 'Curious beginners',
				'level'            => 'beginner',
				'duration_minutes' => 95,
				'outcomes'         => array( 'Pick fresh beans', 'Dial in a grind' ),
				'requirements'     => array( 'A kettle' ),
				'sections'         => array(
					array(
						'title'   => 'Beans and grind',
						'summary' => 'Start with the basics.',
						'lessons' => array(
							array(
								'title'      => 'Choosing fresh beans',
								'summary'    => 'What roast dates mean.',
								'key_points' => array( 'Roast date', 'Storage' ),
								'topics'     => array( array( 'title' => 'Reading a bag label' ) ),
							),
							array(
								'title'   => 'Grind size explained',
								'summary' => 'Coarse to fine.',
							),
						),
					),
					array(
						'title'   => 'Brewing methods',
						'lessons' => array(
							array(
								'title'   => 'Pour-over step by step',
								'summary' => 'Bloom, pour, wait.',
							),
						),
					),
				),
			);
		}
		if ( false !== strpos( $prompt, 'Write the full content of one lesson' ) ) {
			return array(
				'blocks' => array(
					array(
						'type' => 'paragraph',
						'text' => 'Fresh beans are the single biggest upgrade. A grinder is the second.',
					),
					array(
						'type' => 'heading',
						'text' => 'Why freshness matters',
					),
					array(
						'type'  => 'list',
						'items' => array( 'Look for a roast date', 'Use within a month' ),
					),
					array(
						'type' => 'callout',
						'text' => 'Store beans in an airtight jar <b>away</b> from light.',
					),
				),
			);
		}
		if ( false !== strpos( $prompt, 'quiz questions that check understanding' ) ) {
			return array(
				'questions' => array(
					array(
						'type'        => 'single',
						'text'        => 'What is the biggest upgrade?',
						'explanation' => 'Fresh beans matter most.',
						'answers'     => array(
							array(
								'text'    => 'Fresh beans',
								'correct' => true,
							),
							array(
								'text'    => 'A new mug',
								'correct' => false,
							),
						),
					),
					array(
						'type'    => 'single',
						'text'    => 'Broken question with no correct answer',
						'answers' => array(
							array( 'text' => 'A' ),
							array( 'text' => 'B' ),
						),
					),
					array(
						'type'    => 'fill_blank',
						'text'    => 'Use beans within a {blank}.',
						'answers' => array( array( 'text' => 'month' ) ),
					),
					array(
						'type'    => 'true_false',
						'text'    => 'Light is good for beans.',
						'answers' => array(
							array(
								'text'    => 'False',
								'correct' => true,
							),
						),
					),
					array(
						'type'    => 'matching',
						'text'    => 'Match grind to method.',
						'answers' => array(
							array(
								'text'  => 'Coarse',
								'match' => 'French press',
							),
							array(
								'text'  => 'Fine',
								'match' => 'Espresso',
							),
						),
					),
				),
			);
		}
		if ( false !== strpos( $prompt, 'sales description and certificate text' ) ) {
			return array(
				'description'       => 'A practical course.',
				'excerpt'           => 'Short pitch.',
				'outcomes'          => array( 'One', 'Two' ),
				'certificate_title' => 'Certified Home Barista',
				'certificate_body'  => '{learner} completed {course} on {date}.',
			);
		}
		if ( false !== strpos( $prompt, 'Rewrite the lesson' ) ) {
			return array(
				'blocks' => array(
					array(
						'type' => 'heading',
						'text' => 'Simple version',
					),
					array(
						'type' => 'paragraph',
						'text' => 'Buy fresh beans. Grind them just before brewing.',
					),
				),
			);
		}
		return array();
	}

	/**
	 * Runs a REST request with a valid nonce.
	 *
	 * @param string $method Method.
	 * @param string $path   Path under dev3lm/v1.
	 * @param array  $params Params.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $path, array $params = array() ) {
		$req = new WP_REST_Request( $method, '/dev3lm/v1/' . ltrim( $path, '/' ) );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		if ( in_array( $method, array( 'POST', 'PUT' ), true ) ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		} else {
			$req->set_query_params( $params );
		}
		return rest_do_request( $req );
	}

	/**
	 * A small complete tree.
	 *
	 * @return array
	 */
	protected function sample_tree() {
		return array(
			'course'   => array(
				'title'        => 'Coffee 101',
				'description'  => "Learn to brew.\n\nAt home.",
				'excerpt'      => 'Brew better.',
				'level'        => 'beginner',
				'outcomes'     => array( 'Pick beans', 'Grind well' ),
				'requirements' => array( 'A kettle' ),
				'duration'     => 75,
				'certificate'  => array(
					'title' => 'Certificate of Completion',
					'body'  => '{learner} completed {course}.',
				),
			),
			'sections' => array(
				array(
					'id'      => 'sbasics',
					'title'   => 'Basics',
					'lessons' => array(
						array(
							'id'      => 'lbeans',
							'title'   => 'Beans',
							'content' => "## Freshness\n\nFresh beans are the biggest upgrade.\n\n- Roast date\n- Storage",
							'topics'  => array(
								array(
									'id'      => 'tlabel',
									'title'   => 'Reading labels',
									'content' => 'Labels list the roast date.',
								),
							),
						),
						array(
							'id'      => 'lgrind',
							'title'   => 'Grind',
							'content' => 'A burr grinder is consistent. It crushes beans evenly so every particle extracts at the same speed.',
							'quiz'    => array(
								'id'        => 'qgrind',
								'title'     => 'Grind check',
								'questions' => array(
									array(
										'type'    => 'true_false',
										'text'    => 'Burr grinders are consistent.',
										'answers' => array(
											array(
												'text'    => 'True',
												'correct' => true,
											),
										),
									),
								),
							),
						),
					),
					'quiz'    => array(
						'id'        => 'qbasics',
						'title'     => 'Basics quiz',
						'passing'   => 60,
						'questions' => array(
							array(
								'type'        => 'single',
								'text'        => 'Biggest upgrade?',
								'explanation' => 'Fresh beans.',
								'answers'     => array(
									array(
										'text'    => 'Fresh beans',
										'correct' => true,
									),
									array( 'text' => 'Sugar' ),
								),
							),
							array(
								'type'    => 'fill_blank',
								'text'    => 'Use beans within a {blank} and grind {blank}.',
								'answers' => array( array( 'text' => 'month' ), array( 'text' => 'fresh' ) ),
							),
							array(
								'type'    => 'matching',
								'text'    => 'Match.',
								'answers' => array(
									array(
										'text'  => 'Coarse',
										'match' => 'French press',
									),
									array(
										'text'  => 'Fine',
										'match' => 'Espresso',
									),
								),
							),
							array(
								'type' => 'essay',
								'text' => 'Describe your morning brew.',
							),
						),
					),
				),
				array(
					'id'      => 'sbrew',
					'title'   => 'Brewing',
					'lessons' => array(
						array(
							'id'      => 'lpour',
							'title'   => 'Pour-over',
							'content' => 'Bloom for 30 seconds.',
						),
					),
				),
			),
			'quiz'     => array(
				'id'        => 'qfinal',
				'title'     => 'Final assessment',
				'questions' => array(
					array(
						'type'    => 'multiple',
						'text'    => 'Pick the brew methods.',
						'answers' => array(
							array(
								'text'    => 'Pour-over',
								'correct' => true,
							),
							array(
								'text'    => 'French press',
								'correct' => true,
							),
							array( 'text' => 'Toaster' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Runs a job to completion.
	 *
	 * @param int $id Job id.
	 * @return array Job.
	 */
	protected function finish_job( $id ) {
		$job = null;
		for ( $i = 0; $i < 20; $i++ ) {
			$job = \Dev3\AiCourseBuilder\Core3\Core\Jobs::run( $id, 30 );
			if ( in_array( $job['status'], array( 'done', 'failed', 'cancelled' ), true ) ) {
				break;
			}
		}
		return $job;
	}
}
