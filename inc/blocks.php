<?php
defined('ABSPATH') || exit;

function minc_hiring_register_blocks() {
    add_action('init', function () {
        // Shared by the dashboard and candidate detail blocks (see their block.json "style").
        wp_register_style('minc-hiring-ats-admin', MINC_HIRING_URL . 'src/shared/admin.css', [], MINC_HIRING_VERSION);

        register_block_type(MINC_HIRING_PATH . 'src/job-list');
        register_block_type(MINC_HIRING_PATH . 'src/application-form');
        register_block_type(MINC_HIRING_PATH . 'src/staff-login');
        register_block_type(MINC_HIRING_PATH . 'src/candidate-dashboard');
        register_block_type(MINC_HIRING_PATH . 'src/candidate-detail');
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
