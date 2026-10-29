<?php
/**
 * Standalone test runner for MassINC\Notifications (no wp-env required).
 *
 * Usage (Mock Mode - runs automated assertion suite):
 *   docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php tests/test-notifications-standalone.php
 *
 * Usage (Live Mode - sends a real test email via SendGrid API using .env):
 *   docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php tests/test-notifications-standalone.php --live --to=you@example.com
 */

define('ABSPATH', __DIR__ . '/../');
define('MINC_HIRING_PATH', dirname(__DIR__) . '/');
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');

// ---------------------------------------------------------------------------
// Lightweight WordPress Runtime & $wpdb Shim
// ---------------------------------------------------------------------------

class WP_Error {
    private $message;
    public function __construct($code = '', $message = '') {
        $this->message = $message;
    }
    public function get_error_message() {
        return $this->message;
    }
}

class MockWPDB {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $tables = [
        'wp_massinc_jobs'          => [],
        'wp_massinc_candidates'    => [],
        'wp_massinc_applications'  => [],
        'wp_massinc_notifications' => [],
    ];
    private $auto_increment = [
        'wp_massinc_jobs'          => 1,
        'wp_massinc_candidates'    => 1,
        'wp_massinc_applications'  => 1,
        'wp_massinc_notifications' => 1,
    ];

    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function prepare($query, ...$args) {
        return vsprintf(str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%f'], $query), $args);
    }

    public function insert($table, $data, $format = []) {
        if (!isset($this->tables[$table])) {
            $this->tables[$table] = [];
            $this->auto_increment[$table] = 1;
        }
        $id = $this->auto_increment[$table]++;
        $row = array_merge(['id' => $id], $data);
        $this->tables[$table][$id] = $row;
        $this->insert_id = $id;
        return 1;
    }

    public function update($table, $data, $where) {
        $id = $where['id'] ?? 0;
        if (isset($this->tables[$table][$id])) {
            $this->tables[$table][$id] = array_merge($this->tables[$table][$id], $data);
            return 1;
        }
        return false;
    }

    public function get_row($query, $output = OBJECT) {
        if (preg_match('/FROM\s+(\S+)\s+WHERE\s+id\s*=\s*(\d+)/i', $query, $m)) {
            $table = $m[1];
            $id    = (int) $m[2];
            $row   = $this->tables[$table][$id] ?? null;
            if (!$row) {
                return null;
            }
            return $output === ARRAY_A ? $row : (object) $row;
        }
        if (preg_match("/FROM\s+(\S+)\s+WHERE\s+email\s*=\s*'([^']+)'/i", $query, $m)) {
            $table = $m[1];
            $email = $m[2];
            foreach ($this->tables[$table] ?? [] as $row) {
                if (($row['email'] ?? '') === $email) {
                    return $output === ARRAY_A ? $row : (object) $row;
                }
            }
        }
        return null;
    }

    public function get_var($query) {
        if (stripos($query, 'SHOW TABLES LIKE') !== false) {
            return 'wp_massinc_notifications';
        }
        return null;
    }

    public function get_results($query, $output = OBJECT) {
        if (preg_match('/FROM\s+(\S+)/i', $query, $m)) {
            $table = $m[1];
            $rows  = array_values($this->tables[$table] ?? []);
            $rows  = array_reverse($rows);
            return array_map(function ($r) use ($output) {
                return $output === ARRAY_A ? $r : (object) $r;
            }, $rows);
        }
        return [];
    }
}

$GLOBALS['wpdb']             = new MockWPDB();
$GLOBALS['wp_actions']       = [];
$GLOBALS['wp_filters']       = [];
$GLOBALS['wp_options']       = [
    'admin_email' => 'admin@massinc.org',
    'gmt_offset'  => -4,
];
$GLOBALS['wp_cron_events']   = [];
$GLOBALS['http_requests']    = [];
$GLOBALS['mock_http_status'] = 202;
$GLOBALS['mock_http_body']   = '';
$GLOBALS['live_http_mode']   = false;
$GLOBALS['captured_errors']  = [];

function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['wp_actions'][$tag][$priority][] = [
        'callback'      => $callback,
        'accepted_args' => $accepted_args,
    ];
}

function do_action($tag, ...$args) {
    if (empty($GLOBALS['wp_actions'][$tag])) {
        return;
    }
    ksort($GLOBALS['wp_actions'][$tag]);
    foreach ($GLOBALS['wp_actions'][$tag] as $callbacks) {
        foreach ($callbacks as $entry) {
            call_user_func_array($entry['callback'], array_slice($args, 0, $entry['accepted_args']));
        }
    }
}

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['wp_filters'][$tag][$priority][] = [
        'callback'      => $callback,
        'accepted_args' => $accepted_args,
    ];
}

function apply_filters($tag, $value, ...$args) {
    if (empty($GLOBALS['wp_filters'][$tag])) {
        return $value;
    }
    ksort($GLOBALS['wp_filters'][$tag]);
    foreach ($GLOBALS['wp_filters'][$tag] as $callbacks) {
        foreach ($callbacks as $entry) {
            $all_args = array_merge([$value], $args);
            $value    = call_user_func_array($entry['callback'], array_slice($all_args, 0, $entry['accepted_args']));
        }
    }
    return $value;
}

function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$key] : $default;
}

function update_option($key, $value) {
    $GLOBALS['wp_options'][$key] = $value;
    return true;
}

function sanitize_text_field($str) {
    return trim(strip_tags((string) $str));
}

function sanitize_textarea_field($str) {
    return trim(strip_tags((string) $str));
}

function sanitize_key($key) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function sanitize_email($email) {
    return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL) ?: '';
}

function is_email($email) {
    return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false;
}

function wp_kses_post($str) {
    return (string) $str;
}

function esc_url_raw($url) {
    return filter_var(trim((string) $url), FILTER_SANITIZE_URL) ?: '';
}

function esc_html($str) {
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

function wp_strip_all_tags($str) {
    return trim(strip_tags((string) $str));
}

function absint($val) {
    return abs((int) $val);
}

function wp_parse_args($args, $defaults = []) {
    return array_merge($defaults, (array) $args);
}

function wp_json_encode($data) {
    return json_encode($data);
}

function current_time($type) {
    return gmdate('Y-m-d H:i:s');
}

function admin_url($path = '') {
    return 'http://localhost:8888/wp-admin/' . ltrim($path, '/');
}

function get_bloginfo($show = '') {
    return 'MassINC Hiring Portal';
}

function wp_next_scheduled($hook, $args = []) {
    $key = $hook . ':' . json_encode($args);
    return $GLOBALS['wp_cron_events'][$key] ?? false;
}

function wp_schedule_single_event($timestamp, $hook, $args = []) {
    $key = $hook . ':' . json_encode($args);
    $GLOBALS['wp_cron_events'][$key] = $timestamp;
    return true;
}

function is_wp_error($thing) {
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response) {
    return $response['response']['code'] ?? 0;
}

function wp_remote_retrieve_body($response) {
    return $response['body'] ?? '';
}

function wp_remote_post($url, $args = []) {
    $GLOBALS['http_requests'][] = [
        'url'  => $url,
        'args' => $args,
        'body' => json_decode($args['body'] ?? '{}', true),
    ];

    if (!empty($GLOBALS['live_http_mode'])) {
        $headers = [];
        foreach ($args['headers'] ?? [] as $k => $v) {
            $headers[] = "{$k}: {$v}";
        }
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $args['body'] ?? '',
                'timeout'       => $args['timeout'] ?? 15,
                'ignore_errors' => true,
            ],
        ]);
        $resp_body = @file_get_contents($url, false, $context);
        $status    = 0;
        if (!empty($http_response_header[0]) && preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($resp_body === false && $status === 0) {
            return new WP_Error('http_request_failed', 'Could not connect to SendGrid API.');
        }
        return [
            'response' => ['code' => $status],
            'body'     => (string) $resp_body,
        ];
    }

    return [
        'response' => ['code' => $GLOBALS['mock_http_status']],
        'body'     => $GLOBALS['mock_http_body'],
    ];
}

// ---------------------------------------------------------------------------
// Load Plugin Classes
// ---------------------------------------------------------------------------

require_once MINC_HIRING_PATH . 'inc/Models/Model.php';
require_once MINC_HIRING_PATH . 'inc/Models/Job.php';
require_once MINC_HIRING_PATH . 'inc/Models/Candidate.php';
require_once MINC_HIRING_PATH . 'inc/Models/Application.php';
require_once MINC_HIRING_PATH . 'inc/notifications.php';

use MassINC\Models\Application;
use MassINC\Models\Candidate;
use MassINC\Models\Job;
use MassINC\Notifications;

// ---------------------------------------------------------------------------
// Check for --live mode
// ---------------------------------------------------------------------------

$argv_list = $argv ?? [];
if (in_array('--live', $argv_list, true)) {
    $to_email = '';
    foreach ($argv_list as $arg) {
        if (strpos($arg, '--to=') === 0) {
            $to_email = substr($arg, 5);
        }
    }
    if (empty($to_email)) {
        fwrite(STDERR, "Usage: php tests/test-notifications-standalone.php --live --to=you@example.com\n");
        exit(1);
    }

    $GLOBALS['live_http_mode'] = true;
    Notifications::init();
    putenv("MASSINC_STAFF_NOTIFICATION_EMAIL={$to_email}");
    $_ENV['MASSINC_STAFF_NOTIFICATION_EMAIL'] = $to_email;

    $job_id       = Job::create(['title' => 'Software Engineering Fellow', 'location' => 'Boston, MA']);
    $candidate_id = Candidate::create(['first_name' => 'Test', 'last_name' => 'Applicant', 'email' => $to_email, 'phone' => '617-555-0199']);
    $app_id       = Application::create(['job_id' => $job_id, 'candidate_id' => $candidate_id]);

    $logs = Notifications::get_recent_logs(10);
    echo "Live SendGrid test complete for Application #{$app_id}.\n";
    foreach ($logs as $log) {
        echo sprintf(" - [%s] to=%s status=%s attempts=%d error=%s\n", $log->trigger_key, $log->recipient_email, $log->status, $log->attempts, $log->last_error ?: 'none');
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Automated Assertion Suite (Mock Mode)
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function assert_true($condition, $description) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$description}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$description}\n";
        $failed++;
    }
}

echo "=== MassINC Hiring Portal: Email Notifications Test Suite ===\n\n";

// Configure test environment variables
putenv('SENDGRID_API_KEY=SG.test_key_from_env_12345');
putenv('SENDGRID_FROM_EMAIL=careers@massinc.org');
putenv('SENDGRID_FROM_NAME=MassINC Careers');
putenv('MASSINC_STAFF_NOTIFICATION_EMAIL=staff1@massinc.org, staff2@massinc.org');
$_ENV['SENDGRID_API_KEY']                 = 'SG.test_key_from_env_12345';
$_ENV['SENDGRID_FROM_EMAIL']              = 'careers@massinc.org';
$_ENV['SENDGRID_FROM_NAME']               = 'MassINC Careers';
$_ENV['MASSINC_STAFF_NOTIFICATION_EMAIL'] = 'staff1@massinc.org, staff2@massinc.org';

Notifications::init();

// 1. Environment variables test
echo "1. Environment Variable Configuration\n";
assert_true(Notifications::get_api_key() === 'SG.test_key_from_env_12345', 'Reads SENDGRID_API_KEY from environment variable');
assert_true(Notifications::get_from_email() === 'careers@massinc.org', 'Reads SENDGRID_FROM_EMAIL from environment variable');
assert_true(Notifications::get_staff_recipient_emails() === ['staff1@massinc.org', 'staff2@massinc.org'], 'Parses comma-separated MASSINC_STAFF_NOTIFICATION_EMAIL recipients');

// 2. Application Submission Triggers (Applicant + Staff)
echo "\n2. Automatic Emails on Application Submission\n";
$GLOBALS['http_requests'] = [];
$GLOBALS['mock_http_status'] = 202;

$job_id = Job::create([
    'title'           => 'Policy Research Intern',
    'location'        => 'Boston, MA',
    'employment_type' => 'Internship',
]);
$candidate_id = Candidate::create([
    'first_name' => 'Jane',
    'last_name'  => 'Doe',
    'email'      => 'jane.doe@example.org',
    'phone'      => '617-555-0123',
]);
$application_id = Application::create([
    'job_id'       => $job_id,
    'candidate_id' => $candidate_id,
]);

assert_true(count($GLOBALS['http_requests']) === 3, 'Dispatches 1 applicant confirmation + 2 staff notification requests via SendGrid');

$applicant_req = $GLOBALS['http_requests'][0]['body'];
$staff_req_1   = $GLOBALS['http_requests'][1]['body'];
$staff_req_2   = $GLOBALS['http_requests'][2]['body'];

assert_true(
    ($applicant_req['personalizations'][0]['to'][0]['email'] ?? '') === 'jane.doe@example.org',
    'Applicant confirmation email is addressed to jane.doe@example.org'
);
assert_true(
    strpos($applicant_req['subject'] ?? '', 'Policy Research Intern') !== false,
    'Applicant confirmation subject interpolates {{job_title}}'
);
assert_true(
    strpos($applicant_req['content'][1]['value'] ?? '', 'Hi Jane,') !== false,
    'Applicant confirmation body interpolates {{first_name}}'
);
assert_true(
    ($staff_req_1['personalizations'][0]['to'][0]['email'] ?? '') === 'staff1@massinc.org' &&
    ($staff_req_2['personalizations'][0]['to'][0]['email'] ?? '') === 'staff2@massinc.org',
    'Staff notifications are sent to all configured staff emails'
);
assert_true(
    strpos($staff_req_1['subject'] ?? '', 'Jane Doe for Policy Research Intern') !== false,
    'Staff notification subject interpolates {{applicant_name}} and {{job_title}}'
);

// 3. Editable Email Templates Without Code Changes
echo "\n3. Editable Email Templates (No Code Changes Required)\n";
Notifications::save_templates([
    'application_submitted_applicant' => [
        'enabled'              => true,
        'subject'              => 'Custom Subject for {{applicant_name}} (#{{application_id}})',
        'body'                 => '<p>Custom body for {{job_title}}</p>',
        'sendgrid_template_id' => 'd-customtemplate12345',
    ],
    'application_submitted_staff' => [
        'enabled'              => false, // disable staff trigger to verify toggle
        'subject'              => 'Staff Subject',
        'body'                 => '<p>Staff Body</p>',
        'sendgrid_template_id' => '',
    ],
]);

$GLOBALS['http_requests'] = [];
$app_id_2 = Application::create([
    'job_id'       => $job_id,
    'candidate_id' => $candidate_id,
]);

assert_true(count($GLOBALS['http_requests']) === 1, 'Respects template enabled/disabled toggle saved in options');
$custom_req = $GLOBALS['http_requests'][0]['body'];
assert_true(
    ($custom_req['subject'] ?? '') === "Custom Subject for Jane Doe (#{$app_id_2})",
    'Uses updated template subject from wp_options without code changes'
);
assert_true(
    ($custom_req['template_id'] ?? '') === 'd-customtemplate12345' &&
    ($custom_req['personalizations'][0]['dynamic_template_data']['job_title'] ?? '') === 'Policy Research Intern',
    'Passes SendGrid Dynamic Template ID and dynamic_template_data when configured'
);

// Restore default templates for retry tests
$GLOBALS['wp_options'][Notifications::OPTION_TEMPLATES] = [];

// 4. Delivery Failure Logging & Automatic Retry Handling
echo "\n4. Delivery Failure Logging & Retry Handling\n";
$GLOBALS['http_requests']    = [];
$GLOBALS['wp_cron_events']   = [];
$GLOBALS['mock_http_status'] = 503;
$GLOBALS['mock_http_body']   = json_encode(['errors' => [['message' => 'Service temporarily unavailable']]]);

// Restrict to 1 staff email for simpler counting
putenv('MASSINC_STAFF_NOTIFICATION_EMAIL=staff1@massinc.org');
$_ENV['MASSINC_STAFF_NOTIFICATION_EMAIL'] = 'staff1@massinc.org';

$app_id_3 = Application::create([
    'job_id'       => $job_id,
    'candidate_id' => $candidate_id,
]);

$logs = Notifications::get_recent_logs(2);
$latest_log = $logs[0];

assert_true(
    $latest_log->status === 'retrying' && (int) $latest_log->attempts === 1,
    'Marks failed delivery as status="retrying" with attempts=1'
);
assert_true(
    strpos((string) $latest_log->last_error, 'SendGrid HTTP 503: Service temporarily unavailable') !== false,
    'Logs detailed SendGrid error message on failure'
);
assert_true(
    wp_next_scheduled(Notifications::CRON_RETRY_HOOK, [(int) $latest_log->id]) !== false,
    'Schedules automatic WP-Cron retry event with exponential backoff'
);

// Now simulate SendGrid recovering (HTTP 202) and execute the scheduled retry
$GLOBALS['mock_http_status'] = 202;
$GLOBALS['mock_http_body']   = '';
$retry_result = Notifications::retry_notification((int) $latest_log->id);

$updated_row = $GLOBALS['wpdb']->get_row("SELECT * FROM wp_massinc_notifications WHERE id = {$latest_log->id}");
assert_true(
    $retry_result === true && $updated_row->status === 'sent' && (int) $updated_row->attempts === 2 && $updated_row->last_error === null,
    'Retry succeeds when SendGrid recovers and transitions status to "sent" (attempts=2)'
);

// 5. Exhausting Max Retries -> Permanent Failure
echo "\n5. Max Retries Exhaustion\n";
$GLOBALS['mock_http_status'] = 500;
$GLOBALS['mock_http_body']   = json_encode(['errors' => [['message' => 'Internal server error']]]);

$failing_notif_id = Notifications::dispatch_trigger(
    'application_submitted_applicant',
    'jane.doe@example.org',
    Notifications::build_application_context($app_id_3),
    $app_id_3,
    'Jane Doe'
);
// Attempt 2
Notifications::retry_notification($failing_notif_id);
// Attempt 3 (max attempts = 3)
Notifications::retry_notification($failing_notif_id);

$exhausted_row = $GLOBALS['wpdb']->get_row("SELECT * FROM wp_massinc_notifications WHERE id = {$failing_notif_id}");
assert_true(
    $exhausted_row->status === 'failed' && (int) $exhausted_row->attempts === 3 && $exhausted_row->next_retry_at === null,
    'Marks notification as status="failed" and stops scheduling retries after 3 failed attempts'
);

// 6. Extensibility: Adding a New Trigger
echo "\n6. Adding a Custom Email Trigger\n";
$GLOBALS['mock_http_status'] = 202;
Notifications::register_trigger('application_status_interview', [
    'label'          => 'Applicant Interview Invitation',
    'recipient_type' => 'applicant',
    'enabled'        => true,
    'subject'        => 'Interview Invitation: {{job_title}}',
    'body'           => '<p>Hi {{first_name}}, you are invited to interview!</p>',
]);

$custom_notif_id = Notifications::dispatch_trigger(
    'application_status_interview',
    'jane.doe@example.org',
    Notifications::build_application_context($app_id_3),
    $app_id_3,
    'Jane Doe'
);
$custom_row = $GLOBALS['wpdb']->get_row("SELECT * FROM wp_massinc_notifications WHERE id = {$custom_notif_id}");
assert_true(
    $custom_row && $custom_row->status === 'sent' && $custom_row->subject === 'Interview Invitation: Policy Research Intern',
    'Custom registered trigger dispatches and interpolates variables accurately'
);

echo "\n=============================================================\n";
echo "Results: {$passed} passed, {$failed} failed.\n";
echo "=============================================================\n";

exit($failed > 0 ? 1 : 0);
