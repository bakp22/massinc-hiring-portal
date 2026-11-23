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

/**
 * Blocks that make a page part of the staff portal. Pages holding one of
 * these show only the portal's own header (the Figma's Login, Candidates and
 * candidate screens); the public Careers and application pages keep the
 * site's header and footer.
 */
function minc_hiring_staff_blocks() {
    return [
        'minc-hiring/staff-login',
        'minc-hiring/candidate-dashboard',
        'minc-hiring/candidate-detail',
        'minc-hiring/email-settings',
    ];
}

/**
 * Public portal blocks: the job list and the application form. Applicants see
 * these inside the site's own header and footer; signed-in staff and admins
 * see them in the portal template with the portal header, so following the
 * header's Jobs link doesn't drop them out of the portal.
 */
function minc_hiring_public_blocks() {
    return [
        'minc-hiring/job-list',
        'minc-hiring/application-form',
    ];
}

function minc_hiring_page_has_any_block($post, array $block_names) {
    foreach ($block_names as $block_name) {
        if (has_block($block_name, $post)) {
            return true;
        }
    }
    return false;
}

/**
 * The header section to highlight when the portal template wraps a public page
 * for a signed-in staff member or admin ('jobs'), or '' when it doesn't.
 * Staff pages ('' too) draw their own header inside their block.
 */
function minc_hiring_portal_nav_section($post = null) {
    $post = $post ?: get_queried_object();
    if (!$post instanceof WP_Post
        || minc_hiring_page_has_any_block($post, minc_hiring_staff_blocks())
        || !minc_hiring_page_has_any_block($post, minc_hiring_public_blocks())
        || !current_user_can('view_minc_applications')) {
        return '';
    }
    return 'jobs';
}

/**
 * Use templates/portal-page.php instead of the theme's page template on staff
 * portal pages, and on the Jobs and application pages when a signed-in staff
 * member or admin views them. Works with block themes and classic themes
 * alike. Return false from the 'minc_hiring_use_portal_template' filter to
 * keep the theme's header and footer instead.
 */
function minc_hiring_portal_template($template) {
    if (!is_singular('page')) {
        return $template;
    }

    $post = get_queried_object();
    $nav_section = minc_hiring_portal_nav_section($post);
    $is_portal = $nav_section !== '' || minc_hiring_page_has_any_block($post, minc_hiring_staff_blocks());

    if (!$is_portal || !apply_filters('minc_hiring_use_portal_template', true, $post)) {
        return $template;
    }

    if ($nav_section !== '') {
        // The portal header's styles; staff blocks load these themselves.
        wp_enqueue_style('minc-hiring-ats-admin');
    }

    return MINC_HIRING_PATH . 'templates/portal-page.php';
}

function minc_hiring_register_blocks() {
    add_filter('template_include', 'minc_hiring_portal_template', 99);

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
