<?php
namespace MassINC;

use MassINC\Models\Application;
use MassINC\Models\Attachment;
use MassINC\Models\Candidate;
use MassINC\Models\Job;

defined('ABSPATH') || exit;

class API {
    public static function register_routes() {
        register_rest_route('massinc/v1', '/jobs', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'get_jobs'],
            'permission_callback' => '__return_true',
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

        // Reuse the candidate record if this email has applied before,
        // refreshing their name/phone in case it changed since last time.
        $candidate = Candidate::get_by_email($email);
        if ($candidate) {
            $candidate_id = $candidate->get_id();

            // One application per candidate per job. Checked before updating
            // the candidate so a rejected duplicate doesn't change their info.
            if (Application::exists_for($job->get_id(), $candidate_id)) {
                return new \WP_Error('massinc_duplicate_application', 'You have already applied to this position.', ['status' => 409]);
            }

            Candidate::update($candidate_id, [
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'phone'      => $phone,
            ]);
        } else {
            $candidate_id = Candidate::create([
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
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

        return new \WP_REST_Response([
            'status'         => 'submitted',
            'application_id' => $application_id,
        ], 201);
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
