<?php
namespace MassINC;

defined('ABSPATH') || exit;

class Database {
    /**
     * Bump when create_tables() changes so existing installs pick it up.
     */
    const SCHEMA_VERSION = '3';

    /**
     * Run create_tables() on installs created before the current schema
     * version (activation only fires once, so new tables need this).
     */
    public static function maybe_upgrade() {
        if (get_option('minc_hiring_db_version') !== self::SCHEMA_VERSION) {
            self::create_tables();
        }
    }

    /**
     * Create or update custom database tables.
     * Refer to docs/database.md for required column types and indices.
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $jobs_table          = $wpdb->prefix . 'massinc_jobs';
        $candidates_table    = $wpdb->prefix . 'massinc_candidates';
        $applications_table  = $wpdb->prefix . 'massinc_applications';
        $attachments_table   = $wpdb->prefix . 'massinc_application_attachments';
        $notifications_table = $wpdb->prefix . 'massinc_notifications';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE $jobs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description longtext NOT NULL,
            location varchar(255) NOT NULL DEFAULT '',
            employment_type varchar(100) NOT NULL DEFAULT '',
            salary_min decimal(10,2) DEFAULT NULL,
            salary_max decimal(10,2) DEFAULT NULL,
            status varchar(50) NOT NULL DEFAULT 'open',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status)
        ) $charset_collate;

        CREATE TABLE $candidates_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            wp_user_id bigint(20) unsigned DEFAULT NULL,
            first_name varchar(100) NOT NULL DEFAULT '',
            last_name varchar(100) NOT NULL DEFAULT '',
            email varchar(255) NOT NULL DEFAULT '',
            phone varchar(50) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY email (email),
            KEY wp_user_id (wp_user_id)
        ) $charset_collate;

        CREATE TABLE $applications_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            candidate_id bigint(20) unsigned NOT NULL,
            resume_url varchar(255) NOT NULL DEFAULT '',
            cover_letter text DEFAULT NULL,
            cover_letter_url varchar(255) NOT NULL DEFAULT '',
            status varchar(50) NOT NULL DEFAULT 'submitted',
            assigned_to bigint(20) unsigned DEFAULT NULL,
            notes text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY candidate_id (candidate_id),
            KEY status (status),
            KEY assigned_to (assigned_to)
        ) $charset_collate;

        CREATE TABLE $attachments_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            application_id bigint(20) unsigned NOT NULL,
            kind varchar(30) NOT NULL,
            original_name varchar(255) NOT NULL DEFAULT '',
            stored_name varchar(100) NOT NULL DEFAULT '',
            mime_type varchar(100) NOT NULL DEFAULT '',
            size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
            url text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY application_id (application_id)
        ) $charset_collate;

        CREATE TABLE $notifications_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            trigger_key varchar(100) NOT NULL DEFAULT '',
            application_id bigint(20) unsigned DEFAULT NULL,
            recipient_email varchar(255) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            payload longtext NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            attempts int(11) unsigned NOT NULL DEFAULT 0,
            last_error text DEFAULT NULL,
            next_retry_at datetime DEFAULT NULL,
            sent_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY trigger_key (trigger_key),
            KEY application_id (application_id),
            KEY status (status),
            KEY next_retry_at (next_retry_at)
        ) $charset_collate;";

        dbDelta($sql);
        update_option('minc_hiring_db_version', self::SCHEMA_VERSION);
    }
}
