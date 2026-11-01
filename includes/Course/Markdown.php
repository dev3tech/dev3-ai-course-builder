<?php
/**
 * Markdown-lite <-> native Gutenberg blocks.
 *
 * Supported: "#"/"##" → h2, "###" → h3, "####" → h4, paragraphs, "- "/"* " lists, "1. " ordered lists,
 * "> " quotes, "> Tip: …" callouts, ``` code fences, "---" separators, **bold**, *italic*, `code`, [text](url).
 * Output uses block markup that every WordPress version since 6.0 parses (lists/quotes in the classic
 * inner-HTML form, which newer editors migrate silently).
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Course;

defined( 'ABSPATH' ) || exit;

/**
 * Converter.
 */
class Markdown {

	/**
	 * Parses Markdown-lite into a block list [ { type, level?, text?, items?, ordered? } ].
	 *
	 * @param string $md Markdown.
	 * @return array
	 */
	public static function parse( $md ) {
		$lines  = preg_split( '/\n/', str_replace( "\r", '', (string) $md ) );
		$blocks = array();
		$para   = array();
		$list   = null;
		$quote  = array();
		$code   = null;
		$flush  = static function () use ( &$blocks, &$para, &$list, &$quote ) {
			if ( $para ) {
				$blocks[] = array(
					'type' => 'paragraph',
					'text' => implode( ' ', $para ),
				);
				$para     = array();
			}
			if ( $list ) {
				$blocks[] = $list;
				$list     = null;
			}
			if ( $quote ) {
				$text     = implode( ' ', $quote );
				$type     = preg_match( '/^(tip|note|important|warning|key takeaway|remember)\s*:/i', $text ) ? 'callout' : 'quote';
				$blocks[] = array(
					'type' => $type,
					'text' => $text,
				);
				$quote    = array();
			}
		};
		foreach ( $lines as $line ) {
			if ( null !== $code ) {
				if ( preg_match( '/^\s*```/', $line ) ) {
					$blocks[] = array(
						'type' => 'code',
						'text' => implode( "\n", $code ),
					);
					$code     = null;
				} else {
					$code[] = $line;
				}
				continue;
			}
			$trim = trim( $line );
			if ( preg_match( '/^```/', $trim ) ) {
				$flush();
				$code = array();
				continue;
			}
			if ( '' === $trim ) {
				$flush();
				continue;
			}
			if ( preg_match( '/^(#{1,4})\s+(.+)$/', $trim, $m ) ) {
				$flush();
				$blocks[] = array(
					'type'  => 'heading',
					'level' => max( 2, min( 4, strlen( $m[1] ) ) ),
					'text'  => trim( $m[2] ),
				);
				continue;
			}
			if ( preg_match( '/^(-{3,}|\*{3,})$/', $trim ) ) {
				$flush();
				$blocks[] = array( 'type' => 'separator' );
				continue;
			}
			if ( preg_match( '/^(?:[-*•]|(\d+)[.)])\s+(.+)$/u', $trim, $m ) ) {
				$ordered = '' !== $m[1];
				if ( $para || $quote ) {
					$saved_list = $list;
					$list       = null;
					$flush();
					$list = $saved_list;
				}
				if ( $list && $list['ordered'] !== $ordered ) {
					$flush();
				}
				if ( ! $list ) {
					$list = array(
						'type'    => 'list',
						'ordered' => $ordered,
						'items'   => array(),
					);
				}
				$list['items'][] = trim( $m[2] );
				continue;
			}
			if ( preg_match( '/^>\s?(.*)$/', $trim, $m ) ) {
				if ( $para || $list ) {
					$saved = $quote;
					$quote = array();
					$flush();
					$quote = $saved;
				}
				$quote[] = trim( $m[1] );
				continue;
			}
			if ( $list || $quote ) {
				$flush();
			}
			$para[] = $trim;
		}
		if ( null !== $code ) {
			$blocks[] = array(
				'type' => 'code',
				'text' => implode( "\n", $code ),
			);
		}
		$flush();
		return $blocks;
	}

	/**
	 * Markdown-lite → serialized block markup.
	 *
	 * @param string $md Markdown.
	 * @return string
	 */
	public static function to_blocks( $md ) {
		$out = array();
		foreach ( self::parse( $md ) as $b ) {
			switch ( $b['type'] ) {
				case 'heading':
					$level = (int) $b['level'];
					$attrs = 2 === $level ? '' : ' {"level":' . $level . '}';
					$out[] = "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">" . self::inline( $b['text'] ) . "</h{$level}>\n<!-- /wp:heading -->";
					break;
				case 'list':
					$tag   = $b['ordered'] ? 'ol' : 'ul';
					$attrs = $b['ordered'] ? ' {"ordered":true}' : '';
					$items = '';
					foreach ( $b['items'] as $item ) {
						$items .= '<li>' . self::inline( $item ) . '</li>';
					}
					$out[] = "<!-- wp:list{$attrs} -->\n<{$tag}>{$items}</{$tag}>\n<!-- /wp:list -->";
					break;
				case 'quote':
					$out[] = "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>" . self::inline( $b['text'] ) . "</p></blockquote>\n<!-- /wp:quote -->";
					break;
				case 'callout':
					$out[] = "<!-- wp:paragraph {\"className\":\"dev3lm-callout\"} -->\n<p class=\"dev3lm-callout\">" . self::inline( $b['text'] ) . "</p>\n<!-- /wp:paragraph -->";
					break;
				case 'code':
					$out[] = "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>" . esc_html( $b['text'] ) . "</code></pre>\n<!-- /wp:code -->";
					break;
				case 'separator':
					$out[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
					break;
				default:
					$out[] = "<!-- wp:paragraph -->\n<p>" . self::inline( $b['text'] ) . "</p>\n<!-- /wp:paragraph -->";
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Inline formatting (escapes first, then applies **, *, `code`, links).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function inline( $text ) {
		$html = esc_html( (string) $text );
		$code = array();
		$html = (string) preg_replace_callback(
			'/`([^`]+)`/',
			static function ( $m ) use ( &$code ) {
				$code[] = '<code>' . $m[1] . '</code>';
				return "\x01" . ( count( $code ) - 1 ) . "\x02";
			},
			$html
		);
		$html = (string) preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
		$html = (string) preg_replace( '/(?<![*\w])\*(?!\s)(.+?)(?<!\s)\*(?![*\w])/', '<em>$1</em>', $html );
		$html = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( $m ) {
				$url = esc_url( html_entity_decode( $m[2], ENT_QUOTES ) );
				return $url ? '<a href="' . $url . '">' . $m[1] . '</a>' : $m[1];
			},
			$html
		);
		return (string) preg_replace_callback(
			"/\x01(\d+)\x02/",
			static function ( $m ) use ( $code ) {
				return $code[ (int) $m[1] ] ?? '';
			},
			$html
		);
	}

	/**
	 * Structured AI blocks → Markdown-lite.
	 *
	 * @param array $blocks [ { type, text, items[] } ].
	 * @return string
	 */
	public static function from_ai_blocks( array $blocks ) {
		$out = array();
		foreach ( $blocks as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$type  = (string) ( $b['type'] ?? 'paragraph' );
			$text  = trim( str_replace( "\r", '', (string) ( $b['text'] ?? '' ) ) );
			$items = array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) ( $b['items'] ?? array() ) ) ) ) );
			switch ( $type ) {
				case 'heading':
					if ( '' !== $text ) {
						$out[] = '## ' . self::one_line( $text );
					}
					break;
				case 'subheading':
					if ( '' !== $text ) {
						$out[] = '### ' . self::one_line( $text );
					}
					break;
				case 'list':
				case 'ordered_list':
					if ( ! $items && '' !== $text ) {
						$items = array_filter( array_map( 'trim', explode( "\n", $text ) ) );
					}
					$lines = array();
					foreach ( array_values( $items ) as $i => $item ) {
						$item    = preg_replace( '/^(?:[-*•]|\d+[.)])\s+/u', '', self::one_line( $item ) );
						$lines[] = ( 'ordered_list' === $type ? ( $i + 1 ) . '. ' : '- ' ) . $item;
					}
					if ( $lines ) {
						if ( '' !== $text && $items ) {
							$out[] = self::one_line( $text );
						}
						$out[] = implode( "\n", $lines );
					}
					break;
				case 'quote':
					if ( '' !== $text ) {
						$out[] = '> ' . self::one_line( $text );
					}
					break;
				case 'callout':
					if ( '' !== $text ) {
						$text  = preg_match( '/^(tip|note|important|warning|key takeaway|remember)\s*:/i', $text ) ? $text : 'Tip: ' . $text;
						$out[] = '> ' . self::one_line( $text );
					}
					break;
				case 'code':
					if ( '' !== $text ) {
						$out[] = "```\n" . str_replace( '```', "'''", $text ) . "\n```";
					}
					break;
				default:
					if ( '' !== $text ) {
						foreach ( preg_split( '/\n{2,}/', $text ) as $p ) {
							$out[] = self::one_line( $p );
						}
					}
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Post content (blocks or HTML) → Markdown-lite (for prompts and the rewriter diff).
	 *
	 * @param string $html Content.
	 * @return string
	 */
	public static function from_html( $html ) {
		$html = (string) preg_replace( '/<!--.*?-->/s', '', (string) $html );
		$html = (string) preg_replace( '/<(script|style)[^>]*>.*?<\/\1>/is', '', $html );
		$html = (string) preg_replace_callback(
			'/<pre[^>]*>(.*?)<\/pre>/is',
			static function ( $m ) {
				$code = html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES );
				return "\n\n```\n" . trim( $code ) . "\n```\n\n";
			},
			$html
		);
		$html = (string) preg_replace_callback(
			'/<h([1-6])[^>]*>(.*?)<\/h\1>/is',
			static function ( $m ) {
				$level = max( 2, min( 4, (int) $m[1] ) );
				return "\n\n" . str_repeat( '#', $level ) . ' ' . self::inline_back( $m[2] ) . "\n\n";
			},
			$html
		);
		$html = (string) preg_replace_callback(
			'/<(ol|ul)[^>]*>(.*?)<\/\1>/is',
			static function ( $m ) {
				preg_match_all( '/<li[^>]*>(.*?)<\/li>/is', $m[2], $items );
				$lines = array();
				foreach ( $items[1] as $i => $item ) {
					$lines[] = ( 'ol' === strtolower( $m[1] ) ? ( $i + 1 ) . '. ' : '- ' ) . self::inline_back( $item );
				}
				return "\n\n" . implode( "\n", $lines ) . "\n\n";
			},
			$html
		);
		$html = (string) preg_replace_callback(
			'/<blockquote[^>]*>(.*?)<\/blockquote>/is',
			static function ( $m ) {
				return "\n\n> " . self::inline_back( $m[1] ) . "\n\n";
			},
			$html
		);
		$html = (string) preg_replace( '/<hr[^>]*>/i', "\n\n---\n\n", $html );
		$html = (string) preg_replace_callback(
			'/<p[^>]*class="[^"]*dev3lm-callout[^"]*"[^>]*>(.*?)<\/p>/is',
			static function ( $m ) {
				return "\n\n> " . self::inline_back( $m[1] ) . "\n\n";
			},
			$html
		);
		$html = (string) preg_replace_callback(
			'/<(p|div|figcaption|td|th)[^>]*>(.*?)<\/\1>/is',
			static function ( $m ) {
				$text = self::inline_back( $m[2] );
				return '' === $text ? '' : "\n\n" . $text . "\n\n";
			},
			$html
		);
		$html = (string) preg_replace( '/<br\s*\/?>/i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html, false ), ENT_QUOTES );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	/**
	 * Inline HTML → Markdown-lite inline.
	 *
	 * @param string $html Inline HTML.
	 * @return string
	 */
	private static function inline_back( $html ) {
		$html = (string) preg_replace( '/<(strong|b)>(.*?)<\/\1>/is', '**$2**', (string) $html );
		$html = (string) preg_replace( '/<(em|i)>(.*?)<\/\1>/is', '*$2*', $html );
		$html = (string) preg_replace( '/<code>(.*?)<\/code>/is', '`$1`', $html );
		$html = (string) preg_replace( '/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '[$2]($1)', $html );
		$html = (string) preg_replace( '/<br\s*\/?>/i', ' ', $html );
		return self::one_line( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES ) );
	}

	/**
	 * Collapses whitespace.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function one_line( $text ) {
		return trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Plain text for prompts/word counts.
	 *
	 * @param string $md Markdown.
	 * @return string
	 */
	public static function to_text( $md ) {
		$text = (string) preg_replace( '/^(#{1,4}|>|[-*•]|\d+[.)])\s*/mu', '', (string) $md );
		$text = str_replace( array( '**', '```', '`' ), '', $text );
		return trim( $text );
	}
}
