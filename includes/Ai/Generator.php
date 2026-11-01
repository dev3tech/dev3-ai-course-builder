<?php
/**
 * DEV3 AI generators: outline, lesson content, quiz questions, description/certificate and rewrites.
 * Every method returns a draft; nothing is saved here.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Ai;

use Dev3\AiCourseBuilder\Core3\Api\Ai;
use Dev3\AiCourseBuilder\Core3\Api\Prompts;
use Dev3\AiCourseBuilder\Course\Markdown;
use Dev3\AiCourseBuilder\Course\Tree;
use Dev3\AiCourseBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Generator.
 */
class Generator {

	const LENGTHS = array(
		'short'  => 'about 300-450 words',
		'medium' => 'about 600-900 words',
		'long'   => 'about 1200-1600 words',
	);

	/**
	 * Course outline draft.
	 *
	 * @param array $source { topic, outline, text, file{path,mime,kind} }.
	 * @param array $opts   Settings::options().
	 * @return array|\WP_Error { tree, model, tokens, cached }
	 */
	public static function outline( array $source, array $opts ) {
		$text  = trim( (string) ( $source['outline'] ?? '' ) . "\n\n" . (string) ( $source['text'] ?? '' ) );
		$max   = (int) Settings::get( 'max_source_chars', 30000 );
		$extra = array(
			'model' => Ai::model( 'pro' === Settings::get( 'outline_model', 'pro' ) ? 'pro' : 'default' ),
			'cache' => DAY_IN_SECONDS,
		);
		$file  = isset( $source['file'] ) && is_array( $source['file'] ) ? $source['file'] : null;
		$note  = '';
		if ( $file && 'pdf' === $file['kind'] && mb_strlen( $text ) < 200 ) {
			// No usable text layer (scanned PDF): send the PDF itself.
			$extra['images'] = array(
				array(
					'mime' => 'application/pdf',
					'data' => base64_encode( (string) file_get_contents( $file['path'] ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				),
			);
			$note            = 'The attached PDF is the source material.';
			$text            = '';
		}
		if ( '' !== trim( (string) ( $source['outline'] ?? '' ) ) ) {
			$note .= ' The author supplied an outline: keep its sections and lessons, fill gaps and improve titles only where needed.';
		}
		$result = Prompts::run(
			'outline',
			array(
				'language'     => $opts['language'],
				'tone'         => $opts['tone'],
				'topic'        => mb_substr( (string) ( $source['topic'] ?? '' ), 0, 2000 ),
				'audience'     => '' !== $opts['audience'] ? $opts['audience'] : 'general learners',
				'level'        => $opts['level'],
				'goals'        => $opts['goals'] ?? '',
				'sections'     => $opts['sections'],
				'lessons'      => $opts['lessons_per_section'],
				'topics'       => (int) $opts['topics_per_lesson'],
				'outline_note' => trim( $note ),
				'source'       => '' === $text ? '(none)' : mb_substr( $text, 0, $max ),
			),
			'admin',
			$extra
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = (array) ( $result['data'] ?? array() );
		$tree = self::outline_tree( $data, $opts );
		return self::envelope( $result, array( 'tree' => $tree ) );
	}

	/**
	 * AI outline JSON → tree (counts clamped, key points kept as lesson summary bullets).
	 *
	 * @param array $data Decoded JSON.
	 * @param array $opts Options.
	 * @return array
	 */
	public static function outline_tree( array $data, array $opts ) {
		$sections = array();
		foreach ( array_slice( (array) ( $data['sections'] ?? array() ), 0, Tree::MAX_SECTIONS ) as $s ) {
			$lessons = array();
			foreach ( array_slice( (array) ( $s['lessons'] ?? array() ), 0, Tree::MAX_LESSONS ) as $l ) {
				$summary = (string) ( $l['summary'] ?? '' );
				$points  = array_filter( array_map( 'strval', (array) ( $l['key_points'] ?? array() ) ) );
				if ( $points ) {
					$summary .= "\n- " . implode( "\n- ", $points );
				}
				$topics = array();
				if ( (int) $opts['topics_per_lesson'] > 0 ) {
					foreach ( array_slice( (array) ( $l['topics'] ?? array() ), 0, (int) $opts['topics_per_lesson'] ) as $t ) {
						$topics[] = array(
							'title'   => (string) ( $t['title'] ?? '' ),
							'summary' => (string) ( $t['summary'] ?? '' ),
						);
					}
				}
				$lessons[] = array(
					'title'   => (string) ( $l['title'] ?? '' ),
					'summary' => $summary,
					'topics'  => $topics,
				);
			}
			$sections[] = array(
				'title'   => (string) ( $s['title'] ?? '' ),
				'summary' => (string) ( $s['summary'] ?? '' ),
				'lessons' => $lessons,
			);
		}
		$tree = Tree::normalize(
			array(
				'course'   => array(
					'title'        => $data['title'] ?? '',
					'description'  => $data['description'] ?? '',
					'excerpt'      => $data['excerpt'] ?? '',
					'audience'     => $data['audience'] ?? ( $opts['audience'] ?? '' ),
					'level'        => $data['level'] ?? ( $opts['level'] ?? 'all_levels' ),
					'duration'     => (int) ( $data['duration_minutes'] ?? 0 ),
					'outcomes'     => (array) ( $data['outcomes'] ?? array() ),
					'requirements' => (array) ( $data['requirements'] ?? array() ),
				),
				'sections' => $sections,
			)
		);
		return Tree::place_quizzes( $tree, (string) ( $opts['quiz_placement'] ?? 'none' ) );
	}

	/**
	 * Lesson (or topic) content draft.
	 *
	 * @param array  $tree   Tree.
	 * @param string $node   Lesson/topic id.
	 * @param string $source Source text (may be empty).
	 * @param array  $opts   Options.
	 * @return array|\WP_Error { content, model, tokens, cached }
	 */
	public static function lesson( array $tree, $node, $source, array $opts ) {
		$index = Tree::index( $tree );
		if ( ! isset( $index[ $node ] ) || ! in_array( $index[ $node ]['kind'], array( 'lesson', 'topic' ), true ) ) {
			return new \WP_Error( 'dev3lm_node', __( 'That lesson no longer exists in this course.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) );
		}
		$entry    = $index[ $node ];
		$lesson   = $entry['node'];
		$section  = $index[ $entry['section'] ]['node'];
		$siblings = array();
		foreach ( $section['lessons'] as $l ) {
			if ( $l['id'] !== $node ) {
				$siblings[] = $l['title'];
			}
		}
		$title = $lesson['title'];
		if ( 'topic' === $entry['kind'] ) {
			$parent = $index[ $entry['lesson'] ]['node'];
			$title  = $parent['title'] . ' → ' . $lesson['title'];
		}
		$result = Prompts::run(
			'lesson',
			array(
				'language' => $opts['language'],
				'tone'     => $opts['tone'],
				'course'   => $tree['course']['title'],
				'audience' => '' !== $tree['course']['audience'] ? $tree['course']['audience'] : ( '' !== $opts['audience'] ? $opts['audience'] : 'general learners' ),
				'level'    => $tree['course']['level'],
				'section'  => $section['title'],
				'lesson'   => $title,
				'summary'  => '' !== $lesson['summary'] ? $lesson['summary'] : '(none)',
				'points'   => '' !== trim( (string) $lesson['content'] ) ? 'Expand these author notes: ' . mb_substr( Markdown::to_text( $lesson['content'] ), 0, 3000 ) : '(use the summary)',
				'siblings' => $siblings ? implode( '; ', array_slice( $siblings, 0, 15 ) ) : '(none)',
				'length'   => self::LENGTHS[ $opts['lesson_length'] ] ?? self::LENGTHS['medium'],
				'source'   => '' === trim( (string) $source ) ? '(none)' : self::excerpt( (string) $source, $title . ' ' . $lesson['summary'], 6000 ),
			),
			'admin',
			array( 'cache' => DAY_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$md = Markdown::from_ai_blocks( (array) ( $result['data']['blocks'] ?? array() ) );
		if ( '' === $md ) {
			return new \WP_Error( 'dev3lm_empty', __( 'DEV3 AI returned an empty lesson. Try again.', 'dev3-ai-course-builder' ), array( 'status' => 502 ) );
		}
		return self::envelope( $result, array( 'content' => $md ) );
	}

	/**
	 * Quiz questions draft from material.
	 *
	 * @param string $material Markdown/plain text.
	 * @param array  $args     { count, types[], title, course, level, language, tone }.
	 * @return array|\WP_Error { questions, model, tokens, cached }
	 */
	public static function quiz( $material, array $args ) {
		$material = trim( (string) $material );
		if ( mb_strlen( $material ) < 40 ) {
			return new \WP_Error( 'dev3lm_no_material', __( 'There is not enough lesson text to write questions from. Write or generate the lesson content first.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		$types  = array_values( array_intersect( Settings::QUESTION_TYPES, (array) ( $args['types'] ?? array() ) ) );
		$types  = $types ? $types : array( 'single' );
		$count  = min( 30, max( 1, (int) ( $args['count'] ?? 5 ) ) );
		$result = Prompts::run(
			'quiz',
			array(
				'language' => $args['language'] ?? Settings::get( 'language', 'English' ),
				'tone'     => $args['tone'] ?? Settings::get( 'tone', 'friendly' ),
				'count'    => $count,
				'title'    => (string) ( $args['title'] ?? 'Quiz' ),
				'course'   => (string) ( $args['course'] ?? '' ),
				'level'    => (string) ( $args['level'] ?? 'all_levels' ),
				'types'    => implode( ', ', $types ),
				'material' => mb_substr( $material, 0, (int) Settings::get( 'max_source_chars', 30000 ) ),
			),
			'admin',
			array( 'cache' => DAY_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$questions = array();
		foreach ( (array) ( $result['data']['questions'] ?? array() ) as $raw ) {
			if ( ! is_array( $raw ) || ! in_array( $raw['type'] ?? '', $types, true ) ) {
				continue;
			}
			// A choice question without a marked answer means the model got the key wrong: drop it
			// instead of guessing which answer is right.
			if ( in_array( $raw['type'], array( 'single', 'multiple' ), true ) && ! self::has_correct( (array) ( $raw['answers'] ?? array() ) ) ) {
				continue;
			}
			$q = Tree::question( $raw );
			if ( $q && self::valid_question( $q ) ) {
				$questions[] = $q;
			}
		}
		$questions = array_slice( $questions, 0, $count );
		if ( ! $questions ) {
			return new \WP_Error( 'dev3lm_empty', __( 'DEV3 AI returned no usable questions. Try again or choose other question types.', 'dev3-ai-course-builder' ), array( 'status' => 502 ) );
		}
		return self::envelope( $result, array( 'questions' => $questions ) );
	}

	/**
	 * Whether a raw AI answer list marks at least one answer correct.
	 *
	 * @param array $answers Raw answers.
	 * @return bool
	 */
	private static function has_correct( array $answers ) {
		foreach ( $answers as $a ) {
			if ( is_array( $a ) && ! empty( $a['correct'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Server-side validation of an AI question (drops broken ones).
	 *
	 * @param array $q Normalised question.
	 * @return bool
	 */
	public static function valid_question( array $q ) {
		$n       = count( $q['answers'] );
		$correct = count( array_filter( wp_list_pluck( $q['answers'], 'correct' ) ) );
		switch ( $q['type'] ) {
			case 'single':
				return $n >= 2 && 1 === $correct;
			case 'multiple':
				return $n >= 2 && $correct >= 1;
			case 'fill_blank':
				return $n >= 1 && substr_count( $q['text'], '{blank}' ) >= 1;
			case 'ordering':
				return $n >= 2;
			case 'matching':
				return $n >= 2 && count( array_filter( wp_list_pluck( $q['answers'], 'match' ) ) ) === $n;
			default:
				return true;
		}
	}

	/**
	 * Description + certificate draft.
	 *
	 * @param array $tree Tree.
	 * @param array $opts Options.
	 * @return array|\WP_Error { draft{description, excerpt, outcomes, certificate_title, certificate_body}, model, tokens, cached }
	 */
	public static function details( array $tree, array $opts ) {
		$structure = array();
		foreach ( $tree['sections'] as $s ) {
			$structure[] = '- ' . $s['title'] . ': ' . implode( '; ', wp_list_pluck( $s['lessons'], 'title' ) );
		}
		$result = Prompts::run(
			'details',
			array(
				'language'  => $opts['language'],
				'tone'      => $opts['tone'],
				'course'    => $tree['course']['title'],
				'audience'  => '' !== $tree['course']['audience'] ? $tree['course']['audience'] : 'general learners',
				'level'     => $tree['course']['level'],
				'structure' => implode( "\n", array_slice( $structure, 0, 30 ) ),
			),
			'admin',
			array( 'cache' => DAY_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d = (array) ( $result['data'] ?? array() );
		return self::envelope(
			$result,
			array(
				'draft' => array(
					'description'       => Tree::text( $d['description'] ?? '', 5000 ),
					'excerpt'           => Tree::text( $d['excerpt'] ?? '', 600 ),
					'outcomes'          => implode( "\n", array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $d['outcomes'] ?? array() ) ) ) ),
					'certificate_title' => Tree::line( $d['certificate_title'] ?? '', 160 ),
					'certificate_body'  => Tree::text( $d['certificate_body'] ?? '', 1200 ),
				),
			)
		);
	}

	/**
	 * Non-AI description + certificate from the tree (templates).
	 *
	 * @param array $tree Tree.
	 * @return array Same draft shape as details().
	 */
	public static function details_basic( array $tree ) {
		$title    = '' !== $tree['course']['title'] ? $tree['course']['title'] : __( 'this course', 'dev3-ai-course-builder' );
		$sections = wp_list_pluck( $tree['sections'], 'title' );
		$counts   = Tree::counts( $tree );
		/* translators: 1: course title, 2: number of sections, 3: number of lessons */
		$desc = sprintf( __( '%1$s is a step-by-step course in %2$d sections and %3$d lessons.', 'dev3-ai-course-builder' ), $title, $counts['sections'], $counts['lessons'] );
		if ( $sections ) {
			/* translators: %s: list of section titles */
			$desc .= "\n\n" . sprintf( __( 'You will work through: %s.', 'dev3-ai-course-builder' ), implode( ', ', array_slice( $sections, 0, 8 ) ) );
		}
		if ( $counts['quizzes'] ) {
			$desc .= "\n\n" . __( 'Short quizzes help you check what you learned along the way.', 'dev3-ai-course-builder' );
		}
		return array(
			'description'       => $desc,
			/* translators: %s: course title */
			'excerpt'           => sprintf( __( 'Learn %s step by step, with practical lessons and quizzes.', 'dev3-ai-course-builder' ), $title ),
			'outcomes'          => implode( "\n", array_slice( $sections, 0, 6 ) ),
			'certificate_title' => __( 'Certificate of Completion', 'dev3-ai-course-builder' ),
			'certificate_body'  => __( 'This certifies that {learner} has successfully completed {course} on {date}.', 'dev3-ai-course-builder' ),
		);
	}

	/**
	 * Lesson rewrite draft.
	 *
	 * @param string $title   Lesson title.
	 * @param string $md      Current content (Markdown-lite).
	 * @param string $mode    Mode key.
	 * @param array  $args    { extra, tone, reading_level, target_language, language }.
	 * @return array|\WP_Error { content, model, tokens, cached }
	 */
	public static function rewrite( $title, $md, $mode, array $args ) {
		$modes = Prompt_Library::rewrite_modes();
		if ( ! isset( $modes[ $mode ] ) ) {
			return new \WP_Error( 'dev3lm_mode', __( 'Choose how to rewrite the lesson.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		if ( mb_strlen( trim( (string) $md ) ) < 20 ) {
			return new \WP_Error( 'dev3lm_no_material', __( 'This lesson has no text to rewrite yet.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		$instruction = Prompts::fill(
			$modes[ $mode ],
			array(
				'tone'            => $args['tone'] ?? Settings::get( 'tone', 'friendly' ),
				'reading_level'   => $args['reading_level'] ?? 'grade 8 (plain English)',
				'target_language' => $args['target_language'] ?? 'Spanish',
			)
		);
		$language    = 'translate' === $mode ? ( $args['target_language'] ?? 'Spanish' ) : ( $args['language'] ?? Settings::get( 'language', 'English' ) );
		$result      = Prompts::run(
			'rewrite',
			array(
				'language'    => $language,
				'tone'        => $args['tone'] ?? Settings::get( 'tone', 'friendly' ),
				'title'       => $title,
				'instruction' => $instruction,
				'extra'       => '' !== trim( (string) ( $args['extra'] ?? '' ) ) ? mb_substr( (string) $args['extra'], 0, 600 ) : '(none)',
				'content'     => mb_substr( (string) $md, 0, (int) Settings::get( 'max_source_chars', 30000 ) ),
			),
			'admin',
			array( 'cache' => HOUR_IN_SECONDS )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$out = Markdown::from_ai_blocks( (array) ( $result['data']['blocks'] ?? array() ) );
		if ( '' === $out ) {
			return new \WP_Error( 'dev3lm_empty', __( 'DEV3 AI returned an empty rewrite. Try again.', 'dev3-ai-course-builder' ), array( 'status' => 502 ) );
		}
		return self::envelope( $result, array( 'content' => $out ) );
	}

	/**
	 * Most relevant parts of a long source for a query (keeps document order).
	 *
	 * @param string $source Source.
	 * @param string $query  Query.
	 * @param int    $max    Max chars.
	 * @return string
	 */
	public static function excerpt( $source, $query, $max ) {
		if ( mb_strlen( $source ) <= $max ) {
			return $source;
		}
		$chunks = array();
		$buf    = '';
		foreach ( preg_split( '/\n{2,}/', $source ) as $para ) {
			if ( mb_strlen( $buf ) + mb_strlen( $para ) > 1200 && '' !== $buf ) {
				$chunks[] = $buf;
				$buf      = '';
			}
			$buf .= ( '' === $buf ? '' : "\n\n" ) . $para;
		}
		if ( '' !== $buf ) {
			$chunks[] = $buf;
		}
		$words = array_unique( array_filter( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $query ) ), static fn( $w ) => mb_strlen( $w ) > 3 ) );
		$score = array();
		foreach ( $chunks as $i => $chunk ) {
			$lower       = mb_strtolower( $chunk );
			$score[ $i ] = 0;
			foreach ( $words as $w ) {
				$score[ $i ] += substr_count( $lower, $w );
			}
		}
		arsort( $score );
		$pick = array();
		$len  = 0;
		foreach ( array_keys( $score ) as $i ) {
			if ( $len + mb_strlen( $chunks[ $i ] ) > $max ) {
				continue;
			}
			$pick[ $i ] = $chunks[ $i ];
			$len       += mb_strlen( $chunks[ $i ] );
		}
		ksort( $pick );
		return implode( "\n\n[…]\n\n", $pick );
	}

	/**
	 * Adds model/tokens/cached to a payload.
	 *
	 * @param array $result  Ai::run result.
	 * @param array $payload Payload.
	 * @return array
	 */
	private static function envelope( array $result, array $payload ) {
		return array_merge(
			$payload,
			array(
				'model'  => (string) ( $result['model'] ?? '' ),
				'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
				'cached' => ! empty( $result['cached'] ),
			)
		);
	}
}
