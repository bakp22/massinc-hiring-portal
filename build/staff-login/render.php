<?php
/**
 * Frontend render template for Staff Login block.
 */
defined('ABSPATH') || exit;

$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'massinc-staff-login-block',
]);

$redirect_to = isset($_GET['redirect_to'])
    ? esc_url_raw(wp_unslash($_GET['redirect_to']))
    : admin_url('admin.php?page=minc-hiring-applications');
$redirect_to = wp_validate_redirect($redirect_to, admin_url('admin.php?page=minc-hiring-applications'));

$return_url = remove_query_arg('login', get_permalink());
$has_login_error = isset($_GET['login']) && sanitize_text_field(wp_unslash($_GET['login'])) === 'failed';
?>

<div <?php echo $wrapper_attributes; ?>>
    <div class="massinc-login-card">
        <div class="massinc-login-logo">MassINC</div>

        <div class="massinc-login-header">
            <h1 class="massinc-login-title">Staff sign in</h1>
            <p class="massinc-login-subtitle">
                Applicant tracking for MassInc and Commonwealth Beacon.
            </p>
        </div>

        <form id="massinc-login-form" method="post" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>" novalidate>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
            <input type="hidden" name="massinc_login_return" value="<?php echo esc_attr($return_url); ?>">

            <div class="massinc-form-error-summary" role="alert" <?php echo $has_login_error ? '' : 'hidden'; ?>>
                <?php if ($has_login_error) : ?>
                    <?php echo esc_html('Invalid email or password. Please try again.'); ?>
                <?php endif; ?>
            </div>

            <div class="massinc-field" data-field="email">
                <label for="massinc-login-email">Email</label>
                <input
                    type="email"
                    id="massinc-login-email"
                    name="log"
                    autocomplete="username"
                    placeholder="dana.whitfield@massinc.org"
                    required
                >
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

        <p class="massinc-login-footer">
            Access uses your MassInc WordPress account.
        </p>
    </div>
</div>
