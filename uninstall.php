<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiCourseBuilder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3lm_settings = get_option( 'dev3lm_settings', array() );
if ( empty( $dev3lm_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3lm_usage', 'dev3lm_core_cache', 'dev3lm_core_jobs', 'dev3lm_core_embeddings', 'dev3lm_blueprints', 'dev3lm_rewrites' ) as $dev3lm_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3lm_table}" );
}
foreach ( array( 'dev3lm_settings', 'dev3lm_db_version' ) as $dev3lm_option ) {
	delete_option( $dev3lm_option );
}
delete_transient( 'dev3lm_models' );
wp_unschedule_hook( 'dev3lm_run_job' );
wp_unschedule_hook( 'dev3lm_daily' );
// Courses, lessons and quizzes created in the LMS belong to the site and are kept.
