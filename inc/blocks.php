<?php
defined('ABSPATH') || exit;

/**
 * Version string for a plugin file that changes whenever the file does, so
 * browsers never run a stale copy after an update (a fixed version string
 * keeps serving the cached file, which can then disagree with the new markup).
 */
function minc_hiring_file_version($relative_path) {
    $path = MINC_HIRING_PATH . $relative_path;
    return file_exists($path) ? (string) filemtime($path) : MINC_HIRING_VERSION;
}

function minc_hiring_register_blocks() {
    add_action('init', function () {
        // Shared by the dashboard and candidate detail blocks (see their block.json "style").
        wp_register_style('minc-hiring-ats-admin', MINC_HIRING_URL . 'src/shared/admin.css', [], minc_hiring_file_version('src/shared/admin.css'));
        // The custom dropdown both blocks' view.js files build on (see their view.asset.php).
        wp_register_script('minc-hiring-ats-select', MINC_HIRING_URL . 'src/shared/select.js', [], minc_hiring_file_version('src/shared/select.js'), true);

        register_block_type(MINC_HIRING_PATH . 'src/job-list');
        register_block_type(MINC_HIRING_PATH . 'src/application-form');
        register_block_type(MINC_HIRING_PATH . 'src/staff-login');
        register_block_type(MINC_HIRING_PATH . 'src/candidate-dashboard');
        register_block_type(MINC_HIRING_PATH . 'src/candidate-detail');
    });

    add_action('wp_login_failed', function ($username = '', $error = null) {
        if (empty($_POST['massinc_login_return'])) {
            return;
        }

        $return_url = esc_url_raw(wp_unslash($_POST['massinc_login_return']));
        $return_url = wp_validate_redirect($return_url, home_url('/'));

        // Correct credentials on the wrong tab get their own message; anything
        // else stays the same generic "invalid" so it doesn't reveal who has an account.
        $reason = ($error instanceof WP_Error && $error->get_error_code() === 'massinc_wrong_login_type')
            ? 'wrong_type'
            : 'failed';

        wp_safe_redirect(add_query_arg('login', $reason, $return_url));
        exit;
    }, 10, 2);
}
