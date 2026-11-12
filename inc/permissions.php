<?php
defined('ABSPATH') || exit;

/**
 * Two kinds of account use the hiring portal:
 *
 *  - Administrator: everything staff can do, plus changing a candidate's stage
 *    and the Jobs and Email settings pages in the WordPress dashboard.
 *  - Hiring Staff (minc_hiring_staff): view candidates, rate them, add notes and
 *    manage the staff-name list. Kept out of the WordPress dashboard.
 *
 * Capabilities:
 *  - view_minc_applications  staff + admin: see candidates, rate, add notes
 *  - edit_minc_applications  admin only: change a candidate's stage
 *  - manage_minc_hiring      admin only: Jobs and Email settings in wp-admin
 *
 * Staff are created in WordPress under Users > Add New with the "Hiring Staff"
 * role.
 */

const MINC_HIRING_ROLES_VERSION = '2';

function minc_hiring_admin_capabilities() {
    return ['view_minc_applications', 'edit_minc_applications', 'manage_minc_hiring'];
}

function minc_hiring_register_roles() {
    $admin = get_role('administrator');
    if ($admin) {
        foreach (minc_hiring_admin_capabilities() as $capability) {
            if (empty($admin->capabilities[$capability])) {
                $admin->add_cap($capability);
            }
        }
    }

    // The staff role is rebuilt only when this file's version changes, so its
    // capabilities can be updated without touching the database on every request.
    if (get_option('minc_hiring_roles_version') === MINC_HIRING_ROLES_VERSION && get_role('minc_hiring_staff')) {
        return;
    }

    remove_role('minc_hiring_staff');
    add_role('minc_hiring_staff', 'Hiring Staff', [
        'read'                   => true,
        'view_minc_applications' => true,
    ]);

    // The earlier "Hiring Manager" role is folded into Hiring Staff. Anyone who
    // had it keeps access to the candidates but no longer to the admin-only parts.
    if (get_role('minc_hiring_manager')) {
        foreach (get_users(['role' => 'minc_hiring_manager', 'fields' => 'ID']) as $user_id) {
            $user = new WP_User($user_id);
            $user->add_role('minc_hiring_staff');
            $user->remove_role('minc_hiring_manager');
        }
        remove_role('minc_hiring_manager');
    }

    update_option('minc_hiring_roles_version', MINC_HIRING_ROLES_VERSION);
}

function minc_hiring_can_manage() {
    return current_user_can('manage_minc_hiring');
}

function minc_hiring_can_change_stage() {
    return current_user_can('edit_minc_applications');
}

/**
 * A Hiring Staff account that isn't also an administrator.
 */
function minc_hiring_is_staff_only($user = null) {
    $user = $user ?: wp_get_current_user();
    return $user instanceof WP_User
        && $user->exists()
        && in_array('minc_hiring_staff', (array) $user->roles, true)
        && !user_can($user, 'manage_options');
}

/**
 * Where a Hiring Staff account should be sent instead of the WordPress
 * dashboard, or '' when they may stay (administrators, other users, and the
 * background requests the portal itself makes).
 */
function minc_hiring_staff_admin_redirect() {
    if (!minc_hiring_is_staff_only()) {
        return '';
    }
    if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return '';
    }
    if (basename((string) ($_SERVER['PHP_SELF'] ?? '')) === 'admin-post.php') {
        return '';
    }
    return minc_hiring_dashboard_url();
}

/**
 * Keeps Hiring Staff in the portal: no WordPress toolbar, signing in lands on
 * the Candidates page, and the WordPress dashboard sends them back there.
 */
function minc_hiring_register_access_rules() {
    add_filter('show_admin_bar', function ($show) {
        return minc_hiring_is_staff_only() ? false : $show;
    });

    // The sign-in page has a Staff tab and an Admin tab. Credentials must match
    // the tab: the shared staff account on Staff, an administrator on Admin.
    add_filter('authenticate', function ($user) {
        if (!($user instanceof WP_User) || empty($_POST['massinc_login_type'])) {
            return $user;
        }

        $tab = sanitize_key(wp_unslash($_POST['massinc_login_type']));
        if (!user_can($user, 'view_minc_applications')) {
            return new WP_Error('massinc_no_access', 'That account does not have access to the hiring portal.');
        }

        $is_admin = user_can($user, 'edit_minc_applications');
        if (($tab === 'admin' && !$is_admin) || ($tab === 'staff' && $is_admin)) {
            return new WP_Error('massinc_wrong_login_type', 'Use the other tab to sign in.');
        }

        return $user;
    }, 30);

    add_filter('login_redirect', function ($redirect_to, $requested, $user) {
        return minc_hiring_is_staff_only($user) ? minc_hiring_dashboard_url() : $redirect_to;
    }, 10, 3);

    add_action('admin_init', function () {
        $url = minc_hiring_staff_admin_redirect();
        if ($url) {
            wp_safe_redirect($url);
            exit;
        }
    });
}
