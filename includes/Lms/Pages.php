<?php
/**
 * "No LMS" fallback: the course becomes a parent page with child lesson pages, self-check quiz pages
 * (native details blocks reveal the answers) and a certificate page.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Lms;

use Dev3\AiCourseBuilder\Course\Markdown;

defined( 'ABSPATH' ) || exit;

/**
 * Pages adapter.
 */
class Pages extends Adapter {

	const TOC_MARK = '<!-- dev3lm:toc -->';

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'pages';
	}

	/**
	 * Label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WordPress pages (no LMS)', 'dev3-ai-course-builder' );
	}

	/**
	 * Always available.
	 *
	 * @return bool
	 */
	public function available() {
		return true;
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
			'section_quiz' => true,
			'types'        => array( 'single', 'multiple', 'true_false', 'fill_blank', 'short_answer', 'essay', 'ordering', 'matching' ),
		);
	}

	/**
	 * Course page.
	 *
	 * @param array $course Course.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error
	 */
	public function create_course( array $course, array $ctx ) {
		return $this->insert(
			array(
				'post_type'    => 'page',
				'post_title'   => $course['title'],
				'post_content' => $this->course_body( $course, true ),
				'post_excerpt' => $course['excerpt'],
			),
			$ctx
		);
	}

	/**
	 * Sections are headings on the course page.
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
	 * Lesson page (child of the course page).
	 *
	 * @param array $lesson Lesson.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error
	 */
	public function create_lesson( array $lesson, array $ctx ) {
		return $this->insert(
			array(
				'post_type'    => 'page',
				'post_title'   => $lesson['title'],
				'post_content' => $this->body( $lesson ),
				'post_parent'  => (int) $ctx['course_post'],
				'menu_order'   => (int) $ctx['global_order'],
			),
			$ctx
		);
	}

	/**
	 * Topic page (child of the lesson page).
	 *
	 * @param array $topic Topic.
	 * @param array $ctx   Context.
	 * @return int|\WP_Error
	 */
	public function create_topic( array $topic, array $ctx ) {
		return $this->insert(
			array(
				'post_type'    => 'page',
				'post_title'   => $topic['title'],
				'post_content' => $this->body( $topic ),
				'post_parent'  => (int) ( $ctx['lesson_post'] ? $ctx['lesson_post'] : $ctx['course_post'] ),
				'menu_order'   => (int) $ctx['order'] + 1,
			),
			$ctx
		);
	}

	/**
	 * Quiz page.
	 *
	 * @param array $quiz Quiz.
	 * @param array $ctx  Context.
	 * @return int|\WP_Error
	 */
	public function create_quiz( array $quiz, array $ctx ) {
		return $this->insert(
			array(
				'post_type'    => 'page',
				'post_title'   => '' !== $quiz['title'] ? $quiz['title'] : __( 'Quiz', 'dev3-ai-course-builder' ),
				'post_content' => self::quiz_blocks( $quiz ),
				'post_parent'  => (int) ( ! empty( $ctx['lesson_post'] ) ? $ctx['lesson_post'] : $ctx['course_post'] ),
				'menu_order'   => (int) ( $ctx['global_order'] ?? 0 ),
			),
			$ctx
		);
	}

	/**
	 * Certificate page.
	 *
	 * @param array $course Course.
	 * @param array $ctx    Context.
	 * @return int|\WP_Error
	 */
	public function create_certificate( array $course, array $ctx ) {
		return $this->insert(
			array(
				'post_type'    => 'page',
				'post_title'   => '' !== $course['certificate']['title'] ? $course['certificate']['title'] : __( 'Certificate', 'dev3-ai-course-builder' ),
				'post_content' => '<!-- wp:dev3lm/certificate {"courseId":' . (int) $ctx['course_post'] . '} /-->',
				'post_parent'  => (int) $ctx['course_post'],
				'menu_order'   => 9999,
			),
			$ctx
		);
	}

	/**
	 * Table of contents on the course page + previous/next links on each lesson page.
	 *
	 * @param int   $course_id Course page.
	 * @param array $map       Map.
	 * @param array $tree      Tree.
	 * @param array $ctx       Context.
	 * @return true
	 */
	public function finalize( $course_id, array $map, array $tree, array $ctx ) {
		$toc   = array( '## ' . __( 'Course content', 'dev3-ai-course-builder' ) );
		$chain = array();
		foreach ( $tree['sections'] as $s ) {
			$toc[] = '### ' . $s['title'];
			$items = array();
			foreach ( $s['lessons'] as $l ) {
				if ( empty( $map[ $l['id'] ] ) ) {
					continue;
				}
				$items[] = '- [' . self::md_text( $l['title'] ) . '](' . get_permalink( (int) $map[ $l['id'] ] ) . ')';
				$chain[] = (int) $map[ $l['id'] ];
				foreach ( $l['topics'] as $t ) {
					if ( ! empty( $map[ $t['id'] ] ) ) {
						$chain[] = (int) $map[ $t['id'] ];
					}
				}
				if ( $l['quiz'] && ! empty( $map[ $l['quiz']['id'] ] ) ) {
					$chain[] = (int) $map[ $l['quiz']['id'] ];
				}
			}
			if ( $s['quiz'] && ! empty( $map[ $s['quiz']['id'] ] ) ) {
				$items[] = '- [' . self::md_text( $s['quiz']['title'] ) . '](' . get_permalink( (int) $map[ $s['quiz']['id'] ] ) . ')';
				$chain[] = (int) $map[ $s['quiz']['id'] ];
			}
			if ( $items ) {
				$toc[] = implode( "\n", $items );
			}
		}
		if ( $tree['quiz'] && ! empty( $map[ $tree['quiz']['id'] ] ) ) {
			$toc[]   = '- [' . self::md_text( $tree['quiz']['title'] ) . '](' . get_permalink( (int) $map[ $tree['quiz']['id'] ] ) . ')';
			$chain[] = (int) $map[ $tree['quiz']['id'] ];
		}
		if ( ! empty( $map['__certificate'] ) ) {
			$toc[] = '[' . __( 'Your certificate', 'dev3-ai-course-builder' ) . '](' . get_permalink( (int) $map['__certificate'] ) . ')';
		}
		$content = (string) get_post_field( 'post_content', $course_id );
		wp_update_post(
			array(
				'ID'           => $course_id,
				'post_content' => wp_slash( $content . "\n\n" . self::TOC_MARK . "\n\n" . Markdown::to_blocks( implode( "\n\n", $toc ) ) ),
			)
		);
		// Previous / next navigation.
		foreach ( $chain as $i => $page ) {
			$links = array();
			if ( $i > 0 ) {
				$links[] = '[← ' . self::md_text( get_the_title( $chain[ $i - 1 ] ) ) . '](' . get_permalink( $chain[ $i - 1 ] ) . ')';
			}
			$links[] = '[' . __( 'Course overview', 'dev3-ai-course-builder' ) . '](' . get_permalink( $course_id ) . ')';
			if ( isset( $chain[ $i + 1 ] ) ) {
				$links[] = '[' . self::md_text( get_the_title( $chain[ $i + 1 ] ) ) . ' →](' . get_permalink( $chain[ $i + 1 ] ) . ')';
			}
			$nav = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->\n\n<!-- wp:paragraph {\"className\":\"dev3lm-pager\"} -->\n<p class=\"dev3lm-pager\">" . implode( ' · ', array_map( array( Markdown::class, 'inline' ), $links ) ) . "</p>\n<!-- /wp:paragraph -->";
			wp_update_post(
				array(
					'ID'           => $page,
					'post_content' => wp_slash( (string) get_post_field( 'post_content', $page ) . "\n\n" . $nav ),
				)
			);
		}
		unset( $ctx );
		return true;
	}

	/**
	 * Escapes text for a Markdown link label.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function md_text( $text ) {
		return str_replace( array( '[', ']' ), array( '(', ')' ), html_entity_decode( (string) $text, ENT_QUOTES ) );
	}

	/**
	 * Self-check quiz as blocks.
	 *
	 * @param array $quiz Quiz.
	 * @return string
	 */
	public static function quiz_blocks( array $quiz ) {
		$out = array();
		if ( '' !== $quiz['description'] ) {
			$out[] = Markdown::to_blocks( $quiz['description'] );
		}
		$details = class_exists( '\WP_Block_Type_Registry' ) && \WP_Block_Type_Registry::get_instance()->is_registered( 'core/details' );
		foreach ( array_values( $quiz['questions'] ) as $i => $q ) {
			/* translators: %d: question number */
			$out[]   = "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . esc_html( sprintf( __( 'Question %d', 'dev3-ai-course-builder' ), $i + 1 ) ) . "</h3>\n<!-- /wp:heading -->";
			$out[]   = "<!-- wp:paragraph -->\n<p>" . esc_html( str_replace( '{blank}', '_____', $q['text'] ) ) . "</p>\n<!-- /wp:paragraph -->";
			$options = array();
			$answer  = array();
			switch ( $q['type'] ) {
				case 'single':
				case 'multiple':
				case 'true_false':
					foreach ( $q['answers'] as $a ) {
						$options[] = $a['text'];
						if ( $a['correct'] ) {
							$answer[] = $a['text'];
						}
					}
					break;
				case 'ordering':
					$options = wp_list_pluck( $q['answers'], 'text' );
					$answer  = array( implode( ' → ', $options ) );
					$options = self::shuffle( $options, $q['id'] );
					break;
				case 'matching':
					$terms   = wp_list_pluck( $q['answers'], 'text' );
					$options = self::shuffle( wp_list_pluck( $q['answers'], 'match' ), $q['id'] );
					foreach ( $q['answers'] as $a ) {
						$answer[] = $a['text'] . ' — ' . $a['match'];
					}
					$out[] = "<!-- wp:paragraph -->\n<p><strong>" . esc_html__( 'Match:', 'dev3-ai-course-builder' ) . '</strong> ' . esc_html( implode( ' · ', $terms ) ) . "</p>\n<!-- /wp:paragraph -->";
					break;
				default:
					$answer = wp_list_pluck( $q['answers'], 'text' );
			}
			if ( $options ) {
				$ordered = 'ordering' !== $q['type'] && 'matching' !== $q['type'];
				$items   = '';
				foreach ( $options as $o ) {
					$items .= '<li>' . esc_html( $o ) . '</li>';
				}
				$out[] = $ordered ? "<!-- wp:list {\"ordered\":true,\"type\":\"upper-alpha\"} -->\n<ol type=\"A\">{$items}</ol>\n<!-- /wp:list -->" : "<!-- wp:list -->\n<ul>{$items}</ul>\n<!-- /wp:list -->";
			}
			$reveal = '';
			if ( $answer ) {
				$reveal .= '<p><strong>' . esc_html__( 'Answer:', 'dev3-ai-course-builder' ) . '</strong> ' . esc_html( implode( '; ', $answer ) ) . '</p>';
			}
			if ( '' !== $q['explanation'] ) {
				$reveal .= '<p>' . esc_html( $q['explanation'] ) . '</p>';
			}
			if ( '' === $reveal ) {
				continue;
			}
			$summary = 'essay' === $q['type'] ? __( 'Show guidance', 'dev3-ai-course-builder' ) : __( 'Show answer', 'dev3-ai-course-builder' );
			if ( $details ) {
				$inner = (string) preg_replace( '/<p>(.*?)<\/p>/s', "<!-- wp:paragraph -->\n<p>$1</p>\n<!-- /wp:paragraph -->", $reveal );
				$out[] = "<!-- wp:details -->\n<details class=\"wp-block-details\"><summary>" . esc_html( $summary ) . "</summary>{$inner}</details>\n<!-- /wp:details -->";
			} else {
				$out[] = "<!-- wp:html -->\n<details class=\"dev3lm-reveal\"><summary>" . esc_html( $summary ) . "</summary>{$reveal}</details>\n<!-- /wp:html -->";
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Stable shuffle (same order on every build).
	 *
	 * @param array  $items Items.
	 * @param string $seed  Seed.
	 * @return array
	 */
	private static function shuffle( array $items, $seed ) {
		$keyed = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$keyed[ md5( $seed . $i . $item ) ] = $item;
		}
		ksort( $keyed );
		$out = array_values( $keyed );
		if ( count( $out ) > 1 && array_values( $items ) === $out ) {
			$out[] = array_shift( $out );
		}
		return $out;
	}

	/**
	 * Lesson types.
	 *
	 * @return string[]
	 */
	public function lesson_types() {
		return array( 'page', 'post' );
	}

	/**
	 * Course page of a lesson page.
	 *
	 * @param int $lesson_id Lesson.
	 * @return int
	 */
	public function course_of( $lesson_id ) {
		return (int) wp_get_post_parent_id( $lesson_id );
	}

	/**
	 * Quiz page under an existing page/post (posts cannot have children: the quiz page links back).
	 *
	 * @param int    $lesson_id Lesson.
	 * @param array  $quiz      Quiz.
	 * @param string $status    Status.
	 * @return int|\WP_Error
	 */
	public function attach_quiz( $lesson_id, array $quiz, $status ) {
		$is_page = 'page' === get_post_type( $lesson_id );
		$content = self::quiz_blocks( $quiz );
		/* translators: %s: lesson title */
		$back     = sprintf( __( 'Back to: %s', 'dev3-ai-course-builder' ), get_the_title( $lesson_id ) );
		$content .= "\n\n<!-- wp:paragraph -->\n<p><a href=\"" . esc_url( get_permalink( $lesson_id ) ) . '">' . esc_html( $back ) . "</a></p>\n<!-- /wp:paragraph -->";
		return $this->insert(
			array(
				'post_type'    => 'page',
				'post_title'   => '' !== $quiz['title'] ? $quiz['title'] : __( 'Quiz', 'dev3-ai-course-builder' ),
				'post_content' => $content,
				'post_parent'  => $is_page ? (int) $lesson_id : 0,
				'post_status'  => $status,
			),
			array(
				'status' => $status,
				'node'   => $quiz['id'],
			)
		);
	}
}
