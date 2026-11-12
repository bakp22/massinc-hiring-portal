<?php
namespace MassINC\Models;

defined('ABSPATH') || exit;

/**
 * A staff rating (1 to 5) of an application. One row per reviewer per
 * application: rating again replaces the earlier score.
 *
 * The reviewer is a name picked on the page (staff share one sign-in) and is
 * stored as text of its own, so removing the name from the staff list leaves
 * past ratings as they are.
 */
class Rating extends Model {
    const MIN_SCORE = 1;
    const MAX_SCORE = 5;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'massinc_ratings';
    }

    /**
     * Ratings for an application, newest change first.
     *
     * @param int $application_id
     * @return Rating[]
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
                "SELECT * FROM {$table} WHERE application_id = %d ORDER BY updated_at DESC, id DESC",
                $application_id
            ),
            ARRAY_A
        );

        return array_map(function ($row) {
            return new self($row);
        }, $rows ?: []);
    }

    /**
     * Average score and number of ratings for every rated application, keyed
     * by application id (applications nobody has rated are absent).
     *
     * @return array<int, array{average: float, count: int}>
     */
    public static function summaries() {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results(
            "SELECT application_id, AVG(score) AS average, COUNT(*) AS total FROM {$table} GROUP BY application_id",
            ARRAY_A
        );

        $summaries = [];
        foreach ($rows ?: [] as $row) {
            $summaries[(int) $row['application_id']] = [
                'average' => (float) $row['average'],
                'count'   => (int) $row['total'],
            ];
        }
        return $summaries;
    }

    /**
     * Rate an application as a reviewer. If that reviewer (matched ignoring
     * case) already rated it, their score is replaced; otherwise a new rating
     * is added.
     *
     * @param int    $application_id
     * @param string $reviewer       Already cleaned (non-empty, at most 100 characters).
     * @param int    $score          1 to 5.
     * @return array|\WP_Error ['reviewer' => string, 'score' => int, 'created' => bool, 'updated_at' => string]
     */
    public static function save($application_id, $reviewer, $score) {
        global $wpdb;
        $table = self::table();
        $application_id = absint($application_id);
        $score = (int) $score;

        if (!$application_id || $reviewer === '' || $score < self::MIN_SCORE || $score > self::MAX_SCORE) {
            return new \WP_Error('massinc_invalid_rating', 'That rating could not be saved.', ['status' => 400]);
        }

        $now = current_time('mysql');

        $find = function () use ($wpdb, $table, $application_id, $reviewer) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id FROM {$table} WHERE application_id = %d AND LOWER(reviewer_name) = LOWER(%s) LIMIT 1",
                    $application_id,
                    $reviewer
                )
            );
        };
        $replace = function ($id) use ($wpdb, $table, $reviewer, $score, $now) {
            return $wpdb->update(
                $table,
                ['reviewer_name' => $reviewer, 'score' => $score, 'updated_at' => $now],
                ['id' => (int) $id],
                ['%s', '%d', '%s'],
                ['%d']
            );
        };

        $existing = $find();
        $created = false;

        if ($existing) {
            $saved = $replace($existing->id) !== false;
        } else {
            $created = true;
            $saved = $wpdb->insert(
                $table,
                [
                    'application_id' => $application_id,
                    'reviewer_name'  => $reviewer,
                    'score'          => $score,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ],
                ['%d', '%s', '%d', '%s', '%s']
            ) !== false;

            // Two submissions at once: the unique key refused the second insert,
            // so the first one's row is there to replace.
            if (!$saved && ($raced = $find())) {
                $created = false;
                $saved = $replace($raced->id) !== false;
            }
        }

        if (!$saved) {
            return new \WP_Error('massinc_rating_failed', 'Could not save that rating. Please try again.', ['status' => 500]);
        }

        return [
            'reviewer'   => $reviewer,
            'score'      => $score,
            'created'    => $created,
            'updated_at' => $now,
        ];
    }

    public function get_score() {
        return (int) $this->get('score', 0);
    }
}
