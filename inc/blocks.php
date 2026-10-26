<?php
defined('ABSPATH') || exit;

function minc_hiring_register_blocks() {
    add_action('init', function () {
        register_block_type(MINC_HIRING_PATH . 'src/job-list');
        register_block_type(MINC_HIRING_PATH . 'src/application-form');
        register_block_type(MINC_HIRING_PATH . 'src/staff-login');
    });

    add_action('wp_login_failed', function () {
        if (empty($_POST['massinc_login_return'])) {
            return;
        }

        $return_url = esc_url_raw(wp_unslash($_POST['massinc_login_return']));
        $return_url = wp_validate_redirect($return_url, home_url('/'));

        wp_safe_redirect(add_query_arg('login', 'failed', $return_url));
        exit;
    });
}
