<?php
namespace MassINC\Models;

defined('ABSPATH') || exit;

class Job extends Model {
    /**
     * Query wp_massinc_jobs for all status='open' roles.
     *
     * @return Job[]
     */
    public static function get_all_open() {
        global $wpdb;
        $table = $wpdb->prefix . 'massinc_jobs';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC",
                'open'
            ),
            ARRAY_A
        );

        if (empty($results)) {
            return [];
        }

        return array_map(function ($row) {
            return new self($row);
        }, $results);
    }

    /**
     * Retrieve single job by ID using $wpdb->prepare.
     *
     * @param int $id
     * @return Job|null
     */
    public static function get_by_id($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) {
            return null;
        }

        $table = $wpdb->prefix . 'massinc_jobs';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $row ? new self($row) : null;
    }

    /**
     * Insert job record into wp_massinc_jobs.
     *
     * @param array $data
     * @return int|false Inserted ID on success, false on failure.
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'massinc_jobs';

        $now = current_time('mysql');

        $insert_data = [
            'title'           => sanitize_text_field($data['title'] ?? ''),
            'description'     => wp_kses_post($data['description'] ?? ''),
            'location'        => sanitize_text_field($data['location'] ?? ''),
            'employment_type' => sanitize_text_field($data['employment_type'] ?? ''),
            'status'          => sanitize_text_field($data['status'] ?? 'open'),
            'created_at'      => $data['created_at'] ?? $now,
            'updated_at'      => $data['updated_at'] ?? $now,
        ];

        $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if (isset($data['salary_min']) && $data['salary_min'] !== '' && $data['salary_min'] !== null) {
            $insert_data['salary_min'] = (float) $data['salary_min'];
            $format[] = '%f';
        }

        if (isset($data['salary_max']) && $data['salary_max'] !== '' && $data['salary_max'] !== null) {
            $insert_data['salary_max'] = (float) $data['salary_max'];
            $format[] = '%f';
        }

        $result = $wpdb->insert($table, $insert_data, $format);

        if ($result === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }
}
