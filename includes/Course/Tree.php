<?php
/**
 * Course tree: the canonical, sanitised shape of a course blueprint.
 *
 * {
 *   course:   { title, description, excerpt, level, audience, outcomes[], requirements[], duration, certificate{title, body} },
 *   sections: [ { id, title, summary, lessons: [ { id, title, summary, content, topics: [ { id, title, summary, content } ], quiz } ], quiz } ],
 *   quiz:     quiz|null   (final course quiz)
 * }
 * quiz = { id, title, description, passing, questions: [ { id, type, text, explanation, points, answers: [ { text, correct, match } ] } ] }
 *
 * Lesson content is Markdown-lite (see Markdown) and becomes native blocks when the course is created.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Course;

use Dev3\AiCourseBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tree helpers.
 */
class Tree {

	const MAX_SECTIONS  = 30;
	const MAX_LESSONS   = 40;
	const MAX_TOPICS    = 20;
	const MAX_QUESTIONS = 50;
	const MAX_ANSWERS   = 12;
	const MAX_CONTENT   = 60000;

	/**
	 * Empty tree.
	 *
	 * @param string $title Course title.
	 * @return array
	 */
	public static function blank( $title = '' ) {
		return self::normalize( array( 'course' => array( 'title' => $title ) ) );
	}

	/**
	 * New node id.
	 *
	 * @param string $prefix One-letter kind prefix.
	 * @return string
	 */
	public static function new_id( $prefix = 'n' ) {
		return $prefix . strtolower( wp_generate_password( 8, false, false ) );
	}

	/**
	 * Sanitises any input into the canonical tree (ids added where missing, duplicates re-keyed).
	 *
	 * @param array $input Raw tree.
	 * @return array
	 */
	public static function normalize( array $input ) {
		$seen     = array();
		$c        = isset( $input['course'] ) && is_array( $input['course'] ) ? $input['course'] : array();
		$cert     = isset( $c['certificate'] ) && is_array( $c['certificate'] ) ? $c['certificate'] : array();
		$course   = array(
			'title'        => self::line( $c['title'] ?? '', 200 ),
			'description'  => self::text( $c['description'] ?? '', 5000 ),
			'excerpt'      => self::text( $c['excerpt'] ?? '', 600 ),
			'level'        => in_array( $c['level'] ?? '', Settings::LEVELS, true ) ? $c['level'] : 'all_levels',
			'audience'     => self::line( $c['audience'] ?? '', 300 ),
			'outcomes'     => self::lines( $c['outcomes'] ?? array(), 12, 200 ),
			'requirements' => self::lines( $c['requirements'] ?? array(), 12, 200 ),
			'duration'     => min( 100000, max( 0, (int) ( $c['duration'] ?? 0 ) ) ),
			'certificate'  => array(
				'title' => self::line( $cert['title'] ?? '', 160 ),
				'body'  => self::text( $cert['body'] ?? '', 1200 ),
			),
		);
		$sections = array();
		foreach ( array_slice( self::arr( $input['sections'] ?? array() ), 0, self::MAX_SECTIONS ) as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}
			$sid     = self::id( $s['id'] ?? '', 's', $seen );
			$lessons = array();
			foreach ( array_slice( self::arr( $s['lessons'] ?? array() ), 0, self::MAX_LESSONS ) as $l ) {
				if ( ! is_array( $l ) ) {
					continue;
				}
				$lid    = self::id( $l['id'] ?? '', 'l', $seen );
				$topics = array();
				foreach ( array_slice( self::arr( $l['topics'] ?? array() ), 0, self::MAX_TOPICS ) as $t ) {
					if ( ! is_array( $t ) ) {
						continue;
					}
					$topics[] = array(
						'id'       => self::id( $t['id'] ?? '', 't', $seen ),
						'title'    => self::line( $t['title'] ?? '', 200 ),
						'summary'  => self::text( $t['summary'] ?? '', 1000 ),
						'content'  => self::content( $t['content'] ?? '' ),
						'ai_draft' => ! empty( $t['ai_draft'] ),
					);
				}
				$lessons[] = array(
					'id'       => $lid,
					'title'    => self::line( $l['title'] ?? '', 200 ),
					'summary'  => self::text( $l['summary'] ?? '', 1000 ),
					'content'  => self::content( $l['content'] ?? '' ),
					'ai_draft' => ! empty( $l['ai_draft'] ),
					'topics'   => $topics,
					'quiz'     => self::quiz( $l['quiz'] ?? null, $seen ),
				);
			}
			$sections[] = array(
				'id'      => $sid,
				'title'   => self::line( $s['title'] ?? '', 200 ),
				'summary' => self::text( $s['summary'] ?? '', 1000 ),
				'lessons' => $lessons,
				'quiz'    => self::quiz( $s['quiz'] ?? null, $seen ),
			);
		}
		return array(
			'course'   => $course,
			'sections' => $sections,
			'quiz'     => self::quiz( $input['quiz'] ?? null, $seen ),
		);
	}

	/**
	 * Sanitises a quiz (null when absent).
	 *
	 * @param mixed $q    Raw quiz.
	 * @param array $seen Seen ids (by reference).
	 * @return array|null
	 */
	public static function quiz( $q, array &$seen = array() ) {
		if ( ! is_array( $q ) ) {
			return null;
		}
		$questions = array();
		foreach ( array_slice( self::arr( $q['questions'] ?? array() ), 0, self::MAX_QUESTIONS ) as $raw ) {
			$question = self::question( $raw, $seen );
			if ( $question ) {
				$questions[] = $question;
			}
		}
		return array(
			'id'          => self::id( $q['id'] ?? '', 'q', $seen ),
			'title'       => self::line( $q['title'] ?? '', 200 ),
			'description' => self::text( $q['description'] ?? '', 1000 ),
			'passing'     => min( 100, max( 0, (int) ( $q['passing'] ?? Settings::get( 'passing_grade', 70 ) ) ) ),
			'questions'   => $questions,
		);
	}

	/**
	 * Sanitises one question; fixes the answer list so each type stays valid.
	 *
	 * @param mixed $raw  Raw.
	 * @param array $seen Seen ids.
	 * @return array|null
	 */
	public static function question( $raw, array &$seen = array() ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$type = in_array( $raw['type'] ?? '', Settings::QUESTION_TYPES, true ) ? $raw['type'] : 'single';
		$text = self::text( $raw['text'] ?? '', 1000 );
		if ( '' === $text ) {
			return null;
		}
		$answers = array();
		foreach ( array_slice( self::arr( $raw['answers'] ?? array() ), 0, self::MAX_ANSWERS ) as $a ) {
			if ( is_string( $a ) ) {
				$a = array( 'text' => $a );
			}
			if ( ! is_array( $a ) ) {
				continue;
			}
			$answer_text = self::line( $a['text'] ?? '', 300 );
			if ( '' === $answer_text ) {
				continue;
			}
			$answers[] = array(
				'text'    => $answer_text,
				'correct' => ! empty( $a['correct'] ),
				'match'   => self::line( $a['match'] ?? '', 300 ),
			);
		}
		if ( 'true_false' === $type ) {
			$true    = true;
			$first   = $answers ? strtolower( $answers[0]['text'] ) : 'true';
			$correct = null;
			foreach ( $answers as $a ) {
				if ( $a['correct'] ) {
					$correct = strtolower( $a['text'] );
				}
			}
			if ( null !== $correct ) {
				$true = in_array( $correct, array( 'true', 'yes', 'richtig', 'vrai', 'verdadero', 'wahr' ), true ) || ( $correct === $first && ! in_array( $correct, array( 'false', 'no' ), true ) );
			}
			$answers = array(
				array(
					'text'    => 'True',
					'correct' => $true,
					'match'   => '',
				),
				array(
					'text'    => 'False',
					'correct' => ! $true,
					'match'   => '',
				),
			);
		} elseif ( 'single' === $type && $answers ) {
			$found = false;
			foreach ( $answers as $i => $a ) {
				$answers[ $i ]['correct'] = $a['correct'] && ! $found;
				$found                    = $found || $a['correct'];
			}
			if ( ! $found ) {
				$answers[0]['correct'] = true;
			}
		} elseif ( in_array( $type, array( 'fill_blank', 'ordering', 'short_answer', 'essay' ), true ) ) {
			foreach ( $answers as $i => $a ) {
				$answers[ $i ]['correct'] = true;
				$answers[ $i ]['match']   = '';
			}
		}
		if ( 'fill_blank' === $type ) {
			$text = (string) preg_replace( '/_{3,}|\[blank\]|\{dash\}/i', '{blank}', $text );
			if ( false === strpos( $text, '{blank}' ) && $answers ) {
				$text .= ' {blank}';
			}
		}
		if ( in_array( $type, array( 'essay' ), true ) ) {
			$answers = array();
		}
		return array(
			'id'          => self::id( $raw['id'] ?? '', 'k', $seen ),
			'type'        => $type,
			'text'        => $text,
			'explanation' => self::text( $raw['explanation'] ?? '', 1000 ),
			'points'      => min( 100, max( 1, (int) ( $raw['points'] ?? 1 ) ) ),
			'answers'     => $answers,
		);
	}

	/**
	 * Problems that block or weaken a build (for the UI and the build endpoint).
	 *
	 * @param array $tree Tree.
	 * @return array [ { node, level: error|warning, message } ]
	 */
	public static function issues( array $tree ) {
		$out = array();
		if ( '' === $tree['course']['title'] ) {
			$out[] = self::issue( 'course', 'error', __( 'Give the course a title.', 'dev3-ai-course-builder' ) );
		}
		if ( ! $tree['sections'] ) {
			$out[] = self::issue( 'course', 'error', __( 'Add at least one section with a lesson.', 'dev3-ai-course-builder' ) );
		}
		foreach ( $tree['sections'] as $s ) {
			if ( '' === $s['title'] ) {
				$out[] = self::issue( $s['id'], 'error', __( 'A section has no title.', 'dev3-ai-course-builder' ) );
			}
			if ( ! $s['lessons'] ) {
				/* translators: %s: section title */
				$out[] = self::issue( $s['id'], 'warning', sprintf( __( 'Section "%s" has no lessons.', 'dev3-ai-course-builder' ), $s['title'] ) );
			}
			foreach ( $s['lessons'] as $l ) {
				if ( '' === $l['title'] ) {
					$out[] = self::issue( $l['id'], 'error', __( 'A lesson has no title.', 'dev3-ai-course-builder' ) );
				} elseif ( '' === trim( $l['content'] ) ) {
					/* translators: %s: lesson title */
					$out[] = self::issue( $l['id'], 'warning', sprintf( __( 'Lesson "%s" has no content yet.', 'dev3-ai-course-builder' ), $l['title'] ) );
				}
				if ( $l['ai_draft'] ) {
					/* translators: %s: lesson title */
					$out[] = self::issue( $l['id'], 'warning', sprintf( __( 'Review the DEV3 AI draft of "%s".', 'dev3-ai-course-builder' ), $l['title'] ) );
				}
				foreach ( $l['topics'] as $t ) {
					if ( '' === $t['title'] ) {
						$out[] = self::issue( $t['id'], 'error', __( 'A topic has no title.', 'dev3-ai-course-builder' ) );
					}
				}
				if ( $l['quiz'] ) {
					$out = array_merge( $out, self::quiz_issues( $l['quiz'] ) );
				}
			}
			if ( $s['quiz'] ) {
				$out = array_merge( $out, self::quiz_issues( $s['quiz'] ) );
			}
		}
		if ( $tree['quiz'] ) {
			$out = array_merge( $out, self::quiz_issues( $tree['quiz'] ) );
		}
		return $out;
	}

	/**
	 * Quiz issues.
	 *
	 * @param array $quiz Quiz.
	 * @return array
	 */
	private static function quiz_issues( array $quiz ) {
		$out   = array();
		$label = '' !== $quiz['title'] ? $quiz['title'] : __( 'Quiz', 'dev3-ai-course-builder' );
		if ( ! $quiz['questions'] ) {
			/* translators: %s: quiz title */
			$out[] = self::issue( $quiz['id'], 'warning', sprintf( __( '"%s" has no questions (it will be skipped).', 'dev3-ai-course-builder' ), $label ) );
		}
		foreach ( $quiz['questions'] as $q ) {
			$correct = count( array_filter( wp_list_pluck( $q['answers'], 'correct' ) ) );
			if ( in_array( $q['type'], array( 'single', 'multiple' ), true ) && ( count( $q['answers'] ) < 2 || 0 === $correct ) ) {
				/* translators: %s: quiz title */
				$out[] = self::issue( $quiz['id'], 'error', sprintf( __( 'A choice question in "%s" needs at least two answers and a correct one.', 'dev3-ai-course-builder' ), $label ) );
			}
			if ( in_array( $q['type'], array( 'fill_blank', 'ordering', 'matching' ), true ) && ! $q['answers'] ) {
				/* translators: %s: quiz title */
				$out[] = self::issue( $quiz['id'], 'error', sprintf( __( 'A question in "%s" has no answers.', 'dev3-ai-course-builder' ), $label ) );
			}
		}
		return $out;
	}

	/**
	 * Issue row.
	 *
	 * @param string $node    Node id.
	 * @param string $level   error|warning.
	 * @param string $message Message.
	 * @return array
	 */
	private static function issue( $node, $level, $message ) {
		return array(
			'node'    => $node,
			'level'   => $level,
			'message' => $message,
		);
	}

	/**
	 * Counts.
	 *
	 * @param array $tree Tree.
	 * @return array { sections, lessons, topics, quizzes, questions, words }
	 */
	public static function counts( array $tree ) {
		$out      = array(
			'sections'  => count( $tree['sections'] ),
			'lessons'   => 0,
			'topics'    => 0,
			'quizzes'   => 0,
			'questions' => 0,
			'written'   => 0,
			'words'     => 0,
		);
		$add_quiz = static function ( $quiz ) use ( &$out ) {
			if ( $quiz ) {
				++$out['quizzes'];
				$out['questions'] += count( $quiz['questions'] );
			}
		};
		foreach ( $tree['sections'] as $s ) {
			$out['lessons'] += count( $s['lessons'] );
			foreach ( $s['lessons'] as $l ) {
				$out['topics'] += count( $l['topics'] );
				if ( '' !== trim( $l['content'] ) ) {
					++$out['written'];
					$out['words'] += str_word_count( wp_strip_all_tags( $l['content'] ) );
				}
				foreach ( $l['topics'] as $t ) {
					$out['words'] += str_word_count( wp_strip_all_tags( $t['content'] ) );
				}
				$add_quiz( $l['quiz'] );
			}
			$add_quiz( $s['quiz'] );
		}
		$add_quiz( $tree['quiz'] );
		return $out;
	}

	/**
	 * Flat index: node id → { kind, node, section, lesson, position }.
	 *
	 * @param array $tree Tree.
	 * @return array
	 */
	public static function index( array $tree ) {
		$out = array();
		foreach ( $tree['sections'] as $si => $s ) {
			$out[ $s['id'] ] = array(
				'kind'     => 'section',
				'node'     => $s,
				'section'  => null,
				'lesson'   => null,
				'position' => $si,
			);
			foreach ( $s['lessons'] as $li => $l ) {
				$out[ $l['id'] ] = array(
					'kind'     => 'lesson',
					'node'     => $l,
					'section'  => $s['id'],
					'lesson'   => null,
					'position' => $li,
				);
				foreach ( $l['topics'] as $ti => $t ) {
					$out[ $t['id'] ] = array(
						'kind'     => 'topic',
						'node'     => $t,
						'section'  => $s['id'],
						'lesson'   => $l['id'],
						'position' => $ti,
					);
				}
				if ( $l['quiz'] ) {
					$out[ $l['quiz']['id'] ] = array(
						'kind'     => 'quiz',
						'node'     => $l['quiz'],
						'section'  => $s['id'],
						'lesson'   => $l['id'],
						'position' => 0,
					);
				}
			}
			if ( $s['quiz'] ) {
				$out[ $s['quiz']['id'] ] = array(
					'kind'     => 'quiz',
					'node'     => $s['quiz'],
					'section'  => $s['id'],
					'lesson'   => null,
					'position' => count( $s['lessons'] ),
				);
			}
		}
		if ( $tree['quiz'] ) {
			$out[ $tree['quiz']['id'] ] = array(
				'kind'     => 'quiz',
				'node'     => $tree['quiz'],
				'section'  => null,
				'lesson'   => null,
				'position' => count( $tree['sections'] ),
			);
		}
		return $out;
	}

	/**
	 * Replaces one node (lesson/topic content or a quiz) by id. Returns the updated tree.
	 *
	 * @param array    $tree Tree.
	 * @param string   $id   Node id.
	 * @param callable $mutate fn( array $node ): array.
	 * @return array
	 */
	public static function update_node( array $tree, $id, callable $mutate ) {
		if ( $tree['quiz'] && $tree['quiz']['id'] === $id ) {
			$tree['quiz'] = $mutate( $tree['quiz'] );
		}
		foreach ( $tree['sections'] as $si => $s ) {
			if ( $s['id'] === $id ) {
				$tree['sections'][ $si ] = $mutate( $s );
			}
			if ( $s['quiz'] && $s['quiz']['id'] === $id ) {
				$tree['sections'][ $si ]['quiz'] = $mutate( $s['quiz'] );
			}
			foreach ( $s['lessons'] as $li => $l ) {
				if ( $l['id'] === $id ) {
					$tree['sections'][ $si ]['lessons'][ $li ] = $mutate( $l );
				}
				if ( $l['quiz'] && $l['quiz']['id'] === $id ) {
					$tree['sections'][ $si ]['lessons'][ $li ]['quiz'] = $mutate( $l['quiz'] );
				}
				foreach ( $l['topics'] as $ti => $t ) {
					if ( $t['id'] === $id ) {
						$tree['sections'][ $si ]['lessons'][ $li ]['topics'][ $ti ] = $mutate( $t );
					}
				}
			}
		}
		return self::normalize( $tree );
	}

	/**
	 * Adds empty quizzes where the placement setting asks for them.
	 *
	 * @param array  $tree      Tree.
	 * @param string $placement none|section|lesson|course.
	 * @return array
	 */
	public static function place_quizzes( array $tree, $placement ) {
		$make = static function ( $title ) {
			return array(
				'title'     => $title,
				'questions' => array(),
			);
		};
		foreach ( $tree['sections'] as $si => $s ) {
			if ( 'section' === $placement && ! $s['quiz'] ) {
				/* translators: %s: section title */
				$tree['sections'][ $si ]['quiz'] = $make( sprintf( __( 'Quiz: %s', 'dev3-ai-course-builder' ), $s['title'] ) );
			}
			if ( 'lesson' === $placement ) {
				foreach ( $s['lessons'] as $li => $l ) {
					if ( ! $l['quiz'] ) {
						/* translators: %s: lesson title */
						$tree['sections'][ $si ]['lessons'][ $li ]['quiz'] = $make( sprintf( __( 'Check: %s', 'dev3-ai-course-builder' ), $l['title'] ) );
					}
				}
			}
		}
		if ( 'course' === $placement && ! $tree['quiz'] ) {
			$tree['quiz'] = $make( __( 'Final assessment', 'dev3-ai-course-builder' ) );
		}
		return self::normalize( $tree );
	}

	/**
	 * Node id: keeps valid unique ids, otherwise makes a new one.
	 *
	 * @param mixed  $id     Raw id.
	 * @param string $prefix Prefix.
	 * @param array  $seen   Seen (by reference).
	 * @return string
	 */
	private static function id( $id, $prefix, array &$seen ) {
		$id = is_string( $id ) ? strtolower( $id ) : '';
		if ( ! preg_match( '/^[a-z0-9_-]{2,24}$/', $id ) || isset( $seen[ $id ] ) ) {
			do {
				$id = self::new_id( $prefix );
			} while ( isset( $seen[ $id ] ) );
		}
		$seen[ $id ] = true;
		return $id;
	}

	/**
	 * Array or empty.
	 *
	 * @param mixed $v Value.
	 * @return array
	 */
	private static function arr( $v ) {
		return is_array( $v ) ? array_values( $v ) : array();
	}

	/**
	 * Single line.
	 *
	 * @param mixed $v   Value.
	 * @param int   $max Max chars.
	 * @return string
	 */
	public static function line( $v, $max ) {
		return is_scalar( $v ) ? mb_substr( trim( sanitize_text_field( (string) $v ) ), 0, $max ) : '';
	}

	/**
	 * Multi-line plain text.
	 *
	 * @param mixed $v   Value.
	 * @param int   $max Max chars.
	 * @return string
	 */
	public static function text( $v, $max ) {
		return is_scalar( $v ) ? mb_substr( trim( sanitize_textarea_field( (string) $v ) ), 0, $max ) : '';
	}

	/**
	 * List of lines.
	 *
	 * @param mixed $v     Value (array or newline text).
	 * @param int   $count Max items.
	 * @param int   $max   Max chars each.
	 * @return array
	 */
	private static function lines( $v, $count, $max ) {
		if ( is_string( $v ) ) {
			$v = preg_split( '/\r?\n/', $v );
		}
		$out = array();
		foreach ( self::arr( $v ) as $line ) {
			$line = self::line( $line, $max );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_slice( $out, 0, $count );
	}

	/**
	 * Markdown-lite content. Kept as plain text (code samples may contain "<"); it is always escaped
	 * when converted to blocks (Markdown::to_blocks) and rendered as text in the admin.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function content( $v ) {
		if ( ! is_scalar( $v ) ) {
			return '';
		}
		$v = wp_check_invalid_utf8( str_replace( "\r", '', (string) $v ) );
		$v = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v );
		return mb_substr( trim( $v ), 0, self::MAX_CONTENT );
	}
}
