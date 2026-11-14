<?php
namespace MassINC;

use MassINC\Models\Application;
use MassINC\Models\Attachment;
use MassINC\Models\Candidate;
use MassINC\Models\Job;
use MassINC\Models\Note;
use MassINC\Models\Rating;

defined('ABSPATH') || exit;

class API {
    public static function register_routes() {
        register_rest_route('massinc/v1', '/jobs', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_jobs'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('massinc/v1', '/staff-names', [
            [
                'methods'  => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'add_staff_name'],
                'permission_callback' => [self::class, 'can_edit_staff_names'],
                'args' => [
                    'name' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ],
            [
                'methods'  => \WP_REST_Server::DELETABLE,
                'callback' => [self::class, 'remove_staff_name'],
                'permission_callback' => [self::class, 'can_edit_staff_names'],
                'args' => [
                    'name' => [
                        'type'     => 'string',
                        'required' => true,
                    ],
                ],
            ],
        ]);

        register_rest_route('massinc/v1', '/applications/(?P<id>\d+)/ratings', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'save_rating'],
            'permission_callback' => [self::class, 'can_review_applications'],
            'args' => [
                'reviewer' => [
                    'type'     => 'string',
                    'required' => true,
                ],
                'score' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'validate_callback' => function ($value) {
                        return is_numeric($value)
                            && (int) $value == $value
                            && (int) $value >= Rating::MIN_SCORE
                            && (int) $value <= Rating::MAX_SCORE;
                    },
                ],
            ],
        ]);

        register_rest_route('massinc/v1', '/applications/(?P<id>\d+)/stage', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'change_stage'],
            'permission_callback' => [self::class, 'can_change_stage'],
            'args' => [
                'stage' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route('massinc/v1', '/applications/(?P<id>\d+)/notes', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'add_note'],
            'permission_callback' => [self::class, 'can_review_applications'],
            'args' => [
                'author' => [
                    'type'     => 'string',
                    'required' => true,
                ],
                'text' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
        ]);

        register_rest_route('massinc/v1', '/attachments/(?P<id>\d+)/download', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'download_attachment'],
            'permission_callback' => [self::class, 'can_view_applications'],
        ]);

        register_rest_route('massinc/v1', '/applications', [
            'methods'  => \WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'submit_application'],
            'permission_callback' => '__return_true',
            // WordPress runs validate_callback, then sanitize_callback, before our callback.
            // A missing or invalid field returns a 400 automatically.
            'args' => [
                'first_name' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => [self::class, 'normalize_name'],
                    'validate_callback' => function ($value) {
                        return is_string($value) && trim($value) !== '';
                    },
                ],
                'last_name' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => [self::class, 'normalize_name'],
                    'validate_callback' => function ($value) {
                        return is_string($value) && trim($value) !== '';
                    },
                ],
                'applicant_email' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => function ($value) {
                        return strtolower(sanitize_email($value));
                    },
                    'validate_callback' => function ($value) {
                        return is_string($value) && is_email($value);
                    },
                ],
                'job_id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return ctype_digit((string) $value) && (int) $value > 0;
                    },
                ],
                'phone' => [
                    'type'              => 'string',
                    'required'          => false,
                    'sanitize_callback' => function ($value) {
                        return preg_replace('/\D/', '', sanitize_text_field($value));
                    },
                ],
            ],
        ]);
    }

    /**
     * Title-cases a name (e.g. "JOHN smith" -> "John Smith"). Capitalizes
     * after spaces, hyphens, and apostrophes, but can't know about
     * exceptions like "McDonald" or "O'Brien" without a name dictionary.
     */
    public static function normalize_name($value) {
        $value = sanitize_text_field($value);
        return ucwords(strtolower($value), " -'");
    }

    public static function get_jobs(\WP_REST_Request $request) {
        $jobs = array_map(function (Job $job) {
            return $job->to_array();
        }, Job::get_all_open());

        return new \WP_REST_Response($jobs, 200);
    }

    public static function submit_application(\WP_REST_Request $request) {
        $first_name = $request->get_param('first_name');
        $last_name  = $request->get_param('last_name');
        $email      = $request->get_param('applicant_email');
        $phone      = $request->get_param('phone');
        $job_id     = $request->get_param('job_id');

        $job = Job::get_by_id($job_id);
        if (!$job || $job->get('status') !== 'open') {
            return new \WP_Error('massinc_invalid_position', 'This position is not open for applications.', ['status' => 400]);
        }

        // Validate files and links before writing anything, so a rejected
        // submission leaves no candidate or application behind.
        $files  = Attachments::collect($request->get_file_params());
        $links  = Attachments::normalize_links($request->get_param('sample_urls'));
        $errors = Attachments::validate($files, $links);
        if ($errors) {
            return new \WP_Error(
                'massinc_invalid_attachments',
                reset($errors),
                ['status' => 400, 'params' => $errors]
            );
        }

        // Reuse the candidate record if this applicant has applied before
        // with either the same email or the same name, flagging them as a
        // past applicant and refreshing their contact information.
        $candidate = Candidate::find_existing($email, $first_name, $last_name);
        $is_past_applicant = $candidate !== null;

        if ($candidate) {
            $candidate_id = $candidate->get_id();

            // One application per candidate per job. Checked before updating
            // the candidate so a rejected duplicate doesn't change their info.
            if (Application::exists_for($job->get_id(), $candidate_id)) {
                return new \WP_Error('massinc_duplicate_application', 'You have already applied to this position.', ['status' => 409]);
            }

            Candidate::update($candidate_id, [
                'first_name'        => $first_name,
                'last_name'         => $last_name,
                'email'             => $email,
                'phone'             => $phone,
                'is_past_applicant' => 1,
            ]);
        } else {
            $candidate_id = Candidate::create([
                'first_name'        => $first_name,
                'last_name'         => $last_name,
                'email'             => $email,
                'phone'             => $phone,
                'is_past_applicant' => 0,
            ]);
        }

        if (!$candidate_id) {
            return new \WP_Error('massinc_candidate_failed', 'Could not save your application. Please try again.', ['status' => 500]);
        }

        $application_id = Application::create([
            'job_id'       => $job->get_id(),
            'candidate_id' => $candidate_id,
        ]);

        if (!$application_id) {
            return new \WP_Error('massinc_application_failed', 'Could not save your application. Please try again.', ['status' => 500]);
        }

        $saved = Attachments::save_all($application_id, $files, $links);
        if (is_wp_error($saved)) {
            Attachments::purge($application_id);
            Application::delete($application_id);
            return $saved;
        }

        if ($is_past_applicant) {
            Attachments::replace_past_for_candidate($candidate_id, $application_id);
        }

        return new \WP_REST_Response([
            'status'         => 'submitted',
            'application_id' => $application_id,
        ], 201);
    }

    /**
     * POST /massinc/v1/staff-names: add a name to the "Select your name" list.
     * Returns 201 for a new name, 200 if it was already listed.
     */
    public static function add_staff_name(\WP_REST_Request $request) {
        $result = StaffNames::add($request->get_param('name'));
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response($result, $result['created'] ? 201 : 200);
    }

    /**
     * DELETE /massinc/v1/staff-names?name=...: take a name off the list. Only
     * the list changes; ratings and notes already written under the name stay.
     */
    public static function remove_staff_name(\WP_REST_Request $request) {
        return new \WP_REST_Response(StaffNames::remove($request->get_param('name')), 200);
    }

    /**
     * POST /massinc/v1/applications/{id}/ratings: rate an application as a
     * reviewer. Rating again as the same reviewer replaces their earlier score
     * (200); a first rating is created (201).
     */
    public static function save_rating(\WP_REST_Request $request) {
        $application = Application::get_by_id($request['id']);
        if (!$application) {
            return new \WP_Error('massinc_application_not_found', 'Application not found.', ['status' => 404]);
        }

        $reviewer = self::staff_name($request->get_param('reviewer'), 'Select your name first.');
        if (is_wp_error($reviewer)) {
            return $reviewer;
        }

        $result = Rating::save($application->get_id(), $reviewer, $request->get_param('score'));
        if (is_wp_error($result)) {
            return $result;
        }

        return new \WP_REST_Response([
            'reviewer' => $result['reviewer'],
            'score'    => $result['score'],
            'created'  => $result['created'],
            'date'     => mysql2date('j M Y', $result['updated_at']),
        ], $result['created'] ? 201 : 200);
    }

    /**
     * POST /massinc/v1/applications/{id}/stage: move an application to a
     * hiring stage. Administrators only.
     */
    public static function change_stage(\WP_REST_Request $request) {
        $application = Application::get_by_id($request['id']);
        if (!$application) {
            return new \WP_Error('massinc_application_not_found', 'Application not found.', ['status' => 404]);
        }

        $stage = sanitize_key((string) $request->get_param('stage'));
        $status = Application::status_for_stage($stage);
        if ($status === null) {
            return new \WP_Error('massinc_invalid_stage', 'Choose a stage from the list.', ['status' => 400]);
        }

        if (!Application::update_status($application->get_id(), $status)) {
            return new \WP_Error('massinc_stage_failed', 'Could not change the stage. Please try again.', ['status' => 500]);
        }

        return new \WP_REST_Response(['stage' => $stage, 'status' => $status], 200);
    }

    public static function can_change_stage() {
        if (current_user_can('edit_minc_applications')) {
            return true;
        }
        return new \WP_Error(
            'rest_forbidden',
            'Only admins can change stages.',
            ['status' => is_user_logged_in() ? 403 : 401]
        );
    }

    /**
     * POST /massinc/v1/applications/{id}/notes: add a note. Notes are
     * append-only: there is no route to edit or delete one yet.
     */
    public static function add_note(\WP_REST_Request $request) {
        $application = Application::get_by_id($request['id']);
        if (!$application) {
            return new \WP_Error('massinc_application_not_found', 'Application not found.', ['status' => 404]);
        }

        $author = self::staff_name($request->get_param('author'), 'Select your name first.');
        if (is_wp_error($author)) {
            return $author;
        }

        $text = trim(sanitize_textarea_field((string) $request->get_param('text')));
        if ($text === '') {
            return new \WP_Error('massinc_note_required', 'Write a note first.', ['status' => 400]);
        }
        if (mb_strlen($text) > Note::MAX_LENGTH) {
            return new \WP_Error(
                'massinc_note_too_long',
                sprintf('Notes can be up to %d characters.', Note::MAX_LENGTH),
                ['status' => 400]
            );
        }

        $note = Note::create($application->get_id(), $author, $text);
        if (is_wp_error($note)) {
            return $note;
        }

        return new \WP_REST_Response([
            'id'     => $note['id'],
            'author' => $note['author'],
            'text'   => $note['body'],
            'date'   => mysql2date('j M Y', $note['created_at']),
        ], 201);
    }

    /**
     * A reviewer or note-author name from the page: cleaned, spelled as on the
     * staff list when listed, and 100 characters at most.
     */
    private static function staff_name($raw, $blank_message) {
        $name = StaffNames::canonical($raw);
        if ($name === '') {
            return new \WP_Error('massinc_name_required', $blank_message, ['status' => 400]);
        }
        if (mb_strlen($name) > StaffNames::MAX_LENGTH) {
            return new \WP_Error(
                'massinc_name_too_long',
                sprintf('Names can be up to %d characters.', StaffNames::MAX_LENGTH),
                ['status' => 400]
            );
        }
        return $name;
    }

    public static function can_review_applications() {
        if (current_user_can('view_minc_applications')) {
            return true;
        }
        return new \WP_Error(
            'rest_forbidden',
            'You are not allowed to rate applications or add notes.',
            ['status' => is_user_logged_in() ? 403 : 401]
        );
    }

    public static function can_edit_staff_names() {
        if (current_user_can('view_minc_applications')) {
            return true;
        }
        return new \WP_Error(
            'rest_forbidden',
            'You are not allowed to change the staff list.',
            ['status' => is_user_logged_in() ? 403 : 401]
        );
    }

    public static function can_view_applications() {
        if (current_user_can('view_minc_applications')) {
            return true;
        }
        return new \WP_Error(
            'rest_forbidden',
            'You are not allowed to download application files.',
            ['status' => is_user_logged_in() ? 403 : 401]
        );
    }

    /**
     * Stream a stored attachment to staff. Files are never served by URL.
     */
    public static function download_attachment(\WP_REST_Request $request) {
        $attachment = Attachment::get_by_id($request['id']);
        $path = $attachment && !$attachment->is_link()
            ? Attachments::path_for($attachment->get('stored_name'))
            : '';

        if (!$path || !file_exists($path)) {
            return new \WP_Error('massinc_attachment_not_found', 'File not found.', ['status' => 404]);
        }

        $filename = $attachment->get('original_name') ?: 'attachment';
        $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        // Replace JSON serialization of this response with the raw file.
        add_filter('rest_pre_serve_request', function ($served) use ($attachment, $path, $filename, $ascii) {
            if ($served) {
                return $served;
            }
            header('Content-Type: ' . $attachment->get('mime_type'));
            header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''" . rawurlencode($filename));
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            readfile($path);
            return true;
        });

        return new \WP_REST_Response(null, 200);
    }
}
