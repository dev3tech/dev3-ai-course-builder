<?php
/**
 * Every DEV3 AI prompt of the plugin in one place (versioned, filterable through the core prompt library).
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Ai;

use Dev3\AiCourseBuilder\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Prompt registrations + schemas.
 */
class Prompt_Library {

	const BLOCK_TYPES = array( 'heading', 'subheading', 'paragraph', 'list', 'ordered_list', 'quote', 'callout', 'code' );

	/**
	 * Registers prompts.
	 */
	public static function register() {
		$system = 'You are an expert instructional designer who writes clear, accurate, well-structured online courses. Write in {{language}}. Tone: {{tone}}. Never invent statistics, quotes, prices or URLs. Plain text inside JSON strings: no HTML, no Markdown headings.';

		Prompts::register(
			'outline',
			array(
				'version'         => '1',
				'task'            => 'course_outline',
				'system'          => $system,
				'template'        => "Design a complete online course outline.\n\nCourse topic / brief: {{topic}}\nTarget audience: {{audience}}\nLevel: {{level}}\nLearning goals from the author: {{goals}}\nWanted structure: about {{sections}} sections, about {{lessons}} lessons per section, {{topics}} topics (sub-lessons) per lesson.\n{{outline_note}}\n\nSource material (use it as the main basis when present, keep its order and terminology):\n{{source}}\n\nReturn the course title, a 2-4 paragraph description, a one-sentence excerpt, 4-6 learning outcomes, prerequisites, the audience, the level, an estimated duration in minutes and the sections with lessons. Each lesson has a one or two sentence summary and 3-5 key points. Lesson titles are specific and different from each other.",
				'schema'          => self::outline_schema(),
				'profile'         => 'heavy',
				'thinking_budget' => 2048,
				'timeout'         => 150,
				'max_tokens'      => 16384,
			)
		);

		Prompts::register(
			'lesson',
			array(
				'version'    => '1',
				'task'       => 'lesson_content',
				'system'     => $system,
				'template'   => "Write the full content of one lesson of the course \"{{course}}\" (audience: {{audience}}; level: {{level}}).\nSection: {{section}}\nLesson: {{lesson}}\nLesson summary: {{summary}}\nKey points to cover: {{points}}\nOther lessons in this section (do not repeat them): {{siblings}}\nLength: {{length}}.\n\nRelevant source material (stay faithful to it when present):\n{{source}}\n\nReturn the lesson as an ordered list of blocks. Start with a short introduction paragraph, use 'heading' blocks for the main parts, explain with paragraphs, lists and at least one practical example, add one 'callout' with a tip or common mistake, and end with a 'heading' \"Key takeaways\" followed by a 'list'. Use 'code' only for real code or commands.",
				'schema'     => self::blocks_schema( 'Lesson content blocks' ),
				'profile'    => 'heavy',
				'max_tokens' => 12288,
			)
		);

		Prompts::register(
			'quiz',
			array(
				'version'    => '1',
				'task'       => 'quiz_questions',
				'system'     => $system,
				'template'   => "Write {{count}} quiz questions that check understanding of the material below.\nQuiz: {{title}} (course \"{{course}}\", level {{level}}).\nAllowed question types (use a mix, only these): {{types}}.\nRules per type:\n- single: 4 answers, exactly one correct.\n- multiple: 4-5 answers, two or more correct.\n- true_false: answers \"True\" and \"False\", mark the correct one.\n- fill_blank: the question text contains {blank} where each missing word goes; answers list the missing words in order (all correct).\n- short_answer: a question answered in a few words; answers hold 1-3 accepted answers.\n- essay: an open question; no answers; put grading guidance in the explanation.\n- ordering: answers are the items in the correct order.\n- matching: each answer has text (the term) and match (its definition or pair).\nEvery question has a one-sentence explanation of the correct answer. Questions must be answerable from the material only.\n\nMaterial:\n{{material}}",
				'schema'     => self::quiz_schema(),
				'profile'    => 'heavy',
				'max_tokens' => 12288,
			)
		);

		Prompts::register(
			'details',
			array(
				'version'  => '1',
				'task'     => 'course_details',
				'system'   => $system,
				'template' => "Write the sales description and certificate text for the course \"{{course}}\" (audience: {{audience}}; level: {{level}}).\nCourse structure:\n{{structure}}\n\nReturn: a persuasive but honest 2-4 paragraph description (plain text, paragraphs separated by blank lines), a one-sentence excerpt, 4-6 learning outcomes, a short certificate title, and a certificate body of 1-3 sentences that may use the placeholders {learner}, {course} and {date}.",
				'schema'   => self::details_schema(),
				'profile'  => 'fast',
			)
		);

		Prompts::register(
			'rewrite',
			array(
				'version'    => '1',
				'task'       => 'lesson_rewrite',
				'system'     => $system,
				'template'   => "Rewrite the lesson \"{{title}}\".\nInstruction: {{instruction}}\nExtra instructions from the author: {{extra}}\nKeep the facts, the order of ideas and any code exactly correct. Do not add information you are not sure about.\n\nCurrent lesson:\n{{content}}",
				'schema'     => self::blocks_schema( 'Rewritten lesson blocks' ),
				'profile'    => 'heavy',
				'max_tokens' => 12288,
			)
		);
	}

	/**
	 * Rewrite instructions per mode.
	 *
	 * @return array mode => instruction.
	 */
	public static function rewrite_modes() {
		return array(
			'simplify'  => 'Simplify the language for beginners: short sentences, plain words, explain jargon.',
			'expand'    => 'Expand the lesson: deeper explanations, more detail on each point, a worked example.',
			'examples'  => 'Keep the text and add concrete, realistic examples and one short exercise.',
			'shorten'   => 'Make it about 40% shorter while keeping every key idea.',
			'clarity'   => 'Fix grammar, spelling and clarity; improve structure with headings and lists; keep the length.',
			'tone'      => 'Rewrite in the tone: {{tone}}.',
			'reading'   => 'Rewrite for a reading level of: {{reading_level}}.',
			'translate' => 'Translate the lesson into {{target_language}} (write the whole lesson in that language).',
		);
	}

	/**
	 * Outline schema.
	 *
	 * @return array
	 */
	public static function outline_schema() {
		$str = static function ( $max ) {
			return array(
				'type'      => 'STRING',
				'maxLength' => $max,
			);
		};
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'title'            => $str( 120 ),
				'description'      => $str( 2500 ),
				'excerpt'          => $str( 300 ),
				'audience'         => $str( 300 ),
				'level'            => array(
					'type' => 'STRING',
					'enum' => array( 'all_levels', 'beginner', 'intermediate', 'expert' ),
				),
				'duration_minutes' => array( 'type' => 'INTEGER' ),
				'outcomes'         => array(
					'type'     => 'ARRAY',
					'items'    => $str( 200 ),
					'maxItems' => 8,
				),
				'requirements'     => array(
					'type'     => 'ARRAY',
					'items'    => $str( 200 ),
					'maxItems' => 6,
				),
				'sections'         => array(
					'type'     => 'ARRAY',
					'maxItems' => 30,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'title'   => $str( 120 ),
							'summary' => $str( 400 ),
							'lessons' => array(
								'type'     => 'ARRAY',
								'maxItems' => 20,
								'items'    => array(
									'type'       => 'OBJECT',
									'properties' => array(
										'title'      => $str( 120 ),
										'summary'    => $str( 500 ),
										'key_points' => array(
											'type'     => 'ARRAY',
											'items'    => $str( 200 ),
											'maxItems' => 6,
										),
										'topics'     => array(
											'type'     => 'ARRAY',
											'maxItems' => 10,
											'items'    => array(
												'type'     => 'OBJECT',
												'properties' => array(
													'title'   => $str( 120 ),
													'summary' => $str( 300 ),
												),
												'required' => array( 'title' ),
											),
										),
									),
									'required'   => array( 'title', 'summary' ),
								),
							),
						),
						'required'   => array( 'title', 'lessons' ),
					),
				),
			),
			'required'   => array( 'title', 'description', 'sections' ),
		);
	}

	/**
	 * Content blocks schema.
	 *
	 * @param string $label Description.
	 * @return array
	 */
	public static function blocks_schema( $label ) {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'blocks' => array(
					'type'        => 'ARRAY',
					'description' => $label,
					'maxItems'    => 60,
					'items'       => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'type'  => array(
								'type' => 'STRING',
								'enum' => self::BLOCK_TYPES,
							),
							'text'  => array(
								'type'      => 'STRING',
								'maxLength' => 2500,
							),
							'items' => array(
								'type'     => 'ARRAY',
								'maxItems' => 15,
								'items'    => array(
									'type'      => 'STRING',
									'maxLength' => 400,
								),
							),
						),
						'required'   => array( 'type' ),
					),
				),
			),
			'required'   => array( 'blocks' ),
		);
	}

	/**
	 * Quiz schema.
	 *
	 * @return array
	 */
	public static function quiz_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'questions' => array(
					'type'     => 'ARRAY',
					'maxItems' => 30,
					'items'    => array(
						'type'       => 'OBJECT',
						'properties' => array(
							'type'        => array(
								'type' => 'STRING',
								'enum' => array( 'single', 'multiple', 'true_false', 'fill_blank', 'short_answer', 'essay', 'ordering', 'matching' ),
							),
							'text'        => array(
								'type'      => 'STRING',
								'maxLength' => 600,
							),
							'explanation' => array(
								'type'      => 'STRING',
								'maxLength' => 600,
							),
							'answers'     => array(
								'type'     => 'ARRAY',
								'maxItems' => 8,
								'items'    => array(
									'type'       => 'OBJECT',
									'properties' => array(
										'text'    => array(
											'type'      => 'STRING',
											'maxLength' => 300,
										),
										'correct' => array( 'type' => 'BOOLEAN' ),
										'match'   => array(
											'type'      => 'STRING',
											'maxLength' => 300,
										),
									),
									'required'   => array( 'text' ),
								),
							),
						),
						'required'   => array( 'type', 'text' ),
					),
				),
			),
			'required'   => array( 'questions' ),
		);
	}

	/**
	 * Description + certificate schema.
	 *
	 * @return array
	 */
	public static function details_schema() {
		return array(
			'type'       => 'OBJECT',
			'properties' => array(
				'description'       => array(
					'type'      => 'STRING',
					'maxLength' => 2500,
				),
				'excerpt'           => array(
					'type'      => 'STRING',
					'maxLength' => 300,
				),
				'outcomes'          => array(
					'type'     => 'ARRAY',
					'maxItems' => 8,
					'items'    => array(
						'type'      => 'STRING',
						'maxLength' => 200,
					),
				),
				'certificate_title' => array(
					'type'      => 'STRING',
					'maxLength' => 120,
				),
				'certificate_body'  => array(
					'type'      => 'STRING',
					'maxLength' => 600,
				),
			),
			'required'   => array( 'description', 'excerpt', 'certificate_title', 'certificate_body' ),
		);
	}
}
