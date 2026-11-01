<?php
/**
 * Adapter registry and detection.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Lms;

use Dev3\AiCourseBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registry.
 */
class Adapters {

	/**
	 * Cached instances.
	 *
	 * @var Adapter[]|null
	 */
	private static $all = null;

	/**
	 * All adapters keyed by id (filter dev3lm_adapters adds custom ones).
	 *
	 * @return Adapter[]
	 */
	public static function all() {
		if ( null === self::$all ) {
			$list = array(
				'tutor'     => new Tutor(),
				'learndash' => new LearnDash(),
				'pages'     => new Pages(),
			);
			/**
			 * Registered LMS adapters (id => Adapter instance).
			 *
			 * @param Adapter[] $list Adapters.
			 */
			$list      = (array) apply_filters( 'dev3lm_adapters', $list );
			self::$all = array_filter(
				$list,
				static function ( $a ) {
					return $a instanceof Adapter;
				}
			);
		}
		return self::$all;
	}

	/**
	 * One adapter (null when unknown).
	 *
	 * @param string $id Id.
	 * @return Adapter|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Resolves "auto"/a requested id to an available adapter.
	 *
	 * @param string $id Requested id ('' = setting).
	 * @return Adapter|\WP_Error
	 */
	public static function resolve( $id = '' ) {
		$id = '' === (string) $id ? (string) Settings::get( 'default_lms', 'auto' ) : (string) $id;
		if ( 'auto' === $id ) {
			foreach ( array( 'tutor', 'learndash' ) as $candidate ) {
				$a = self::get( $candidate );
				if ( $a && $a->available() ) {
					return $a;
				}
			}
			return self::get( 'pages' );
		}
		$a = self::get( $id );
		if ( ! $a ) {
			return new \WP_Error( 'dev3lm_lms', __( 'Unknown LMS target.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		if ( ! $a->available() ) {
			/* translators: %s: LMS name */
			return new \WP_Error( 'dev3lm_lms_inactive', sprintf( __( '%s is not active on this site. Activate it or choose another target.', 'dev3-ai-course-builder' ), $a->label() ), array( 'status' => 400 ) );
		}
		return $a;
	}

	/**
	 * Adapter that owns a post type (for row actions/tools).
	 *
	 * @param string $post_type Post type.
	 * @return Adapter|null
	 */
	public static function for_post_type( $post_type ) {
		foreach ( self::all() as $a ) {
			if ( 'pages' !== $a->id() && $a->available() && in_array( $post_type, $a->lesson_types(), true ) ) {
				return $a;
			}
		}
		$pages = self::get( 'pages' );
		return $pages && in_array( $post_type, $pages->lesson_types(), true ) ? $pages : null;
	}

	/**
	 * Describes all adapters.
	 *
	 * @return array
	 */
	public static function describe() {
		$out = array();
		foreach ( self::all() as $a ) {
			$out[] = $a->describe();
		}
		return $out;
	}

	/**
	 * Clears the cache (tests).
	 */
	public static function reset() {
		self::$all = null;
	}
}
