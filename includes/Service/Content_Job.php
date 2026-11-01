<?php
/**
 * "Draft all lessons with DEV3 AI": a background job that writes content for every empty lesson and
 * topic of a blueprint. It only fills the course tree; nothing is created in the LMS.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Service;

use Dev3\AiCourseBuilder\Ai\Generator;
use Dev3\AiCourseBuilder\Core3\Api\Ai;
use Dev3\AiCourseBuilder\Core3\Core\Jobs;
use Dev3\AiCourseBuilder\Course\Tree;
use Dev3\AiCourseBuilder\Data\Blueprints;
use Dev3\AiCourseBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Content job.
 */
class Content_Job {

	const JOB = 'dev3lm_content';

	/**
	 * Registers the job.
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_chunk' ) );
	}

	/**
	 * Starts the job for empty lessons/topics (or the given node ids).
	 *
	 * @param int   $id    Blueprint.
	 * @param array $nodes Node ids ([] = every empty lesson/topic).
	 * @return array|\WP_Error Job.
	 */
	public static function start( $id, array $nodes = array() ) {
		$bp = Blueprints::get( $id );
		if ( ! $bp ) {
			return new \WP_Error( 'dev3lm_not_found', __( 'Course draft not found.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) );
		}
		if ( ! Ai::enabled( 'admin' ) ) {
			return new \WP_Error( 'dev3lm_ai_off', __( 'DEV3 AI is off or no API key is set. Write the lessons yourself, or connect DEV3 AI in API Config.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		foreach ( Jobs::recent( self::JOB, 10 ) as $job ) {
			if ( (int) ( $job['args']['blueprint'] ?? 0 ) === (int) $id && in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				return $job;
			}
		}
		$items = array();
		foreach ( Tree::index( $bp['tree'] ) as $node_id => $entry ) {
			if ( ! in_array( $entry['kind'], array( 'lesson', 'topic' ), true ) ) {
				continue;
			}
			if ( $nodes ? in_array( $node_id, $nodes, true ) : '' === trim( (string) $entry['node']['content'] ) ) {
				$items[] = $node_id;
			}
		}
		if ( ! $items ) {
			return new \WP_Error( 'dev3lm_nothing', __( 'Every lesson already has content.', 'dev3-ai-course-builder' ), array( 'status' => 400 ) );
		}
		$job = Jobs::create( self::JOB, $items, array( 'blueprint' => (int) $id ), 1 );
		return is_wp_error( $job ) ? $job : Jobs::get( $job );
	}

	/**
	 * Writes one lesson per chunk into the tree.
	 *
	 * @param array $items Node ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		$id   = (int) ( $job['args']['blueprint'] ?? 0 );
		$opts = Settings::options();
		$out  = array();
		foreach ( $items as $node ) {
			$bp = Blueprints::get( $id );
			if ( ! $bp ) {
				$out[ $node ] = new \WP_Error( 'dev3lm_not_found', __( 'Course draft not found.', 'dev3-ai-course-builder' ) );
				continue;
			}
			$draft = Generator::lesson( $bp['tree'], $node, (string) ( $bp['source']['text'] ?? '' ), $opts );
			if ( is_wp_error( $draft ) ) {
				$out[ $node ] = $draft;
				continue;
			}
			// Re-read right before saving so edits made meanwhile in the editor are kept.
			$bp   = Blueprints::get( $id );
			$tree = Tree::update_node(
				$bp['tree'],
				$node,
				static function ( $n ) use ( $draft ) {
					if ( '' === trim( (string) $n['content'] ) ) {
						$n['content']  = $draft['content'];
						$n['ai_draft'] = true;
					}
					return $n;
				}
			);
			Blueprints::update( $id, array( 'tree' => $tree ) );
			$out[ $node ] = true;
		}
		return $out;
	}
}
