<?php
namespace MassINC\Models;

defined('ABSPATH') || exit;

class Candidate extends Model {
    public function get_name() {
        return trim($this->get('first_name', '') . ' ' . $this->get('last_name', ''));
    }

    public function get_email() {
        return (string) $this->get('email', '');
    }

    public function is_past_applicant() {
        return (bool) $this->get('is_past_applicant', 0);
    }

    /**
     * Retrieve a candidate by email address.
     *
     * @param string $email
     * @return Candidate|null
     */
    public static function get_by_email($email) {
        global $wpdb;
        $email = sanitize_email($email);
        if (empty($email)) {
            return null;
        }

        $table = $wpdb->prefix . 'massinc_candidates';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE email = %s",
                $email
            ),
            ARRAY_A
        );

        return $row ? new self($row) : null;
    }

    /**
     * Retrieve a candidate by first and last name.
     *
     * @param string $first_name
     * @param string $last_name
     * @return Candidate|null
     */
    public static function get_by_name($first_name, $last_name) {
        global $wpdb;
        $first_name = sanitize_text_field($first_name);
        $last_name  = sanitize_text_field($last_name);
        if ($first_name === '' || $last_name === '') {
            return null;
        }

        $table = $wpdb->prefix . 'massinc_candidates';
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE LOWER(first_name) = LOWER(%s) AND LOWER(last_name) = LOWER(%s) ORDER BY id DESC LIMIT 1",
                $first_name,
                $last_name
            ),
            ARRAY_A
        );

        return $row ? new self($row) : null;
    }

    /**
     * Find an existing candidate by email or by full name (first_name + last_name).
     *
     * @param string $email
     * @param string $first_name
     * @param string $last_name
     * @return Candidate|null
     */
    public static function find_existing($email, $first_name = '', $last_name = '') {
        $candidate = self::get_by_email($email);
        if ($candidate) {
            return $candidate;
        }

        return self::get_by_name($first_name, $last_name);
    }

    /**
     * Retrieve a candidate by ID.
     *
     * @param int $id
     * @return Candidate|null
     */
    public static function get_by_id($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) {
            return null;
        }

        $table = $wpdb->prefix . 'massinc_candidates';
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
     * Insert candidate record into wp_massinc_candidates.
     *
     * @param array $data
     * @return int|false Inserted ID on success, false on failure.
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'massinc_candidates';

        $now = current_time('mysql');

        $insert_data = [
            'first_name'        => sanitize_text_field($data['first_name'] ?? ''),
            'last_name'         => sanitize_text_field($data['last_name'] ?? ''),
            'email'             => sanitize_email($data['email'] ?? ''),
            'phone'             => sanitize_text_field($data['phone'] ?? ''),
            'is_past_applicant' => !empty($data['is_past_applicant']) ? 1 : 0,
            'created_at'        => $data['created_at'] ?? $now,
            'updated_at'        => $data['updated_at'] ?? $now,
        ];

        $format = ['%s', '%s', '%s', '%s', '%d', '%s', '%s'];

        if (!empty($data['wp_user_id'])) {
            $insert_data['wp_user_id'] = absint($data['wp_user_id']);
            $format[] = '%d';
        }

        $result = $wpdb->insert($table, $insert_data, $format);

        if ($result === false) {
            return false;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing candidate's information and optional past-applicant flag
     * in wp_massinc_candidates.
     *
     * @param int $id
     * @param array $data
     * @return bool
     */
    public static function update($id, $data) {
        global $wpdb;
        $id = absint($id);
        if (!$id) {
            return false;
        }

        $table = $wpdb->prefix . 'massinc_candidates';

        $update_data = [
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($data['last_name'] ?? ''),
            'phone'      => sanitize_text_field($data['phone'] ?? ''),
            'updated_at' => current_time('mysql'),
        ];
        $format = ['%s', '%s', '%s', '%s'];

        if (isset($data['email'])) {
            $clean_email = sanitize_email($data['email']);
            if ($clean_email !== '') {
                $update_data['email'] = $clean_email;
                $format[] = '%s';
            }
        }

        if (array_key_exists('is_past_applicant', $data)) {
            $update_data['is_past_applicant'] = !empty($data['is_past_applicant']) ? 1 : 0;
            $format[] = '%d';
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            ['id' => $id],
            $format,
            ['%d']
        );

        return $result !== false;
    }
}
