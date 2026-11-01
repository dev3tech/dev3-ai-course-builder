<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder;

use Dev3\AiCourseBuilder\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3lm_settings_defaults / dev3lm_settings_sanitize.
 */
class Settings {

	const LMS            = array( 'auto', 'tutor', 'learndash', 'pages' );
	const LEVELS         = array( 'all_levels', 'beginner', 'intermediate', 'expert' );
	const TONES          = array( 'friendly', 'professional', 'academic', 'conversational', 'motivational' );
	const LENGTHS        = array( 'short', 'medium', 'long' );
	const QUIZ_PLACES    = array( 'none', 'section', 'lesson', 'course' );
	const QUESTION_TYPES = array( 'single', 'multiple', 'true_false', 'fill_blank', 'short_answer', 'essay', 'ordering', 'matching' );

	/**
	 * Adds plugin defaults. No __() here: defaults can be read before init.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'default_lms'         => 'auto',
				'post_status'         => 'draft',
				'language'            => 'English',
				'tone'                => 'friendly',
				'audience'            => '',
				'level'               => 'beginner',
				'sections'            => 4,
				'lessons_per_section' => 3,
				'topics_per_lesson'   => 0,
				'quiz_placement'      => 'section',
				'questions_per_quiz'  => 5,
				'question_types'      => array( 'single', 'multiple', 'true_false' ),
				'lesson_length'       => 'medium',
				'passing_grade'       => 70,
				'certificate'         => true,
				'max_source_chars'    => 30000,
				'outline_model'       => 'pro',
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		$enums = array(
			'default_lms'    => self::LMS,
			'post_status'    => array( 'draft', 'publish', 'pending', 'private' ),
			'tone'           => self::TONES,
			'level'          => self::LEVELS,
			'lesson_length'  => self::LENGTHS,
			'quiz_placement' => self::QUIZ_PLACES,
			'outline_model'  => array( 'default', 'pro' ),
		);
		foreach ( $enums as $key => $allowed ) {
			if ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) {
				$out[ $key ] = $input[ $key ];
			}
		}
		$ints = array(
			'sections'            => array( 1, 30 ),
			'lessons_per_section' => array( 1, 20 ),
			'topics_per_lesson'   => array( 0, 10 ),
			'questions_per_quiz'  => array( 1, 30 ),
			'passing_grade'       => array( 0, 100 ),
			'max_source_chars'    => array( 2000, 120000 ),
		);
		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['language'] ) ) {
			$lang            = mb_substr( sanitize_text_field( wp_unslash( (string) $input['language'] ) ), 0, 40 );
			$out['language'] = '' === $lang ? 'English' : $lang;
		}
		if ( isset( $input['audience'] ) ) {
			$out['audience'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['audience'] ) ), 0, 200 );
		}
		if ( isset( $input['certificate'] ) ) {
			$out['certificate'] = (bool) $input['certificate'];
		}
		if ( isset( $input['question_types'] ) && is_array( $input['question_types'] ) ) {
			$types                 = array_values( array_intersect( self::QUESTION_TYPES, array_map( 'strval', $input['question_types'] ) ) );
			$out['question_types'] = $types ? $types : array( 'single' );
		}
		return $out;
	}

	/**
	 * Reads one setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}

	/**
	 * Generation options merged with per-request overrides (sanitised).
	 *
	 * @param array $input Overrides.
	 * @return array
	 */
	public static function options( array $input = array() ) {
		$base = array();
		foreach ( array( 'language', 'tone', 'audience', 'level', 'sections', 'lessons_per_section', 'topics_per_lesson', 'quiz_placement', 'questions_per_quiz', 'question_types', 'lesson_length', 'passing_grade', 'certificate' ) as $key ) {
			$base[ $key ] = self::get( $key );
		}
		$clean = self::sanitize( array(), $input, array() );
		$opts  = array_merge( $base, array_intersect_key( $clean, $base ) );
		if ( isset( $input['goals'] ) ) {
			$opts['goals'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['goals'] ) ), 0, 1000 );
		}
		return $opts;
	}
}
