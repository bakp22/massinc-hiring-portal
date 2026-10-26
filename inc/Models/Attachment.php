<?php
namespace MassINC\Models;

defined('ABSPATH') || exit;

class Attachment extends Model {
    const KIND_RESUME          = 'resume';
    const KIND_COVER_LETTER    = 'cover_letter';
    const KIND_WRITING_SAMPLE  = 'writing_sample';
    const KIND_LINK            = 'link';

    public static function kinds() {
        return [
            self::KIND_RESUME,
            self::KIND_COVER_LETTER,
            self::KIND_WRITING_SAMPLE,
            self::KIND_LINK,
        ];
    }

    public function get_kind() {
        return (string) $this->get('kind', '');
    }

    public function get_application_id() {
        return (int) $this->get('application_id', 0);
    }

    public function is_link() {
        return $this->get_kind() === self::KIND_LINK;
    }

    /**
     * @param int $id
     * @return Attachment|null
     */
    public static function get_by_id($id) {
        global $wpdb;
        $id = absint($id);
        if (!$id) {
            return null;
        }

        $table = $wpdb->prefix . 'massinc_application_attachments';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ? new self($row) : null;
    }

    /**
     * @param int $application_id
     * @return Attachment[]
     */
    public static function get_for_application($application_id) {
        global $wpdb;
        $application_id = absint($application_id);
        if (!$application_id) {
            return [];
        }

        $table = $wpdb->prefix . 'massinc_application_attachments';
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE application_id = %d ORDER BY id ASC", $application_id),
            ARRAY_A
        );

        return array_map(function ($row) {
            return new self($row);
        }, $rows ?: []);
    }

    /**
     * Insert an attachment row: a stored file (resume, cover letter, writing
     * sample) or a link.
     *
     * @param array $data
     * @return int|false Inserted ID on success, false on failure.
     */
    public static function create($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'massinc_application_attachments';

        $application_id = absint($data['application_id'] ?? 0);
        $kind = (string) ($data['kind'] ?? '');

        if (!$application_id || !in_array($kind, self::kinds(), true)) {
            return false;
        }

        $result = $wpdb->insert(
            $table,
            [
                'application_id' => $application_id,
                'kind'           => $kind,
                'original_name'  => sanitize_text_field($data['original_name'] ?? ''),
                'stored_name'    => sanitize_file_name($data['stored_name'] ?? ''),
                'mime_type'      => sanitize_text_field($data['mime_type'] ?? ''),
                'size_bytes'     => absint($data['size_bytes'] ?? 0),
                'url'            => isset($data['url']) ? esc_url_raw($data['url']) : null,
                'created_at'     => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $result === false ? false : (int) $wpdb->insert_id;
    }

    /**
     * @param int $application_id
     */
    public static function delete_for_application($application_id) {
        global $wpdb;
        $application_id = absint($application_id);
        if (!$application_id) {
            return;
        }

        $wpdb->delete(
            $wpdb->prefix . 'massinc_application_attachments',
            ['application_id' => $application_id],
            ['%d']
        );
    }
}
