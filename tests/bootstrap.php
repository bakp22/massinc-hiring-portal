<?php
/**
 * PHPUnit bootstrap for the MassINC Hiring Portal plugin.
 * Expects the WordPress core test suite (provided by `wp-env`'s tests
 * containers) at $WP_TESTS_DIR, falling back to the conventional path.
 */

$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

if (!getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH')) {
    putenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH=' . dirname(__DIR__) . '/vendor/yoast/phpunit-polyfills');
}

require_once $_tests_dir . '/includes/functions.php';

function _minc_manually_load_plugin() {
    require dirname(__DIR__) . '/massinc-hiring-portal.php';
    \MassINC\Database::create_tables();
}
tests_add_filter('muplugins_loaded', '_minc_manually_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';
