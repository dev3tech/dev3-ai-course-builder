<?php
/**
 * Loads Tutor LMS (free, installed by wp-env) before DEV3 and creates its tables once, outside the
 * per-test transactions. LearnDash is paid: its tests load tests/php/stubs/learndash.php instead.
 *
 * @package Dev3\AiCourseBuilder
 */

tests_add_filter(
	'dev3lm_test_dependencies',
	static function () {
		return array( 'tutor/tutor.php' );
	}
);

tests_add_filter(
	'init',
	static function () {
		if ( class_exists( '\TUTOR\Tutor' ) && method_exists( '\TUTOR\Tutor', 'create_database' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			\TUTOR\Tutor::create_database();
		}
	},
	99
);

tests_add_filter( 'wp_mail_from', static fn() => 'wordpress@example.org' );
