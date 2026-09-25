<?php
namespace MassINC\Models;

defined('ABSPATH') || exit;

class Application extends Model {
    public function get_status() {
        return (string) $this->get('status', 'submitted');
    }

    public function get_job_id() {
        return (int) $this->get('job_id', 0);
    }

    public function get_candidate_id() {
        return (int) $this->get('candidate_id', 0);
    }

    /**
     * Retrieve an application by ID.
     *
     * @param int $id
     * @return Application|null
     */
    public static function get_by_id($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) {
            return null;
        }

        $table = $wpdb->prefix . 'massinc_applications';
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
     * Insert application record into wp_massinc_applications mapping job_id and candidate_id.
     *
     * @param array $data
     * @return int|false Inserted ID on success, false on failure.
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'massinc_applications';

        $now = current_time('mysql');

        $job_id = absint($data['job_id'] ?? 0);
        $candidate_id = absint($data['candidate_id'] ?? 0);

        if (!$job_id || !$candidate_id) {
            return false;
        }

        $insert_data = [
            'job_id'           => $job_id,
            'candidate_id'     => $candidate_id,
            'resume_url'       => esc_url_raw($data['resume_url'] ?? ''),
            'cover_letter'     => isset($data['cover_letter']) ? wp_kses_post($data['cover_letter']) : null,
            'cover_letter_url' => esc_url_raw($data['cover_letter_url'] ?? ''),
            'status'           => sanitize_text_field($data['status'] ?? 'submitted'),
            'notes'            => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
            'created_at'       => $data['created_at'] ?? $now,
            'updated_at'       => $data['updated_at'] ?? $now,
        ];

        $format = ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if (!empty($data['assigned_to'])) {
            $insert_data['assigned_to'] = absint($data['assigned_to']);
            $format[] = '%d';
        }

        $result = $wpdb->insert($table, $insert_data, $format);

        if ($result === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }
}
