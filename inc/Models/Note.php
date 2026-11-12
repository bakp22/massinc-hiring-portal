<?php
namespace MassINC\Models;

defined('ABSPATH') || exit;

/**
 * A staff note on an application. Append-only for now: notes can be added but
 * not edited or deleted.
 *
 * The author is a name picked on the page (staff share one sign-in) and is
 * stored as text of its own, so removing the name from the staff list leaves
 * past notes as they are.
 */
class Note extends Model {
    const MAX_LENGTH = 5000;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'massinc_notes';
    }

    /**
     * Notes for an application, newest first.
     *
     * @param int $application_id
     * @return Note[]
     */
    public static function get_for_application($application_id) {
        global $wpdb;
        $application_id = absint($application_id);
        if (!$application_id) {
            return [];
        }

        $table = self::table();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE application_id = %d ORDER BY created_at DESC, id DESC",
                $application_id
            ),
            ARRAY_A
        );

        return array_map(function ($row) {
            return new self($row);
        }, $rows ?: []);
    }

    /**
     * Add a note.
     *
     * @param int    $application_id
     * @param string $author Already cleaned (non-empty, at most 100 characters).
     * @param string $body   Already cleaned (non-empty, at most MAX_LENGTH characters).
     * @return array|\WP_Error ['id' => int, 'author' => string, 'body' => string, 'created_at' => string]
     */
    public static function create($application_id, $author, $body) {
        global $wpdb;
        $application_id = absint($application_id);

        if (!$application_id || $author === '' || $body === '') {
            return new \WP_Error('massinc_invalid_note', 'That note could not be saved.', ['status' => 400]);
        }

        $now = current_time('mysql');
        $inserted = $wpdb->insert(
            self::table(),
            [
                'application_id' => $application_id,
                'author_name'    => $author,
                'body'           => $body,
                'created_at'     => $now,
            ],
            ['%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new \WP_Error('massinc_note_failed', 'Could not save that note. Please try again.', ['status' => 500]);
        }

        return [
            'id'         => (int) $wpdb->insert_id,
            'author'     => $author,
            'body'       => $body,
            'created_at' => $now,
        ];
    }
}
