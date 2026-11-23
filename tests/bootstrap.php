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

/**
 * Tests never reach the network. Without this, every submission test sends a
 * real email through Resend using the key in .env. Resend calls get a fake
 * "sent" reply; anything else outside the site fails straight away.
 * Every faked Resend request is kept in $GLOBALS['minc_test_http_requests'] so
 * a test can check what would have been sent.
 */
$GLOBALS['minc_test_http_requests'] = [];

function _minc_block_external_http($preempt, $args, $url) {
    $host = (string) wp_parse_url($url, PHP_URL_HOST);

    if ($host === 'api.resend.com') {
        $GLOBALS['minc_test_http_requests'][] = ['url' => $url, 'args' => $args];
        return [
            'headers'  => [],
            'body'     => wp_json_encode(['id' => 'test-' . count($GLOBALS['minc_test_http_requests'])]),
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    $site_host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
    if ($host !== '' && $host !== $site_host && $host !== 'localhost') {
        return new WP_Error('minc_test_http_blocked', 'Outside HTTP requests are blocked in tests: ' . $url);
    }

    return $preempt;
}
tests_add_filter('pre_http_request', '_minc_block_external_http', 10, 3);

require $_tests_dir . '/includes/bootstrap.php';
