<?php
/**
 * Lesson rewrite history (before/after content) so every accepted rewrite can be undone.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites repository.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
 */
class Rewrites {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3lm_rewrites';
	}

	/**
	 * Logs an applied rewrite.
	 *
	 * @param int    $post_id Post.
	 * @param string $mode    Mode.
	 * @param string $before  Previous content.
	 * @param string $after   New content.
	 * @return int
	 */
	public static function log( $post_id, $mode, $before, $after ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'post_id'        => (int) $post_id,
				'mode'           => sanitize_key( $mode ),
				'before_content' => (string) $before,
				'after_content'  => (string) $after,
				'user_id'        => get_current_user_id(),
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * One row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Marks a row undone.
	 *
	 * @param int $id Id.
	 */
	public static function mark_undone( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'undone_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) );
	}

	/**
	 * Recent rows (optionally for one post), without content.
	 *
	 * @param int $post_id Post filter (0 = all).
	 * @param int $limit   Max.
	 * @return array
	 */
	public static function recent( $post_id = 0, $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		if ( $post_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, post_id, mode, user_id, created_at, undone_at FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT %d", (int) $post_id, (int) $limit ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, post_id, mode, user_id, created_at, undone_at FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A );
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			$latest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$table} WHERE post_id = %d AND undone_at IS NULL", (int) $row['post_id'] ) );
			$out[]  = array(
				'id'         => (int) $row['id'],
				'post_id'    => (int) $row['post_id'],
				'post_title' => get_the_title( (int) $row['post_id'] ),
				'post_type'  => (string) get_post_type( (int) $row['post_id'] ),
				'mode'       => (string) $row['mode'],
				'user'       => $row['user_id'] ? (string) get_the_author_meta( 'display_name', (int) $row['user_id'] ) : '',
				'created_at' => mysql_to_rfc3339( (string) $row['created_at'] ),
				'undone'     => ! empty( $row['undone_at'] ),
				'can_undo'   => empty( $row['undone_at'] ) && $latest === (int) $row['id'],
			);
		}
		return $out;
	}

	/**
	 * Count of applied rewrites.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE undone_at IS NULL' );
	}
}
