<?php
/**
 * Non-AI quiz drafts: fill-in-the-blank questions from definition-style sentences, true/false from
 * statements, and short-answer prompts from the lesson's headings. Always a draft the admin edits.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Course;

defined( 'ABSPATH' ) || exit;

/**
 * Heuristic generator.
 */
class Basic_Quiz {

	const STOP = array( 'about', 'after', 'again', 'also', 'always', 'because', 'before', 'being', 'between', 'could', 'every', 'first', 'their', 'there', 'these', 'thing', 'things', 'those', 'through', 'where', 'which', 'while', 'would', 'should', 'other', 'using', 'often', 'other', 'under', 'still', 'within', 'without', 'something', 'example' );

	/**
	 * Questions from Markdown-lite material.
	 *
	 * @param string $md    Material.
	 * @param int    $count Max questions.
	 * @param array  $types Allowed types.
	 * @return array Normalised questions.
	 */
	public static function from_text( $md, $count, array $types ) {
		$count     = max( 1, min( 30, (int) $count ) );
		$blocks    = Markdown::parse( (string) $md );
		$sentences = array();
		$headings  = array();
		foreach ( $blocks as $b ) {
			if ( 'heading' === $b['type'] ) {
				$headings[] = Markdown::to_text( $b['text'] );
			} elseif ( in_array( $b['type'], array( 'paragraph', 'callout', 'quote' ), true ) ) {
				foreach ( preg_split( '/(?<=[.!?])\s+/u', Markdown::to_text( $b['text'] ) ) as $s ) {
					$s = trim( str_replace( array( '*', '`' ), '', $s ) );
					$w = str_word_count( $s );
					if ( $w >= 6 && $w <= 40 && preg_match( '/[.!]$/', $s ) ) {
						$sentences[] = $s;
					}
				}
			} elseif ( 'list' === $b['type'] ) {
				foreach ( $b['items'] as $item ) {
					$item = trim( str_replace( array( '*', '`' ), '', Markdown::to_text( $item ) ) );
					if ( str_word_count( $item ) >= 6 ) {
						$sentences[] = rtrim( $item, '.' ) . '.';
					}
				}
			}
		}
		$out  = array();
		$used = array();
		// 1) Definitions: "X is/are/means …" → blank the defined term (or the key word).
		if ( in_array( 'fill_blank', $types, true ) || in_array( 'short_answer', $types, true ) ) {
			foreach ( $sentences as $s ) {
				if ( count( $out ) >= $count ) {
					break;
				}
				$term = self::key_term( $s );
				if ( ! $term || isset( $used[ strtolower( $term ) ] ) ) {
					continue;
				}
				$used[ strtolower( $term ) ] = true;
				$blanked                     = (string) preg_replace( '/\b' . preg_quote( $term, '/' ) . '\b/u', '{blank}', $s, 1 );
				if ( in_array( 'fill_blank', $types, true ) ) {
					$out[] = Tree::question(
						array(
							'type'        => 'fill_blank',
							'text'        => $blanked,
							'answers'     => array( array( 'text' => $term ) ),
							'explanation' => $s,
						)
					);
				} else {
					$out[] = Tree::question(
						array(
							'type'        => 'short_answer',
							'text'        => str_replace( '{blank}', '_____', $blanked ),
							'answers'     => array( array( 'text' => $term ) ),
							'explanation' => $s,
						)
					);
				}
			}
		}
		// 2) True/false from remaining statements (true as written).
		if ( in_array( 'true_false', $types, true ) ) {
			foreach ( $sentences as $s ) {
				if ( count( $out ) >= $count ) {
					break;
				}
				if ( isset( $used[ 'tf:' . $s ] ) ) {
					continue;
				}
				$used[ 'tf:' . $s ] = true;
				$out[]              = Tree::question(
					array(
						'type'        => 'true_false',
						'text'        => $s,
						'answers'     => array(
							array(
								'text'    => 'True',
								'correct' => true,
							),
						),
						/* translators: %s: the statement */
						'explanation' => __( 'The lesson states this directly.', 'dev3-ai-course-builder' ),
					)
				);
			}
		}
		// 3) Open questions from headings.
		$open = in_array( 'essay', $types, true ) ? 'essay' : ( in_array( 'short_answer', $types, true ) ? 'short_answer' : '' );
		if ( $open ) {
			foreach ( $headings as $h ) {
				if ( count( $out ) >= $count ) {
					break;
				}
				if ( preg_match( '/key takeaways|summary|introduction|conclusion/i', $h ) ) {
					continue;
				}
				$out[] = Tree::question(
					array(
						'type'        => $open,
						/* translators: %s: heading text */
						'text'        => sprintf( __( 'In your own words, explain: %s', 'dev3-ai-course-builder' ), $h ),
						'explanation' => __( 'Compare the answer with the section of the lesson under this heading.', 'dev3-ai-course-builder' ),
					)
				);
			}
		}
		return array_values( array_filter( array_slice( $out, 0, $count ) ) );
	}

	/**
	 * Picks the term to blank: the subject of "X is/are/means/refers to", else the longest uncommon word.
	 *
	 * @param string $s Sentence.
	 * @return string
	 */
	public static function key_term( $s ) {
		if ( preg_match( '/^(?:an?\s+|the\s+)?([\p{L}][\p{L}\p{N}\'-]*(?:\s+[\p{L}][\p{L}\p{N}\'-]*){0,2})\s+(?:is|are|means|refers to|describes)\s/iu', $s, $m ) ) {
			$term = trim( $m[1] );
			if ( mb_strlen( $term ) >= 3 && ! in_array( strtolower( $term ), array( 'this', 'that', 'it', 'there', 'which', 'what' ), true ) ) {
				return $term;
			}
		}
		$best = '';
		foreach ( preg_split( '/[^\p{L}\p{N}\'-]+/u', $s ) as $w ) {
			$lw = mb_strtolower( $w );
			if ( mb_strlen( $w ) >= 6 && ! in_array( $lw, self::STOP, true ) && mb_strlen( $w ) > mb_strlen( $best ) ) {
				$best = $w;
			}
		}
		return $best;
	}
}
