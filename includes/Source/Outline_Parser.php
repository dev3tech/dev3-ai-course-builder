<?php
/**
 * Non-AI outline parser: turns a pasted outline or extracted document text into a course tree.
 *
 * Understands Markdown headings (#, ##, ###), numbered headings (1., 1.1, 1.1.1), keyword headings
 * ("Module 2:", "Section", "Chapter", "Unit", "Week" → section; "Lesson" → lesson; "Topic" → topic),
 * and indented bullet lists. Body text under a heading becomes that node's content. A heading named
 * "Quiz"/"Questions"/"Assessment" starts a quiz: lines ending in "?" become questions, and
 * "- [x] answer" / "- [ ] answer" lines below a question become choices.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Source;

use Dev3\AiCourseBuilder\Course\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * Parser.
 */
class Outline_Parser {

	/**
	 * Parses text into a tree.
	 *
	 * @param string $text  Outline or document text.
	 * @param string $title Fallback course title.
	 * @return array Tree.
	 */
	public static function parse( $text, $title = '' ) {
		$items        = self::items( (string) $text );
		$heads        = array_values(
			array_filter(
				$items,
				static function ( $i ) {
					return 'head' === $i['kind'];
				}
			)
		);
		$course_title = $title;
		// A single top-level heading at the start that wraps everything is the course title.
		$levels = array_unique( wp_list_pluck( $heads, 'level' ) );
		sort( $levels );
		if ( $heads && 1 === count( array_keys( wp_list_pluck( $heads, 'level' ), $levels[0], true ) ) && count( $levels ) > 1 && $heads[0]['level'] === $levels[0] ) {
			$course_title = $heads[0]['text'];
			$skip         = $heads[0]['index'];
			$items        = array_values(
				array_filter(
					$items,
					static function ( $i ) use ( $skip ) {
						return $i['index'] !== $skip;
					}
				)
			);
			array_shift( $levels );
		}
		// Map the distinct heading depths onto section / lesson / topic.
		$role = array();
		if ( 1 === count( $levels ) ) {
			$role[ $levels[0] ] = 'lesson';
		} else {
			foreach ( array_values( $levels ) as $i => $lvl ) {
				$role[ $lvl ] = 0 === $i ? 'section' : ( 1 === $i ? 'lesson' : 'topic' );
			}
		}
		$tree        = array(
			'course'   => array( 'title' => $course_title ),
			'sections' => array(),
			'quiz'     => null,
		);
		$intro       = array();
		$si          = -1;
		$li          = -1;
		$ti          = -1;
		$quiz        = null; // Reference path to the quiz being filled.
		$question    = -1;
		$ensure_sect = static function () use ( &$tree, &$si, &$li, &$ti ) {
			if ( $si < 0 ) {
				$tree['sections'][] = array(
					'title'   => __( 'Course content', 'dev3-ai-course-builder' ),
					'lessons' => array(),
				);
				$si                 = count( $tree['sections'] ) - 1;
				$li                 = -1;
				$ti                 = -1;
			}
		};
		foreach ( $items as $item ) {
			if ( 'head' === $item['kind'] ) {
				$r = $item['role'] ? $item['role'] : ( $role[ $item['level'] ] ?? 'topic' );
				if ( self::is_quiz_heading( $item['text'] ) ) {
					$ensure_sect();
					$q = array(
						'title'     => $item['text'],
						'questions' => array(),
					);
					if ( 'section' === $r || $li < 0 ) {
						$tree['sections'][ $si ]['quiz'] = $q;
						$quiz                            = array( $si );
					} else {
						$tree['sections'][ $si ]['lessons'][ $li ]['quiz'] = $q;
						$quiz = array( $si, $li );
					}
					$question = -1;
					continue;
				}
				$quiz = null;
				if ( 'section' === $r ) {
					$tree['sections'][] = array(
						'title'   => self::clean_head( $item['text'] ),
						'lessons' => array(),
					);
					$si                 = count( $tree['sections'] ) - 1;
					$li                 = -1;
					$ti                 = -1;
				} elseif ( 'lesson' === $r || $li < 0 ) {
					$ensure_sect();
					$tree['sections'][ $si ]['lessons'][] = array(
						'title'   => self::clean_head( $item['text'] ),
						'content' => '',
						'topics'  => array(),
					);
					$li                                   = count( $tree['sections'][ $si ]['lessons'] ) - 1;
					$ti                                   = -1;
				} else {
					$tree['sections'][ $si ]['lessons'][ $li ]['topics'][] = array(
						'title'   => self::clean_head( $item['text'] ),
						'content' => '',
					);
					$ti = count( $tree['sections'][ $si ]['lessons'][ $li ]['topics'] ) - 1;
				}
				continue;
			}
			// Body text.
			if ( null !== $quiz ) {
				$ref = &self::quiz_ref( $tree, $quiz );
				if ( preg_match( '/^\s*[-*]\s*\[( |x|X)\]\s*(.+)$/', $item['text'], $m ) && $question >= 0 ) {
					$ref['questions'][ $question ]['answers'][] = array(
						'text'    => trim( $m[2] ),
						'correct' => 'x' === strtolower( $m[1] ),
					);
					$n_correct                                  = count( array_filter( wp_list_pluck( $ref['questions'][ $question ]['answers'], 'correct' ) ) );
					$ref['questions'][ $question ]['type']      = $n_correct > 1 ? 'multiple' : 'single';
				} elseif ( preg_match( '/\?\s*$/', $item['text'] ) ) {
					$ref['questions'][] = array(
						'type'    => 'short_answer',
						'text'    => (string) preg_replace( '/^\s*(?:[-*]|\d+[.)]|q\d+[.:)]?)\s*/i', '', $item['text'] ),
						'answers' => array(),
					);
					$question           = count( $ref['questions'] ) - 1;
				}
				unset( $ref );
				continue;
			}
			if ( $si < 0 ) {
				$intro[] = $item['text'];
				continue;
			}
			if ( $li < 0 ) {
				$tree['sections'][ $si ]['summary'] = trim( ( $tree['sections'][ $si ]['summary'] ?? '' ) . "\n" . $item['text'] );
				continue;
			}
			if ( $ti >= 0 ) {
				$tree['sections'][ $si ]['lessons'][ $li ]['topics'][ $ti ]['content'] .= ( '' === $tree['sections'][ $si ]['lessons'][ $li ]['topics'][ $ti ]['content'] ? '' : $item['sep'] ) . $item['text'];
			} else {
				$tree['sections'][ $si ]['lessons'][ $li ]['content'] .= ( '' === $tree['sections'][ $si ]['lessons'][ $li ]['content'] ? '' : $item['sep'] ) . $item['text'];
			}
		}
		if ( $intro ) {
			$tree['course']['description'] = implode( "\n\n", $intro );
		}
		// Only bullets, no headings: every top-level bullet is a lesson, nested bullets are topics.
		if ( ! $heads && ! $tree['sections'] ) {
			$tree = self::from_bullets( (string) $text, $course_title );
		}
		// Lesson summaries from their first sentence.
		foreach ( $tree['sections'] as $s => $section ) {
			foreach ( $section['lessons'] as $l => $lesson ) {
				if ( empty( $lesson['summary'] ) && '' !== ( $lesson['content'] ?? '' ) ) {
					$plain = wp_strip_all_tags( $lesson['content'] );
					$tree['sections'][ $s ]['lessons'][ $l ]['summary'] = mb_substr( (string) strtok( $plain, "\n" ), 0, 300 );
				}
			}
		}
		if ( '' === $tree['course']['title'] && $tree['sections'] ) {
			$tree['course']['title'] = $tree['sections'][0]['title'];
		}
		return Tree::normalize( $tree );
	}

	/**
	 * Reference to a quiz in the tree.
	 *
	 * @param array $tree Tree (by reference).
	 * @param array $path [ si ] or [ si, li ].
	 * @return array
	 */
	private static function &quiz_ref( array &$tree, array $path ) {
		if ( 1 === count( $path ) ) {
			return $tree['sections'][ $path[0] ]['quiz'];
		}
		return $tree['sections'][ $path[0] ]['lessons'][ $path[1] ]['quiz'];
	}

	/**
	 * Bullet-only outlines.
	 *
	 * @param string $text  Text.
	 * @param string $title Title.
	 * @return array
	 */
	private static function from_bullets( $text, $title ) {
		$tree   = array(
			'course'   => array( 'title' => $title ),
			'sections' => array(),
		);
		$lines  = preg_split( '/\n/', str_replace( "\r", '', $text ) );
		$depths = array();
		$parsed = array();
		foreach ( $lines as $line ) {
			if ( ! preg_match( '/^(\s*)(?:[-*•+]|\d+[.)])\s+(.+)$/u', $line, $m ) ) {
				continue;
			}
			$depth    = strlen( str_replace( "\t", '    ', $m[1] ) );
			$depths[] = $depth;
			$parsed[] = array( $depth, trim( $m[2] ) );
		}
		if ( ! $parsed ) {
			// Plain lines: one lesson per non-empty line.
			foreach ( $lines as $line ) {
				if ( '' !== trim( $line ) ) {
					$parsed[] = array( 0, trim( $line ) );
					$depths[] = 0;
				}
			}
		}
		$levels = array_values( array_unique( $depths ) );
		sort( $levels );
		$has_three = count( $levels ) >= 3;
		$si        = -1;
		$li        = -1;
		foreach ( $parsed as $row ) {
			$rank = (int) array_search( $row[0], $levels, true );
			if ( $has_three && 0 === $rank ) {
				$tree['sections'][] = array(
					'title'   => $row[1],
					'lessons' => array(),
				);
				$si                 = count( $tree['sections'] ) - 1;
				$li                 = -1;
				continue;
			}
			if ( $si < 0 ) {
				$tree['sections'][] = array(
					'title'   => count( $levels ) >= 2 && ! $has_three ? __( 'Course content', 'dev3-ai-course-builder' ) : __( 'Course content', 'dev3-ai-course-builder' ),
					'lessons' => array(),
				);
				$si                 = 0;
			}
			$lesson_rank = $has_three ? 1 : 0;
			if ( $rank <= $lesson_rank || $li < 0 ) {
				$tree['sections'][ $si ]['lessons'][] = array(
					'title'  => $row[1],
					'topics' => array(),
				);
				$li                                   = count( $tree['sections'][ $si ]['lessons'] ) - 1;
			} else {
				$tree['sections'][ $si ]['lessons'][ $li ]['topics'][] = array( 'title' => $row[1] );
			}
		}
		return $tree;
	}

	/**
	 * Tokenises text into headings and body lines.
	 *
	 * @param string $text Text.
	 * @return array [ { kind: head|body, level, role, text, index, sep } ]
	 */
	private static function items( $text ) {
		$lines = preg_split( '/\n/', str_replace( "\r", '', $text ) );
		$out   = array();
		$blank = false;
		foreach ( $lines as $i => $line ) {
			$trim = trim( $line );
			if ( '' === $trim ) {
				$blank = true;
				continue;
			}
			$head = self::heading( $trim );
			if ( $head ) {
				$out[] = array(
					'kind'  => 'head',
					'level' => $head[0],
					'role'  => $head[2],
					'text'  => $head[1],
					'index' => $i,
					'sep'   => '',
				);
			} else {
				$is_list = (bool) preg_match( '/^(?:[-*•+]|\d+[.)])\s+/u', $trim );
				$out[]   = array(
					'kind'  => 'body',
					'level' => 0,
					'role'  => '',
					'text'  => $is_list ? (string) preg_replace( '/^[*•+]\s+/u', '- ', rtrim( $line ) ) : $trim,
					'index' => $i,
					'sep'   => $blank ? "\n\n" : ( $is_list ? "\n" : ' ' ),
				);
				if ( $is_list ) {
					$out[ count( $out ) - 1 ]['text'] = ltrim( $out[ count( $out ) - 1 ]['text'] );
				}
			}
			$blank = false;
		}
		return $out;
	}

	/**
	 * Detects a heading line → [ level, text, role ] or null.
	 *
	 * @param string $line Trimmed line.
	 * @return array|null
	 */
	private static function heading( $line ) {
		if ( preg_match( '/^(#{1,6})\s+(.+)$/', $line, $m ) ) {
			return array( strlen( $m[1] ), trim( $m[2], " #\t" ), '' );
		}
		if ( preg_match( '/^(module|section|chapter|unit|week|part)\s+[\w.-]+\s*[:.\-–—]\s*(.+)$/iu', $line, $m ) ) {
			return array( 10, trim( $m[2] ), 'section' );
		}
		if ( preg_match( '/^lesson\s+[\w.-]+\s*[:.\-–—]\s*(.+)$/iu', $line, $m ) ) {
			return array( 11, trim( $m[1] ), 'lesson' );
		}
		if ( preg_match( '/^topic\s+[\w.-]+\s*[:.\-–—]\s*(.+)$/iu', $line, $m ) ) {
			return array( 12, trim( $m[1] ), 'topic' );
		}
		// "1.2.3 Title" numbered headings (short lines without a final period).
		if ( preg_match( '/^(\d+(?:\.\d+){1,3})\.?\s+([^\n]{2,120})$/', $line, $m ) && ! preg_match( '/[.!?:]$/', $m[2] ) ) {
			return array( 20 + substr_count( $m[1], '.' ), trim( $m[2] ), '' );
		}
		return null;
	}

	/**
	 * Quiz heading?
	 *
	 * @param string $text Heading text.
	 * @return bool
	 */
	private static function is_quiz_heading( $text ) {
		return (bool) preg_match( '/^(quiz|questions|assessment|test|knowledge check|review questions)\b/i', trim( $text ) );
	}

	/**
	 * Strips "Lesson 1:" style prefixes kept in markdown headings.
	 *
	 * @param string $text Heading.
	 * @return string
	 */
	private static function clean_head( $text ) {
		$text = (string) preg_replace( '/^(?:module|section|chapter|unit|week|part|lesson|topic)\s+\d+[\w.]*\s*[:.\-–—]\s*/iu', '', trim( $text ) );
		return (string) preg_replace( '/^\d+(?:\.\d+)*[.)]?\s+/', '', $text );
	}

	/**
	 * Template outline for a topic (AI off): N sections × M lessons with sensible titles.
	 *
	 * @param string $topic    Topic.
	 * @param int    $sections Sections.
	 * @param int    $lessons  Lessons per section.
	 * @return array Tree.
	 */
	public static function template( $topic, $sections, $lessons ) {
		$topic    = trim( (string) $topic );
		$names    = array(
			/* translators: %s: course topic */
			__( 'Getting started with %s', 'dev3-ai-course-builder' ),
			/* translators: %s: course topic */
			__( 'Core concepts of %s', 'dev3-ai-course-builder' ),
			/* translators: %s: course topic */
			__( '%s in practice', 'dev3-ai-course-builder' ),
			/* translators: %s: course topic */
			__( 'Going further with %s', 'dev3-ai-course-builder' ),
			/* translators: %s: course topic */
			__( 'Mastering %s', 'dev3-ai-course-builder' ),
		);
		$tree     = array(
			'course'   => array( 'title' => $topic ),
			'sections' => array(),
		);
		$sections = max( 1, min( Tree::MAX_SECTIONS, (int) $sections ) );
		$lessons  = max( 1, min( Tree::MAX_LESSONS, (int) $lessons ) );
		for ( $s = 0; $s < $sections; $s++ ) {
			/* translators: %d: section number */
			$title = isset( $names[ $s ] ) ? sprintf( $names[ $s ], $topic ) : sprintf( __( 'Section %d', 'dev3-ai-course-builder' ), $s + 1 );
			$list  = array();
			for ( $l = 0; $l < $lessons; $l++ ) {
				/* translators: 1: section number, 2: lesson number */
				$list[] = array( 'title' => sprintf( __( 'Lesson %1$d.%2$d', 'dev3-ai-course-builder' ), $s + 1, $l + 1 ) );
			}
			$tree['sections'][] = array(
				'title'   => $title,
				'lessons' => $list,
			);
		}
		return Tree::normalize( $tree );
	}
}
