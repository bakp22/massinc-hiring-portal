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
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name'  => sanitize_text_field($data['last_name'] ?? ''),
            'email'      => sanitize_email($data['email'] ?? ''),
            'phone'      => sanitize_text_field($data['phone'] ?? ''),
            'created_at' => $data['created_at'] ?? $now,
            'updated_at' => $data['updated_at'] ?? $now,
        ];

        $format = ['%s', '%s', '%s', '%s', '%s', '%s'];

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
     * Update an existing candidate's name/phone in wp_massinc_candidates.
     * Email is intentionally not updated here since it's the lookup key
     * used to find this candidate in the first place.
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

        $result = $wpdb->update(
            $table,
            $update_data,
            ['id' => $id],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }
}
