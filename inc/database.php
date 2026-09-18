<?php
namespace MassINC;

defined('ABSPATH') || exit;

class Database {
    /**
     * Create or update custom database tables.
     * Refer to docs/database.md for required column types and indices.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // TODO (Engineer 1): Define table names with $wpdb->prefix . 'massinc_'
        $jobs_table        = $wpdb->prefix . 'massinc_jobs';
        $candidates_table  = $wpdb->prefix . 'massinc_candidates';
        $applications_table = $wpdb->prefix . 'massinc_applications';

        // TODO (Engineer 1): Write CREATE TABLE statements and call dbDelta()
        // require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
}
