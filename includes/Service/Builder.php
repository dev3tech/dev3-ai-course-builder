<?php
/**
 * Creates a reviewed blueprint in the chosen LMS as a background job, and undoes a build.
 *
 * Job items are steps in creation order: "course", "s:<id>", "l:<id>", "t:<id>", "q:<id>", "cert",
 * "final". Each step records node → post id in the blueprint result, so retries never duplicate.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Service;

use Dev3\AiCourseBuilder\Core3\Core\Jobs;
use Dev3\AiCourseBuilder\Course\Tree;
use Dev3\AiCourseBuilder\Data\Blueprints;
use Dev3\AiCourseBuilder\Lms\Adapter;
use Dev3\AiCourseBuilder\Lms\Adapters;
use Dev3\AiCourseBuilder\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builder.
 */
class Builder {

	const JOB = 'dev3lm_build';

	/**
	 * Registers the job (every request).
	 */
	public static function register() {
		Jobs::register( self::JOB, array( self::class, 'run_chunk' ) );
		add_action( 'dev3lm_job_finished_' . self::JOB, array( self::class, 'finished' ) );
	}

	/**
	 * Ordered steps for a tree.
	 *
	 * @param array   $tree    Tree.
	 * @param Adapter $adapter Adapter.
	 * @param bool    $cert    Include the certificate step.
	 * @return string[]
	 */
	public static function steps( array $tree, Adapter $adapter, $cert ) {
		unset( $adapter );
		$steps = array( 'course' );
		foreach ( $tree['sections'] as $s ) {
			$steps[] = 's:' . $s['id'];
			foreach ( $s['lessons'] as $l ) {
				$steps[] = 'l:' . $l['id'];
				foreach ( $l['topics'] as $t ) {
					$steps[] = 't:' . $t['id'];
				}
				if ( $l['quiz'] && $l['quiz']['questions'] ) {
					$steps[] = 'q:' . $l['quiz']['id'];
				}
			}
			if ( $s['quiz'] && $s['quiz']['questions'] ) {
				$steps[] = 'q:' . $s['quiz']['id'];
			}
		}
		if ( $tree['quiz'] && $tree['quiz']['questions'] ) {
			$steps[] = 'q:' . $tree['quiz']['id'];
		}
		if ( $cert && ( '' !== $tree['course']['certificate']['body'] || '' !== $tree['course']['certificate']['title'] ) ) {
			$steps[] = 'cert';
		}
		$steps[] = 'final';
		return $steps;
	}

	/**
	 * Order of every node inside its section (Tutor/LearnDash menu_order) and across the course.
	 *
	 * @param array $tree Tree.
	 * @return array node id → [ section order, global order ]
	 */
	public static function orders( array $tree ) {
		$out    = array();
		$global = 0;
		foreach ( $tree['sections'] as $si => $s ) {
			$out[ $s['id'] ] = array( $si, $global );
			$n               = 0;
			foreach ( $s['lessons'] as $l ) {
				$out[ $l['id'] ] = array( $n++, ++$global );
				foreach ( $l['topics'] as $ti => $t ) {
					$out[ $t['id'] ] = array( $n++, ++$global );
				}
				if ( $l['quiz'] ) {
					$out[ $l['quiz']['id'] ] = array( $n++, ++$global );
				}
			}
			if ( $s['quiz'] ) {
				$out[ $s['quiz']['id'] ] = array( $n++, ++$global );
			}
		}
		if ( $tree['quiz'] ) {
			$out[ $tree['quiz']['id'] ] = array( count( $tree['sections'] ), ++$global );
		}
		return $out;
	}

	/**
	 * Starts a build job.
	 *
	 * @param int    $id     Blueprint id.
	 * @param string $lms    Target ('' = setting).
	 * @param string $status Post status ('' = setting).
	 * @return array|\WP_Error Job.
	 */
	public static function start( $id, $lms = '', $status = '' ) {
		$bp = Blueprints::get( $id );
		if ( ! $bp ) {
			return new \WP_Error( 'dev3lm_not_found', __( 'Course draft not found.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) );
		}
		if ( 'building' === $bp['status'] && $bp['job_id'] ) {
			$job = Jobs::get( $bp['job_id'] );
			if ( $job && in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
				return $job;
			}
		}
		if ( 'built' === $bp['status'] && ! empty( $bp['result']['course_id'] ) && get_post( (int) $bp['result']['course_id'] ) ) {
			return new \WP_Error( 'dev3lm_built', __( 'This course was already created. Undo the build first to create it again.', 'dev3-ai-course-builder' ), array( 'status' => 409 ) );
		}
		$adapter = Adapters::resolve( $lms );
		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}
		$errors = array_filter(
			Tree::issues( $bp['tree'] ),
			static function ( $i ) {
				return 'error' === $i['level'];
			}
		);
		if ( $errors ) {
			$first = reset( $errors );
			return new \WP_Error( 'dev3lm_invalid', $first['message'], array( 'status' => 400 ) );
		}
		$status = in_array( $status, array( 'draft', 'publish', 'pending', 'private' ), true ) ? $status : (string) Settings::get( 'post_status', 'draft' );
		$steps  = self::steps( $bp['tree'], $adapter, (bool) Settings::get( 'certificate', true ) );
		$job_id = Jobs::create(
			self::JOB,
			$steps,
			array(
				'blueprint' => (int) $id,
				'lms'       => $adapter->id(),
				'status'    => $status,
			),
			3
		);
		if ( is_wp_error( $job_id ) ) {
			return $job_id;
		}
		Blueprints::update(
			$id,
			array(
				'status' => 'building',
				'lms'    => $adapter->id(),
				'job_id' => $job_id,
				'result' => array(
					'map'     => array(),
					'lms'     => $adapter->id(),
					'status'  => $status,
					'started' => time(),
				),
			)
		);
		return Jobs::get( $job_id );
	}

	/**
	 * Job handler: creates one chunk of steps.
	 *
	 * @param array $items Steps.
	 * @param array $job   Job.
	 * @return array step → true|WP_Error
	 */
	public static function run_chunk( array $items, array $job ) {
		$args    = (array) $job['args'];
		$id      = (int) ( $args['blueprint'] ?? 0 );
		$bp      = Blueprints::get( $id );
		$adapter = Adapters::get( (string) ( $args['lms'] ?? '' ) );
		if ( ! $bp || ! $adapter ) {
			return new \WP_Error( 'dev3lm_not_found', __( 'Course draft or LMS adapter missing.', 'dev3-ai-course-builder' ) );
		}
		if ( ! empty( $job['user_id'] ) && ! get_current_user_id() ) {
			wp_set_current_user( (int) $job['user_id'] ); // Cron runs: create posts as the admin who started the build.
		}
		$tree   = $bp['tree'];
		$index  = Tree::index( $tree );
		$orders = self::orders( $tree );
		$out    = array();
		foreach ( $items as $step ) {
			$bp           = Blueprints::get( $id );
			$map          = (array) ( $bp['result']['map'] ?? array() );
			$out[ $step ] = self::step( $step, $adapter, $tree, $index, $orders, $map, $bp, (string) ( $args['status'] ?? 'draft' ) );
		}
		return $out;
	}

	/**
	 * Runs one step.
	 *
	 * @param string  $step    Step.
	 * @param Adapter $adapter Adapter.
	 * @param array   $tree    Tree.
	 * @param array   $index   Index.
	 * @param array   $orders  Orders.
	 * @param array   $map     Current map.
	 * @param array   $bp      Blueprint.
	 * @param string  $status  Post status.
	 * @return true|\WP_Error
	 */
	private static function step( $step, Adapter $adapter, array $tree, array $index, array $orders, array $map, array $bp, $status ) {
		$course_post = (int) ( $map['__course'] ?? 0 );
		$ctx         = array(
			'blueprint'   => (int) $bp['id'],
			'status'      => $status,
			'course_post' => $course_post,
			'tree'        => $tree,
		);
		if ( 'course' === $step ) {
			if ( $course_post && get_post( $course_post ) ) {
				return true;
			}
			$ctx['node'] = 'course';
			$post        = $adapter->create_course( $tree['course'], $ctx );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			Adapter::store_certificate( $post, $tree['course'] );
			Blueprints::merge_result(
				$bp['id'],
				array(
					'map'       => array( '__course' => $post ),
					'course_id' => $post,
				)
			);
			return true;
		}
		if ( ! $course_post ) {
			return new \WP_Error( 'dev3lm_no_course', __( 'Skipped: the course itself was not created.', 'dev3-ai-course-builder' ) );
		}
		if ( 'cert' === $step ) {
			if ( array_key_exists( '__certificate', $map ) ) {
				return true;
			}
			$ctx['node'] = 'certificate';
			$post        = $adapter->create_certificate( $tree['course'], $ctx );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			Blueprints::merge_result( $bp['id'], array( 'map' => array( '__certificate' => (int) $post ) ) );
			return true;
		}
		if ( 'final' === $step ) {
			$result = $adapter->finalize( $course_post, $map, $tree, $ctx );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			Blueprints::merge_result( $bp['id'], array( 'links' => $adapter->links( $course_post ) ) );
			return true;
		}
		$node_id = substr( $step, 2 );
		if ( ! isset( $index[ $node_id ] ) ) {
			return new \WP_Error( 'dev3lm_node', __( 'This item was removed from the course draft.', 'dev3-ai-course-builder' ) );
		}
		if ( array_key_exists( $node_id, $map ) ) {
			return true;
		}
		$entry   = $index[ $node_id ];
		$section = $entry['section'];
		$lesson  = $entry['lesson'];
		$last    = 0;
		if ( $section && 'quiz' === $entry['kind'] && ! $lesson ) {
			$lessons = $index[ $section ]['node']['lessons'];
			if ( $lessons ) {
				$last_lesson = end( $lessons );
				$last        = (int) ( $map[ $last_lesson['id'] ] ?? 0 );
			}
		}
		$ctx = array_merge(
			$ctx,
			array(
				'node'             => $node_id,
				'order'            => (int) ( $orders[ $node_id ][0] ?? 0 ),
				'global_order'     => (int) ( $orders[ $node_id ][1] ?? 0 ),
				'section_post'     => $section ? (int) ( $map[ $section ] ?? 0 ) : 0,
				'lesson_post'      => $lesson ? (int) ( $map[ $lesson ] ?? 0 ) : 0,
				'last_lesson_post' => $last,
				'section_quiz'     => $section && ! $lesson && 'quiz' === $entry['kind'],
			)
		);
		if ( $section && 'section' !== $entry['kind'] && ! array_key_exists( $section, $map ) ) {
			return new \WP_Error( 'dev3lm_parent', __( 'Skipped: its section was not created.', 'dev3-ai-course-builder' ) );
		}
		if ( $lesson && empty( $map[ $lesson ] ) ) {
			return new \WP_Error( 'dev3lm_parent', __( 'Skipped: its lesson was not created.', 'dev3-ai-course-builder' ) );
		}
		switch ( $entry['kind'] ) {
			case 'section':
				$post = $adapter->create_section( $entry['node'], $ctx );
				break;
			case 'lesson':
				$post = $adapter->create_lesson( $entry['node'], $ctx );
				break;
			case 'topic':
				$post = $adapter->create_topic( $entry['node'], $ctx );
				break;
			default:
				$post = $adapter->create_quiz( $entry['node'], $ctx );
		}
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		Blueprints::merge_result( $bp['id'], array( 'map' => array( $node_id => (int) $post ) ) );
		return true;
	}

	/**
	 * Job finished: blueprint becomes built (course exists) or failed.
	 *
	 * @param array $job Job.
	 */
	public static function finished( $job ) {
		$id = (int) ( $job['args']['blueprint'] ?? 0 );
		$bp = $id ? Blueprints::get( $id ) : null;
		if ( ! $bp ) {
			return;
		}
		$ok = ! empty( $bp['result']['course_id'] ) && 'done' === $job['status'];
		Blueprints::merge_result(
			$id,
			array(
				'finished' => time(),
				'failed'   => (int) $job['failed'],
				'errors'   => array_slice( (array) $job['errors'], 0, 20 ),
			)
		);
		Blueprints::update( $id, array( 'status' => $ok ? 'built' : 'failed' ) );
	}

	/**
	 * Posts created by a blueprint (any status, newest first).
	 *
	 * @param int $id Blueprint.
	 * @return int[]
	 */
	public static function created_posts( $id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'DESC',
					'meta_key'       => Adapter::META_BLUEPRINT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => (int) $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'no_found_rows'  => true,
				)
			)
		);
	}

	/**
	 * Undo: trashes (or permanently deletes) everything a build created.
	 *
	 * @param int  $id    Blueprint.
	 * @param bool $force Delete permanently.
	 * @return array|\WP_Error { removed }
	 */
	public static function unbuild( $id, $force = false ) {
		$bp = Blueprints::get( $id );
		if ( ! $bp ) {
			return new \WP_Error( 'dev3lm_not_found', __( 'Course draft not found.', 'dev3-ai-course-builder' ), array( 'status' => 404 ) );
		}
		if ( 'building' === $bp['status'] && $bp['job_id'] ) {
			Jobs::cancel( $bp['job_id'] );
		}
		$posts   = self::created_posts( $id );
		$adapter = Adapters::get( (string) ( $bp['result']['lms'] ?? $bp['lms'] ) );
		if ( $force && $adapter ) {
			$adapter->cleanup( $posts );
		}
		$removed = 0;
		foreach ( $posts as $post_id ) {
			if ( $force ) {
				$removed += wp_delete_post( $post_id, true ) ? 1 : 0;
			} elseif ( 'trash' !== get_post_status( $post_id ) ) {
				$removed += wp_trash_post( $post_id ) ? 1 : 0;
			}
		}
		Blueprints::update(
			$id,
			array(
				'status' => 'draft',
				'job_id' => 0,
				'result' => array(
					'undone'  => time(),
					'removed' => $removed,
				),
			)
		);
		return array( 'removed' => $removed );
	}
}
