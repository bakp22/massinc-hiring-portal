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
                'applicant_name' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ($value) {
                        return is_string($value) && trim($value) !== '';
                    },
                ],
                'applicant_email' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                    'validate_callback' => function ($value) {
                        return is_string($value) && is_email($value);
                    },
                ],
                'position_id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value > 0;
                    },
                ],
            ],
        ]);
    }

    public static function get_jobs(\WP_REST_Request $request) {
        $jobs = array_map(function (Job $job) {
            return $job->to_array();
        }, Job::get_all_open());

        return new \WP_REST_Response($jobs, 200);
    }

    public static function submit_application(\WP_REST_Request $request) {
        $name     = $request->get_param('applicant_name');
        $email    = $request->get_param('applicant_email');
        $position = $request->get_param('position_id');

        $job = Job::get_by_id($position);
        if (!$job || $job->get('status') !== 'open') {
            return new \WP_Error('massinc_invalid_position', 'This position is not open for applications.', ['status' => 400]);
        }

        // Reuse the candidate record if this email has applied before.
        $candidate = Candidate::get_by_email($email);
        if ($candidate) {
            $candidate_id = $candidate->get_id();
        } else {
            // The candidates table stores first/last name separately; split on the first space.
            $parts = preg_split('/\s+/', $name, 2);
            $candidate_id = Candidate::create([
                'first_name' => $parts[0],
                'last_name'  => $parts[1] ?? '',
                'email'      => $email,
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
