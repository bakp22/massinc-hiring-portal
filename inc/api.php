<?php
namespace MassINC;

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
        ]);
    }

    public static function get_jobs(\WP_REST_Request $request) {
        // TODO (Engineer 2): Fetch open positions via Job model and return WP_REST_Response
        return new \WP_REST_Response([], 200);
    }

    public static function submit_application(\WP_REST_Request $request) {
        // TODO (Engineer 2): Sanitize inputs, handle candidate creation & application submission
        return new \WP_REST_Response(['status' => 'not_implemented'], 501);
    }
}

add_action('rest_api_init', ['MassINC\API', 'register_routes']);
