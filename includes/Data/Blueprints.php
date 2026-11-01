<?php
/**
 * Course blueprints: the editable course tree that exists before anything is created in the LMS.
 *
 * @package Dev3\AiCourseBuilder
 */

namespace Dev3\AiCourseBuilder\Data;

use Dev3\AiCourseBuilder\Course\Tree;

defined( 'ABSPATH' ) || exit;

/**
 * Blueprints repository.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
 */
class Blueprints {

	const STATUSES = array( 'draft', 'building', 'built', 'failed' );

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3lm_blueprints';
	}

	/**
	 * CREATE TABLE statements (dev3lm_schema_sql filter).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$table    = self::table();
		$rewrites = Rewrites::table();
		$sql[]    = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(200) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'draft',
			lms varchar(20) NOT NULL DEFAULT '',
			source_type varchar(20) NOT NULL DEFAULT 'topic',
			source longtext NOT NULL,
			tree longtext NOT NULL,
			result longtext NOT NULL,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY updated (updated_at)
		) {$charset};";
		$sql[]    = "CREATE TABLE {$rewrites} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			mode varchar(30) NOT NULL DEFAULT '',
			before_content longtext NOT NULL,
			after_content longtext NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			undone_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY post (post_id)
		) {$charset};";
		return $sql;
	}

	/**
	 * Creates a blueprint.
	 *
	 * @param array $data { title, source_type, source, tree, lms }.
	 * @return int|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;
		$tree = Tree::normalize( isset( $data['tree'] ) ? (array) $data['tree'] : array() );
		$now  = current_time( 'mysql', true );
		$ok   = $wpdb->insert(
			self::table(),
			array(
				'title'       => mb_substr( (string) ( $data['title'] ?? $tree['course']['title'] ), 0, 200 ),
				'status'      => 'draft',
				'lms'         => sanitize_key( (string) ( $data['lms'] ?? '' ) ),
				'source_type' => in_array( $data['source_type'] ?? '', array( 'topic', 'outline', 'file', 'import', 'blank' ), true ) ? $data['source_type'] : 'topic',
				'source'      => (string) wp_json_encode( isset( $data['source'] ) ? (array) $data['source'] : array() ),
				'tree'        => (string) wp_json_encode( $tree ),
				'result'      => '{}',
				'user_id'     => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'dev3lm_db', __( 'Could not save the course draft.', 'dev3-ai-course-builder' ), array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Reads one blueprint.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Updates columns. Arrays (tree, source, result) are JSON-encoded.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$cols = array();
		foreach ( array( 'title', 'status', 'lms', 'job_id' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$cols[ $key ] = $data[ $key ];
			}
		}
		if ( isset( $cols['title'] ) ) {
			$cols['title'] = mb_substr( (string) $cols['title'], 0, 200 );
		}
		if ( isset( $cols['status'] ) && ! in_array( $cols['status'], self::STATUSES, true ) ) {
			unset( $cols['status'] );
		}
		if ( array_key_exists( 'tree', $data ) ) {
			$tree         = Tree::normalize( (array) $data['tree'] );
			$cols['tree'] = (string) wp_json_encode( $tree );
			if ( ! isset( $cols['title'] ) ) {
				$cols['title'] = mb_substr( $tree['course']['title'], 0, 200 );
			}
		}
		foreach ( array( 'source', 'result' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$cols[ $key ] = (string) wp_json_encode( (array) $data[ $key ] );
			}
		}
		$cols['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( self::table(), $cols, array( 'id' => (int) $id ) );
	}

	/**
	 * Merges keys into the stored result (re-reads first so parallel steps never drop data).
	 *
	 * @param int   $id    Id.
	 * @param array $patch Patch (map entries are merged one level deep).
	 * @return array New result.
	 */
	public static function merge_result( $id, array $patch ) {
		$row    = self::get( $id );
		$result = $row ? $row['result'] : array();
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && isset( $result[ $key ] ) && is_array( $result[ $key ] ) && 'map' === $key ) {
				$result[ $key ] = $value + $result[ $key ];
			} else {
				$result[ $key ] = $value;
			}
		}
		self::update( $id, array( 'result' => $result ) );
		return $result;
	}

	/**
	 * Deletes a blueprint.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Lists blueprints (newest first) without the heavy columns.
	 *
	 * @param array $args { status, search, page, per_page }.
	 * @return array { items, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
		}
		$per   = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page  = max( 1, (int) ( $args['page'] ?? 1 ) );
		$sqlw  = implode( ' AND ', $where );
		$table = self::table();
		$count = "SELECT COUNT(*) FROM {$table} WHERE {$sqlw}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		$sql   = "SELECT * FROM {$table} WHERE {$sqlw} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		$items = array();
		foreach ( (array) $rows as $row ) {
			$item = self::shape( $row );
			unset( $item['tree'], $item['source']['text'] );
			$items[] = $item;
		}
		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Counts by status.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A ) as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		$out['total'] = array_sum( $out );
		return $out;
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$tree   = json_decode( (string) $row['tree'], true );
		$tree   = Tree::normalize( is_array( $tree ) ? $tree : array() );
		$source = json_decode( (string) $row['source'], true );
		$result = json_decode( (string) $row['result'], true );
		return array(
			'id'          => (int) $row['id'],
			'title'       => (string) $row['title'],
			'status'      => (string) $row['status'],
			'lms'         => (string) $row['lms'],
			'source_type' => (string) $row['source_type'],
			'source'      => is_array( $source ) ? $source : array(),
			'tree'        => $tree,
			'counts'      => Tree::counts( $tree ),
			'result'      => is_array( $result ) ? $result : array(),
			'job_id'      => (int) $row['job_id'],
			'user_id'     => (int) $row['user_id'],
			'created_at'  => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updated_at'  => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}
}
