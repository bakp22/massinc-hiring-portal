<?php
/**
 * Full-screen page for the staff portal.
 *
 * Replaces the theme's header and footer so portal screens show only the
 * portal's own header, as in the Figma. Used for the sign-in, Candidates and
 * candidate pages, and for the Jobs and application pages when a signed-in
 * staff member or admin views them (those get the portal header here, since
 * their blocks don't draw one). Still runs wp_head()/wp_footer(), so block
 * styles, scripts and the admin toolbar load as usual.
 * Chosen by minc_hiring_portal_template() in inc/blocks.php.
 */
defined('ABSPATH') || exit;

$minc_nav_section = minc_hiring_portal_nav_section();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php wp_head(); ?>
    <style>
        body.massinc-portal-page { margin: 0; background: #f8f5f5; }
        .massinc-portal-main { min-height: 100vh; }
        .massinc-portal-main > * { margin-block: 0; }
    </style>
</head>
<body <?php body_class('massinc-portal-page'); ?>>
<?php wp_body_open(); ?>
<main class="massinc-portal-main" id="main">
    <?php if ($minc_nav_section) : ?>
        <div class="massinc-ats massinc-portal-header">
            <?php minc_hiring_admin_nav(false, $minc_nav_section); ?>
        </div>
        <div class="massinc-ats massinc-portal-content">
            <?php
            while (have_posts()) {
                the_post();
                if (!isset($_GET['job_id']) && has_block('minc-hiring/job-list')) {
                    echo '<h1 class="massinc-portal-title">' . esc_html(get_the_title()) . '</h1>';
                }
                the_content();
            }
            ?>
        </div>
    <?php else : ?>
        <?php
        while (have_posts()) {
            the_post();
            the_content();
        }
        ?>
    <?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
