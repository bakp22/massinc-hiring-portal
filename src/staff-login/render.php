<?php
/**
 * Frontend render template for Staff Login block.
 *
 * Two ways in, switched by ?type= on the page and matching the hi-fi screens
 * "03 Login" (Staff) and "03b Login Admin":
 *  - Staff: the one shared staff account (username + password).
 *  - Admin: an individual administrator account (email + password).
 * Both post to WordPress's own sign-in. The hidden massinc_login_type field
 * lets minc_hiring_register_access_rules() (inc/permissions.php) refuse an
 * account on the wrong tab.
 */
defined('ABSPATH') || exit;

$type = (isset($_GET['type']) && $_GET['type'] === 'admin') ? 'admin' : 'staff';

$copy = [
    'staff' => [
        'title'        => 'Staff sign in',
        'subtitle'     => 'Applicant tracking for MassInc and Commonwealth Beacon.',
        'label'        => 'Staff username',
        'input_type'   => 'text',
        'placeholder'  => 'massinc-staff',
        'autocomplete' => 'username',
        'helper'       => 'Use the shared staff login. You’ll pick your name when you rate or add notes.',
        'footer'       => 'Access uses the shared MassInc WordPress account.',
        'failed'       => 'Invalid username or password. Please try again.',
        'wrong_type'   => 'That is an admin account. Use the Admin tab to sign in.',
    ],
    'admin' => [
        'title'        => 'Admin sign in',
        'subtitle'     => 'Individual logins for Maeve and Ayana. Admins manage stages, positions and automated emails.',
        'label'        => 'Admin email',
        'input_type'   => 'email',
        'placeholder'  => 'name@massinc.org',
        'autocomplete' => 'username',
        'helper'       => 'Your own MassInc address — admin actions are recorded against it.',
        'footer'       => 'Admin access is limited to Maeve and Ayana.',
        'failed'       => 'Invalid email or password. Please try again.',
        'wrong_type'   => 'That is the shared staff account. Use the Staff tab to sign in.',
    ],
][$type];

// After signing in, everyone lands on the Candidates page (administrators can
// still reach the WordPress dashboard from there).
$default_redirect = minc_hiring_dashboard_url();
$requested_redirect = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';
$redirect_to = $requested_redirect ? wp_validate_redirect($requested_redirect, $default_redirect) : $default_redirect;

$page_url = remove_query_arg(['login', 'type'], get_permalink());
if ($requested_redirect) {
    $page_url = add_query_arg('redirect_to', rawurlencode($requested_redirect), $page_url);
}
$tab_urls = [
    'staff' => $page_url,
    'admin' => add_query_arg('type', 'admin', $page_url),
];
// A failed sign-in comes back to the same tab.
$return_url = $tab_urls[$type];

$login_error = isset($_GET['login']) ? sanitize_key(wp_unslash($_GET['login'])) : '';
$error_message = $login_error === 'wrong_type' ? $copy['wrong_type'] : ($login_error === 'failed' ? $copy['failed'] : '');

$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'massinc-staff-login-block',
]);
?>

<div <?php echo $wrapper_attributes; ?>>
    <div class="massinc-login-card">
        <img class="massinc-login-logo" src="<?php echo esc_url(MINC_HIRING_URL . 'assets/images/massinc-logo.png'); ?>" alt="MassINC" width="276" height="48">

        <div class="massinc-login-header">
            <h1 class="massinc-login-title"><?php echo esc_html($copy['title']); ?></h1>
            <p class="massinc-login-subtitle"><?php echo esc_html($copy['subtitle']); ?></p>
        </div>

        <nav class="massinc-login-tabs" aria-label="Sign in as">
            <?php foreach (['staff' => 'Staff', 'admin' => 'Admin'] as $key => $label) : ?>
                <a class="massinc-login-tab<?php echo $key === $type ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url($tab_urls[$key]); ?>"
                   <?php echo $key === $type ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
            <?php endforeach; ?>
        </nav>

        <form id="massinc-login-form" method="post" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>" data-login-type="<?php echo esc_attr($type); ?>" novalidate>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
            <input type="hidden" name="massinc_login_return" value="<?php echo esc_attr($return_url); ?>">
            <input type="hidden" name="massinc_login_type" value="<?php echo esc_attr($type); ?>">

            <div class="massinc-form-error-summary" role="alert" <?php echo $error_message ? '' : 'hidden'; ?>>
                <?php echo esc_html($error_message); ?>
            </div>

            <div class="massinc-field" data-field="login">
                <label for="massinc-login-name"><?php echo esc_html($copy['label']); ?></label>
                <input
                    type="<?php echo esc_attr($copy['input_type']); ?>"
                    id="massinc-login-name"
                    name="log"
                    autocomplete="<?php echo esc_attr($copy['autocomplete']); ?>"
                    placeholder="<?php echo esc_attr($copy['placeholder']); ?>"
                    required
                >
                <p class="massinc-field-hint"><?php echo esc_html($copy['helper']); ?></p>
                <p class="massinc-field-error" hidden></p>
            </div>

            <div class="massinc-field" data-field="password">
                <label for="massinc-login-password">Password</label>
                <input
                    type="password"
                    id="massinc-login-password"
                    name="pwd"
                    autocomplete="current-password"
                    required
                >
                <p class="massinc-field-error" hidden></p>
            </div>

            <button type="submit" class="massinc-submit">Sign in</button>
        </form>

        <p class="massinc-login-footer"><?php echo esc_html($copy['footer']); ?></p>
    </div>
</div>
