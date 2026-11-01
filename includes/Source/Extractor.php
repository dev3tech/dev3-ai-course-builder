<?php
/**
 * Text extraction from uploaded course material (PDF, DOCX, TXT, Markdown) for the non-AI path and
 * for prompts. DOCX headings become Markdown headings so the outline parser can read the structure.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Extractor.
 */
class Extractor {

	const MAX_BYTES = 15728640; // 15 MB (Gemini inline request limit is 20 MB).

	const MIMES = array(
		'application/pdf' => 'pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'text/plain'      => 'txt',
		'text/markdown'   => 'txt',
		'text/x-markdown' => 'txt',
	);

	/**
	 * Validates an attachment and returns { path, mime, kind, size, name }.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array|\WP_Error
	 */
	public static function attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'dev3lm_file_missing', __( 'Upload a PDF, DOCX, TXT or Markdown file first.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'dev3lm_forbidden', __( 'You cannot use that file.', 'dev3-ai-course-builder' ), array( 'status' => 403 ) );
		}
		$path = (string) get_attached_file( $attachment_id );
		$name = basename( $path );
		$mime = (string) get_post_mime_type( $attachment_id );
		$kind = self::kind( $mime, $name );
		if ( ! $kind ) {
			return new \WP_Error( 'dev3lm_file_type', __( 'Use a PDF, DOCX, TXT or Markdown (.md) file.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		if ( ! $path || ! is_readable( $path ) ) {
			return new \WP_Error( 'dev3lm_file_missing', __( 'The uploaded file could not be read.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		$size = (int) filesize( $path );
		if ( $size > self::MAX_BYTES ) {
			return new \WP_Error( 'dev3lm_file_size', __( 'The file is larger than 15 MB. Split it or use a lighter PDF.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		return array(
			'path' => $path,
			'mime' => $mime,
			'kind' => $kind,
			'size' => $size,
			'name' => $name,
		);
	}

	/**
	 * File kind from mime type / extension.
	 *
	 * @param string $mime Mime.
	 * @param string $name File name.
	 * @return string pdf|docx|txt|''
	 */
	public static function kind( $mime, $name ) {
		if ( isset( self::MIMES[ $mime ] ) ) {
			return self::MIMES[ $mime ];
		}
		if ( '' !== (string) $mime && ! in_array( $mime, array( 'application/octet-stream', 'application/zip', 'application/x-zip-compressed' ), true ) ) {
			return '';
		}
		$ext = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );
		$map = array(
			'pdf'      => 'pdf',
			'docx'     => 'docx',
			'txt'      => 'txt',
			'md'       => 'txt',
			'markdown' => 'txt',
		);
		return isset( $map[ $ext ] ) ? $map[ $ext ] : '';
	}

	/**
	 * Extracts Markdown-lite text from a file.
	 *
	 * @param string $path File path.
	 * @param string $kind pdf|docx|txt.
	 * @return string|\WP_Error
	 */
	public static function text( $path, $kind ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			return new \WP_Error( 'dev3lm_file_missing', __( 'The uploaded file could not be read.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		if ( 'docx' === $kind ) {
			$text = self::docx( $path );
		} elseif ( 'pdf' === $kind ) {
			$text = self::pdf( $raw );
		} else {
			$text = wp_check_invalid_utf8( str_replace( "\r", '', $raw ), true );
		}
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", (string) $text ) );
	}

	/**
	 * DOCX → Markdown-lite (Title/Heading 1-3 → headings, list paragraphs → bullets).
	 *
	 * @param string $path File.
	 * @return string|\WP_Error
	 */
	public static function docx( $path ) {
		$xml = '';
		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $path ) ) {
				$xml = (string) $zip->getFromName( 'word/document.xml' );
				$zip->close();
			}
		}
		if ( '' === $xml ) {
			return new \WP_Error( 'dev3lm_docx', __( 'This DOCX file could not be opened. Save it again from Word or Google Docs, or paste the outline instead.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		return self::docx_xml( $xml );
	}

	/**
	 * Converts word/document.xml to Markdown-lite.
	 *
	 * @param string $xml Document XML.
	 * @return string
	 */
	public static function docx_xml( $xml ) {
		$out = array();
		preg_match_all( '/<w:p[ >].*?<\/w:p>/s', $xml, $paras );
		foreach ( $paras[0] as $p ) {
			$style = '';
			if ( preg_match( '/<w:pStyle w:val="([^"]+)"/', $p, $m ) ) {
				$style = strtolower( $m[1] );
			}
			$p    = (string) preg_replace( '/<w:tab\/>/', ' ', $p );
			$p    = (string) preg_replace( '/<w:br\/>/', ' ', $p );
			$text = '';
			preg_match_all( '/<w:t(?:\s[^>]*)?>(.*?)<\/w:t>/s', $p, $runs );
			foreach ( $runs[1] as $run ) {
				$text .= $run;
			}
			$text = trim( html_entity_decode( $text, ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
			if ( '' === $text ) {
				continue;
			}
			if ( 'title' === $style || preg_match( '/^heading1$|^berschrift1$|^titre1$/', $style ) ) {
				$out[] = '# ' . $text;
			} elseif ( preg_match( '/heading([2-4])$|berschrift([2-4])$|titre([2-4])$/', $style, $h ) ) {
				$level = (int) max( $h[1] ?? 0, $h[2] ?? 0, $h[3] ?? 0 );
				$out[] = str_repeat( '#', $level ) . ' ' . $text;
			} elseif ( false !== strpos( $p, '<w:numPr>' ) || false !== strpos( $style, 'list' ) ) {
				$depth = 0;
				if ( preg_match( '/<w:ilvl w:val="(\d+)"/', $p, $lvl ) ) {
					$depth = (int) $lvl[1];
				}
				$out[] = str_repeat( '  ', $depth ) . '- ' . $text;
			} else {
				$out[] = $text;
			}
		}
		// Keep consecutive bullets together, separate everything else with blank lines.
		$md = '';
		foreach ( $out as $i => $line ) {
			$is_item   = (bool) preg_match( '/^\s*- /', $line );
			$prev_item = $i > 0 && preg_match( '/^\s*- /', $out[ $i - 1 ] );
			$md       .= ( 0 === $i ? '' : ( $is_item && $prev_item ? "\n" : "\n\n" ) ) . $line;
		}
		return $md;
	}

	/**
	 * Minimal PDF text extraction (uncompressed and Flate streams, Tj/TJ/'/" operators). Scanned PDFs
	 * have no text layer: the AI path sends the PDF itself instead.
	 *
	 * @param string $raw PDF bytes.
	 * @return string
	 */
	public static function pdf( $raw ) {
		$lines = array();
		if ( ! preg_match_all( '/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', (string) $raw, $streams, PREG_SET_ORDER ) ) {
			return '';
		}
		foreach ( $streams as $s ) {
			$dict = $s[1];
			$data = $s[2];
			if ( preg_match( '/\/Subtype\s*\/Image|\/Type\s*\/XObject|\/FontFile|\/Length1/', $dict ) ) {
				continue;
			}
			if ( false !== strpos( $dict, 'FlateDecode' ) ) {
				$decoded = function_exists( 'gzuncompress' ) ? @gzuncompress( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $decoded && function_exists( 'gzinflate' ) ) {
					$decoded = @gzinflate( substr( $data, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				if ( false === $decoded ) {
					continue;
				}
				$data = $decoded;
			} elseif ( preg_match( '/\/Filter/', $dict ) ) {
				continue;
			}
			if ( false === strpos( $data, 'BT' ) ) {
				continue;
			}
			$lines = array_merge( $lines, self::pdf_text_ops( $data ) );
		}
		$text = implode( "\n", $lines );
		$text = (string) preg_replace( '/[ \t]{2,}/', ' ', $text );
		return trim( wp_check_invalid_utf8( $text, true ) );
	}

	/**
	 * Reads text operators from a content stream.
	 *
	 * @param string $data Stream.
	 * @return array Lines.
	 */
	public static function pdf_text_ops( $data ) {
		$lines = array();
		$line  = '';
		preg_match_all( '/BT(.*?)ET/s', $data, $blocks );
		foreach ( $blocks[1] as $block ) {
			preg_match_all( '/(\[(?:[^\]\\\\]|\\\\.)*\]\s*TJ|\((?:[^)\\\\]|\\\\.)*\)\s*(?:Tj|\'|")|-?[\d.]+\s+-?[\d.]+\s+T[dD]|T\*)/s', $block, $ops );
			foreach ( $ops[0] as $op ) {
				if ( 'T*' === $op || preg_match( '/T[dD]$/', $op ) ) {
					if ( preg_match( '/^(-?[\d.]+)\s+(-?[\d.]+)\s+T[dD]$/', $op, $m ) && 0.0 === (float) $m[2] ) {
						continue; // Horizontal move on the same line.
					}
					if ( '' !== trim( $line ) ) {
						$lines[] = trim( $line );
					}
					$line = '';
					continue;
				}
				if ( '[' === $op[0] ) {
					preg_match_all( '/\((?:[^)\\\\]|\\\\.)*\)|-?\d+(?:\.\d+)?/', substr( $op, 1, strrpos( $op, ']' ) - 1 ), $parts );
					foreach ( $parts[0] as $part ) {
						if ( '(' === $part[0] ) {
							$line .= self::pdf_string( substr( $part, 1, -1 ) );
						} elseif ( (float) $part < -200 ) {
							$line .= ' ';
						}
					}
				} else {
					$end   = strrpos( $op, ')' );
					$line .= self::pdf_string( substr( $op, 1, $end - 1 ) );
				}
			}
			if ( '' !== trim( $line ) ) {
				$lines[] = trim( $line );
			}
			$line = '';
		}
		return $lines;
	}

	/**
	 * Decodes a PDF literal string.
	 *
	 * @param string $s Raw.
	 * @return string
	 */
	private static function pdf_string( $s ) {
		$s = (string) preg_replace_callback(
			'/\\\\([0-7]{1,3})/',
			static function ( $m ) {
				return chr( octdec( $m[1] ) & 0xFF );
			},
			$s
		);
		$s = strtr(
			$s,
			array(
				'\\n'  => ' ',
				'\\r'  => ' ',
				'\\t'  => ' ',
				'\\('  => '(',
				'\\)'  => ')',
				'\\\\' => '\\',
			)
		);
		if ( ! preg_match( '//u', $s ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' );
		}
		return $s;
	}
}
