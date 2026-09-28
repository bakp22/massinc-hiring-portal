<?php
namespace MassINC;

use MassINC\Models\Application;
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

        // Reuse the candidate record if this email has applied before,
        // refreshing their name/phone in case it changed since last time.
        $candidate = Candidate::get_by_email($email);
        if ($candidate) {
            $candidate_id = $candidate->get_id();
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

        return new \WP_REST_Response([
            'status'         => 'submitted',
            'application_id' => $application_id,
        ], 201);
    }
}
