<?php
namespace MassINC\Models;

defined('ABSPATH') || exit;

class Job extends Model {
    public static function required_field_labels() {
        return [
            'phone'           => 'Phone number',
            'resume'          => 'CV / resume',
            'cover_letter'    => 'Cover letter',
            'writing_samples' => 'Writing samples',
            'sample_url'      => 'Links',
        ];
    }

    /** Open positions and application counts for the requirement switches. */
    public static function get_open_with_applicant_counts() {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT j.*, COALESCE(a.applicant_count, 0) AS applicant_count
             FROM {$wpdb->prefix}massinc_jobs j
             LEFT JOIN (
                 SELECT job_id, COUNT(*) AS applicant_count
                 FROM {$wpdb->prefix}massinc_applications GROUP BY job_id
             ) a ON a.job_id = j.id
             WHERE j.status = %s
             ORDER BY j.created_at DESC, j.id DESC", 'open'),
            ARRAY_A
        );
        return array_map(function ($row) { return new self($row); }, $rows ?: []);
    }

    /** Existing positions keep their current requirements until an admin edits them. */
    public static function required_field_defaults() {
        return [
            'phone'           => false,
            'resume'          => true,
            'cover_letter'    => false,
            'writing_samples' => false,
            'sample_url'      => false,
        ];
    }

    private static function normalize_required_fields(array $fields) {
        $requirements = self::required_field_defaults();
        foreach ($requirements as $field => $default) {
            if (array_key_exists($field, $fields)) {
                $requirements[$field] = filter_var($fields[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }
        return $requirements;
    }

    public function get_required_fields() {
        $fields = json_decode((string) $this->get('required_fields', ''), true);
        return self::normalize_required_fields(is_array($fields) ? $fields : []);
    }

    /** Save the complete set of requirements for one position. */
    public static function update_required_fields($id, array $fields) {
        global $wpdb;
        return $wpdb->update(
            $wpdb->prefix . 'massinc_jobs',
            [
                'required_fields' => wp_json_encode(self::normalize_required_fields($fields)),
                'updated_at'      => current_time('mysql'),
            ],
            ['id' => absint($id)],
            ['%s', '%s'],
            ['%d']
        ) !== false;
    }

    public function to_array() {
        $data = $this->data;
        $data['id'] = (int) $data['id'];
        $data['salary_min'] = $data['salary_min'] !== null ? (float) $data['salary_min'] : null;
        $data['salary_max'] = $data['salary_max'] !== null ? (float) $data['salary_max'] : null;
        $data['required_fields'] = $this->get_required_fields();
        return $data;
    }

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
            'required_fields' => wp_json_encode(self::normalize_required_fields(
                isset($data['required_fields']) && is_array($data['required_fields']) ? $data['required_fields'] : []
            )),
        ];

        $format = ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

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
