<?php
/**
 * Plugin Name: MassINC Hiring Portal
 * Description: Boilerplate hiring application portal for a WordPress site. Provides jobs, candidates, applications, admin management, and Gutenberg blocks.
 * Version: 0.1.0
 * Author: Hack4Impact BU
 * License: GPL-2.0-or-later
 */

defined('ABSPATH') || exit;

define('MINC_HIRING_VERSION', '0.1.0');
define('MINC_HIRING_PATH', plugin_dir_path(__FILE__));
define('MINC_HIRING_URL', plugin_dir_url(__FILE__));

require_once MINC_HIRING_PATH . 'inc/database.php';
require_once MINC_HIRING_PATH . 'inc/permissions.php';
require_once MINC_HIRING_PATH . 'inc/Models/Model.php';
require_once MINC_HIRING_PATH . 'inc/Models/Job.php';
require_once MINC_HIRING_PATH . 'inc/Models/Candidate.php';
require_once MINC_HIRING_PATH . 'inc/Models/Application.php';
require_once MINC_HIRING_PATH . 'inc/admin.php';
require_once MINC_HIRING_PATH . 'inc/api.php';
require_once MINC_HIRING_PATH . 'inc/blocks.php';

register_activation_hook(__FILE__, 'minc_hiring_install');
register_deactivation_hook(__FILE__, 'minc_hiring_deactivate');

add_action('plugins_loaded', function () {
    minc_hiring_register_roles();
    minc_hiring_register_blocks();
    minc_hiring_register_admin();
    minc_hiring_register_api();
});
