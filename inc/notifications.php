<?php
namespace MassINC;

use MassINC\Models\Application;
use MassINC\Models\Candidate;
use MassINC\Models\Job;

defined('ABSPATH') || exit;

class Notifications {
    const OPTION_TEMPLATES   = 'minc_email_templates';
    const OPTION_STAFF_EMAIL = 'minc_staff_notification_email';
    const RESEND_ENDPOINT    = 'https://api.resend.com/emails';
    const MAX_ATTEMPTS       = 3;
    const CRON_RETRY_HOOK    = 'massinc_retry_failed_email';

    /**
     * @var array<string, array> Registered email triggers.
     */
    protected static $triggers = [];

    /**
     * @var bool Whether local .env has been loaded.
     */
    protected static $env_loaded = false;

    /**
     * Initialize notification hooks, default triggers, and retry handlers.
     */
    public static function init() {
        self::load_dotenv();
        self::register_default_triggers();

        add_action('massinc_application_submitted', [self::class, 'handle_application_submitted'], 10, 2);
        add_action(self::CRON_RETRY_HOOK, [self::class, 'retry_notification'], 10, 1);
    }

    /**
     * Register the built-in application submission triggers.
     */
    public static function register_default_triggers() {
        self::register_trigger('application_submitted_applicant', [
            'label'          => 'Applicant Confirmation (On Submission)',
            'description'    => 'Sent to the candidate immediately after they complete an application.',
            'recipient_type' => 'applicant',
            'enabled'        => true,
            'subject'        => 'Application Received: {{job_title}} — MassINC',
            'body'           => "<p>Hi {{first_name}},</p>\n<p>Thank you for applying for the <strong>{{job_title}}</strong> position at MassINC.</p>\n<p>We have received your application (Reference #{{application_id}}) submitted on {{submitted_at}}. Our hiring team will review your materials and follow up with you regarding next steps.</p>\n<p>Best regards,<br>The MassINC Hiring Team</p>",
        ]);

        self::register_trigger('application_submitted_staff', [
            'label'          => 'Employer / Staff Notification (New Application)',
            'description'    => 'Sent to employers/hiring staff whenever a candidate applies to a position.',
            'recipient_type' => 'staff',
            'enabled'        => true,
            'subject'        => 'New Application: {{applicant_name}} has applied to {{job_title}}',
            'body'           => "<p><strong>{{applicant_name}}</strong> has applied to <strong>{{job_title}}</strong> on the MassINC Hiring Portal.</p>\n<ul>\n<li><strong>Position:</strong> {{job_title}} ({{job_location}})</li>\n<li><strong>Applicant:</strong> {{applicant_name}}</li>\n<li><strong>Email:</strong> {{candidate_email}}</li>\n<li><strong>Phone:</strong> {{candidate_phone}}</li>\n<li><strong>Application ID:</strong> #{{application_id}}</li>\n<li><strong>Submitted At:</strong> {{submitted_at}}</li>\n</ul>\n<p><a href=\"{{admin_applications_url}}\">View Applications in WordPress Admin</a></p>",
        ]);
    }

    /**
     * Register a new email notification trigger.
     *
     * @param string $trigger_key Unique identifier for the trigger.
     * @param array  $config      Trigger configuration (label, description, recipient_type, subject, body, etc.).
     */
    public static function register_trigger($trigger_key, array $config) {
        $trigger_key = sanitize_key($trigger_key);
        if (empty($trigger_key)) {
            return;
        }

        self::$triggers[$trigger_key] = wp_parse_args($config, [
            'label'          => ucwords(str_replace('_', ' ', $trigger_key)),
            'description'    => '',
            'recipient_type' => 'applicant',
            'enabled'        => true,
            'subject'        => '',
            'body'           => '',
        ]);
    }

    /**
     * Return all registered triggers merged with saved template overrides from wp_options.
     *
     * @return array<string, array>
     */
    public static function get_triggers() {
        if (empty(self::$triggers)) {
            self::register_default_triggers();
        }

        $triggers = apply_filters('massinc_email_triggers', self::$triggers);
        $saved    = get_option(self::OPTION_TEMPLATES, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        foreach ($triggers as $key => $default_config) {
            if (isset($saved[$key]) && is_array($saved[$key])) {
                $triggers[$key] = array_merge($default_config, [
                    'enabled' => !empty($saved[$key]['enabled']),
                    'subject' => isset($saved[$key]['subject']) ? (string) $saved[$key]['subject'] : $default_config['subject'],
                    'body'    => isset($saved[$key]['body']) ? (string) $saved[$key]['body'] : $default_config['body'],
                ]);
            }
        }

        return $triggers;
    }

    /**
     * Retrieve a single trigger's resolved template configuration.
     *
     * @param string $trigger_key
     * @return array|null
     */
    public static function get_template($trigger_key) {
        $triggers = self::get_triggers();
        return $triggers[$trigger_key] ?? null;
    }

    /**
     * Save editable email templates to wp_options (no code changes needed).
     *
     * @param array $templates_input
     * @return bool
     */
    public static function save_templates(array $templates_input) {
        $triggers  = self::get_triggers();
        $sanitized = [];

        foreach ($triggers as $key => $config) {
            if (!isset($templates_input[$key]) || !is_array($templates_input[$key])) {
                continue;
            }
            $input = $templates_input[$key];
            $sanitized[$key] = [
                'enabled' => !empty($input['enabled']),
                'subject' => sanitize_text_field($input['subject'] ?? $config['subject']),
                'body'    => wp_kses_post($input['body'] ?? $config['body']),
            ];
        }

        return update_option(self::OPTION_TEMPLATES, $sanitized);
    }

    /**
     * Build template variables for a given application ID.
     *
     * @param int $application_id
     * @return array|null
     */
    public static function build_application_context($application_id) {
        $application = Application::get_by_id($application_id);
        if (!$application) {
            return null;
        }

        $candidate = Candidate::get_by_id($application->get_candidate_id());
        $job       = Job::get_by_id($application->get_job_id());

        if (!$candidate || !$job) {
            return null;
        }

        $first_name = (string) $candidate->get('first_name', '');
        $last_name  = (string) $candidate->get('last_name', '');
        $full_name  = $candidate->get_name() ?: 'Applicant';

        return [
            'application_id'         => (string) $application->get_id(),
            'status'                 => (string) $application->get_status(),
            'submitted_at'           => (string) $application->get('created_at', current_time('mysql')),
            'resume_url'             => (string) $application->get('resume_url', ''),
            'cover_letter_url'       => (string) $application->get('cover_letter_url', ''),
            'first_name'             => $first_name ?: $full_name,
            'last_name'              => $last_name,
            'applicant_name'         => $full_name,
            'candidate_email'        => $candidate->get_email(),
            'candidate_phone'        => (string) $candidate->get('phone', 'N/A'),
            'job_id'                 => (string) $job->get_id(),
            'job_title'              => (string) $job->get('title', 'Open Position'),
            'job_location'           => (string) $job->get('location', 'N/A'),
            'employment_type'        => (string) $job->get('employment_type', ''),
            'admin_applications_url' => admin_url('admin.php?page=minc-hiring-applications'),
            'site_name'              => get_bloginfo('name') ?: 'MassINC',
        ];
    }

    /**
     * Hook callback for `massinc_application_submitted`.
     * Sends confirmation email to the applicant and notification email to staff.
     *
     * @param int   $application_id
     * @param array $application_data
     */
    public static function handle_application_submitted($application_id, $application_data = []) {
        $context = self::build_application_context($application_id);
        if (!$context) {
            self::log_error(sprintf(
                'Cannot send submission emails for application #%d: missing application, candidate, or job record.',
                (int) $application_id
            ));
            return;
        }

        // 1. Send confirmation email to applicant
        if (!empty($context['candidate_email']) && is_email($context['candidate_email'])) {
            self::dispatch_trigger(
                'application_submitted_applicant',
                $context['candidate_email'],
                $context,
                (int) $application_id,
                $context['applicant_name']
            );
        }

        // 2. Send notification email to each configured staff recipient
        $staff_emails = self::get_staff_recipient_emails();
        foreach ($staff_emails as $staff_email) {
            self::dispatch_trigger(
                'application_submitted_staff',
                $staff_email,
                $context,
                (int) $application_id,
                'MassINC Hiring Staff'
            );
        }
    }

    /**
     * Dispatch a registered email trigger to a recipient with template variable interpolation.
     *
     * @param string   $trigger_key
     * @param string   $recipient_email
     * @param array    $variables
     * @param int|null $application_id
     * @param string   $recipient_name
     * @return int|false Notification log ID on creation, or false if skipped.
     */
    public static function dispatch_trigger($trigger_key, $recipient_email, array $variables = [], $application_id = null, $recipient_name = '') {
        $template = self::get_template($trigger_key);
        if (!$template || empty($template['enabled'])) {
            return false;
        }

        $recipient_email = sanitize_email($recipient_email);
        if (empty($recipient_email) || !is_email($recipient_email)) {
            self::log_error(sprintf('Invalid recipient email for trigger "%s".', $trigger_key));
            return false;
        }

        $subject   = self::interpolate($template['subject'], $variables);
        $html_body = self::interpolate($template['body'], $variables);

        $payload = [
            'to_email'  => $recipient_email,
            'to_name'   => sanitize_text_field($recipient_name),
            'subject'   => $subject,
            'html_body' => $html_body,
            'variables' => $variables,
        ];

        $notification_id = self::create_notification_log(
            $trigger_key,
            $recipient_email,
            $subject,
            $payload,
            $application_id
        );

        self::attempt_delivery($notification_id, $trigger_key, $payload);

        return $notification_id;
    }

    /**
     * Replace `{{variable}}` placeholders in a template string.
     *
     * @param string $template
     * @param array  $variables
     * @return string
     */
    public static function interpolate($template, array $variables) {
        $replacements = [];
        foreach ($variables as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{{' . $key . '}}'] = (string) $value;
                $replacements['{{ ' . $key . ' }}'] = (string) $value;
            }
        }
        return strtr((string) $template, $replacements);
    }

    /**
     * Attempt to send an email via Resend API and record the result / schedule retry on failure.
     *
     * @param int    $notification_id
     * @param string $trigger_key
     * @param array  $payload
     * @param int    $current_attempts
     * @return bool True on successful delivery, false on failure.
     */
    public static function attempt_delivery($notification_id, $trigger_key, array $payload, $current_attempts = 0) {
        $attempt = (int) $current_attempts + 1;
        $result  = self::send_via_resend($payload);

        if ($result['success']) {
            self::update_notification_log($notification_id, [
                'status'        => 'sent',
                'attempts'      => $attempt,
                'last_error'    => null,
                'next_retry_at' => null,
                'sent_at'       => current_time('mysql'),
            ]);
            return true;
        }

        $max_attempts = (int) apply_filters('massinc_email_max_retries', self::MAX_ATTEMPTS);
        $error_msg    = $result['error'] ?? 'Unknown Resend delivery error.';

        self::log_error(sprintf(
            'Email delivery failed [trigger=%s, to=%s, attempt=%d/%d]: %s',
            $trigger_key,
            $payload['to_email'] ?? '',
            $attempt,
            $max_attempts,
            $error_msg
        ));

        if ($attempt < $max_attempts) {
            // Exponential backoff: 60s (1 min), 180s (3 min), 540s (9 min)
            $delay_seconds = (int) apply_filters('massinc_email_retry_delay_seconds', 60 * pow(3, $attempt - 1), $attempt);
            $next_retry_ts = time() + $delay_seconds;
            $next_retry_at = gmdate('Y-m-d H:i:s', $next_retry_ts + (int) (get_option('gmt_offset') * HOUR_IN_SECONDS));

            self::update_notification_log($notification_id, [
                'status'        => 'retrying',
                'attempts'      => $attempt,
                'last_error'    => $error_msg,
                'next_retry_at' => $next_retry_at,
            ]);

            if ($notification_id > 0 && !wp_next_scheduled(self::CRON_RETRY_HOOK, [$notification_id])) {
                wp_schedule_single_event($next_retry_ts, self::CRON_RETRY_HOOK, [$notification_id]);
            }
        } else {
            self::update_notification_log($notification_id, [
                'status'        => 'failed',
                'attempts'      => $attempt,
                'last_error'    => $error_msg,
                'next_retry_at' => null,
            ]);
        }

        do_action('massinc_email_delivery_failed', $notification_id, $trigger_key, $payload, $error_msg, $attempt);

        return false;
    }

    /**
     * Retry a previously failed/retrying notification by its database ID.
     *
     * @param int $notification_id
     * @return bool
     */
    public static function retry_notification($notification_id) {
        global $wpdb;
        $notification_id = absint($notification_id);
        if (!$notification_id) {
            return false;
        }

        $table = $wpdb->prefix . 'massinc_notifications';
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $notification_id),
            ARRAY_A
        );

        if (!$row || $row['status'] === 'sent') {
            return false;
        }

        $payload = json_decode($row['payload'], true);
        if (!is_array($payload)) {
            self::update_notification_log($notification_id, [
                'status'     => 'failed',
                'last_error' => 'Corrupted notification payload JSON; cannot retry.',
            ]);
            return false;
        }

        return self::attempt_delivery(
            $notification_id,
            $row['trigger_key'],
            $payload,
            (int) $row['attempts']
        );
    }

    /**
     * Send an email request through the Resend Emails API.
     *
     * @param array $payload
     * @return array{success: bool, id?: string|null, error?: string, status_code?: int}
     */
    public static function send_via_resend(array $payload) {
        $api_key = self::get_api_key();
        if (empty($api_key)) {
            return [
                'success' => false,
                'error'   => 'Missing RESEND_API_KEY environment variable.',
            ];
        }

        $from_email = self::get_from_email();
        $from_name  = self::get_from_name();
        $to_email   = $payload['to_email'] ?? '';
        $subject    = $payload['subject'] ?? '';
        $html_body  = $payload['html_body'] ?? '';

        $from_header = !empty($from_name) ? sprintf('%s <%s>', $from_name, $from_email) : $from_email;
        $plain_text  = wp_strip_all_tags($html_body);

        $body = [
            'from'    => $from_header,
            'to'      => [$to_email],
            'subject' => $subject,
            'html'    => $html_body ?: '<p>' . esc_html($subject) . '</p>',
            'text'    => $plain_text ?: $subject,
        ];

        $response = wp_remote_post(self::RESEND_ENDPOINT, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => 'WP_Error: ' . $response->get_error_message(),
            ];
        }

        $status_code   = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);
        $decoded       = !empty($response_body) ? json_decode($response_body, true) : null;

        // Resend returns 200 OK with {"id": "..."} on success
        if ($status_code >= 200 && $status_code < 300) {
            return [
                'success'     => true,
                'status_code' => $status_code,
                'id'          => is_array($decoded) ? ($decoded['id'] ?? null) : null,
            ];
        }

        $error_detail = sprintf('Resend HTTP %d', $status_code);
        if (!empty($response_body)) {
            if (is_array($decoded) && !empty($decoded['message'])) {
                $error_detail .= ': ' . $decoded['message'];
            } else {
                $error_detail .= ': ' . substr($response_body, 0, 300);
            }
        }

        return [
            'success'     => false,
            'status_code' => $status_code,
            'error'       => $error_detail,
        ];
    }

    /**
     * Insert a new record into wp_massinc_notifications.
     *
     * @param string   $trigger_key
     * @param string   $recipient_email
     * @param string   $subject
     * @param array    $payload
     * @param int|null $application_id
     * @return int Inserted notification ID (0 if DB insert failed).
     */
    protected static function create_notification_log($trigger_key, $recipient_email, $subject, array $payload, $application_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'massinc_notifications';
        $now   = current_time('mysql');

        $data = [
            'trigger_key'     => sanitize_key($trigger_key),
            'recipient_email' => sanitize_email($recipient_email),
            'subject'         => sanitize_text_field($subject),
            'payload'         => wp_json_encode($payload),
            'status'          => 'pending',
            'attempts'        => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];
        $format = ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s'];

        if (!empty($application_id)) {
            $data['application_id'] = absint($application_id);
            $format[]               = '%d';
        }

        $inserted = $wpdb->insert($table, $data, $format);
        if ($inserted === false) {
            // Ensure table exists in case plugin was updated without re-activation
            Database::create_tables();
            $inserted = $wpdb->insert($table, $data, $format);
        }

        return $inserted !== false ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Update an existing record in wp_massinc_notifications.
     *
     * @param int   $notification_id
     * @param array $fields
     */
    protected static function update_notification_log($notification_id, array $fields) {
        global $wpdb;
        $notification_id = absint($notification_id);
        if (!$notification_id) {
            return;
        }

        $table = $wpdb->prefix . 'massinc_notifications';
        $fields['updated_at'] = current_time('mysql');

        $wpdb->update($table, $fields, ['id' => $notification_id]);
    }

    /**
     * Retrieve recent notification logs for display in WP Admin.
     *
     * @param int $limit
     * @return array
     */
    public static function get_recent_logs($limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'massinc_notifications';
        $limit = max(1, min(200, absint($limit)));

        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$table_exists) {
            return [];
        }

        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit)
        ) ?: [];
    }

    /**
     * Resolve the Resend API key strictly from environment variables (never hardcoded).
     *
     * @return string
     */
    public static function get_api_key() {
        return self::get_env('RESEND_API_KEY', '');
    }

    /**
     * Resolve sender email address from environment variable or WordPress admin email fallback.
     *
     * @return string
     */
    public static function get_from_email() {
        $env_from = self::get_env('RESEND_FROM_EMAIL', '');
        if (!empty($env_from) && is_email($env_from)) {
            return $env_from;
        }
        $admin_email = get_option('admin_email');
        return is_email($admin_email) ? $admin_email : 'no-reply@massinc.org';
    }

    /**
     * Resolve sender display name from environment variable or site title fallback.
     *
     * @return string
     */
    public static function get_from_name() {
        $env_name = self::get_env('RESEND_FROM_NAME', '');
        if (!empty($env_name)) {
            return $env_name;
        }
        return get_bloginfo('name') ?: 'MassINC Hiring Portal';
    }

    /**
     * Resolve staff notification recipient email(s).
     * Checks MASSINC_STAFF_NOTIFICATION_EMAIL env var, then WP option, then admin_email.
     *
     * @return string[]
     */
    public static function get_staff_recipient_emails() {
        $raw = self::get_env('MASSINC_STAFF_NOTIFICATION_EMAIL', '');
        if (empty($raw)) {
            $raw = (string) get_option(self::OPTION_STAFF_EMAIL, '');
        }
        if (empty($raw)) {
            $raw = (string) get_option('admin_email', '');
        }

        $parts  = array_map('trim', explode(',', $raw));
        $emails = [];
        foreach ($parts as $email) {
            $clean = sanitize_email($email);
            if (!empty($clean) && is_email($clean)) {
                $emails[] = $clean;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Read an environment variable from getenv(), $_ENV, $_SERVER, or a matching PHP constant.
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    public static function get_env($name, $default = '') {
        self::load_dotenv();

        $val = getenv($name);
        if ($val !== false && $val !== '') {
            return trim((string) $val);
        }

        if (!empty($_ENV[$name])) {
            return trim((string) $_ENV[$name]);
        }

        if (!empty($_SERVER[$name])) {
            return trim((string) $_SERVER[$name]);
        }

        if (defined($name)) {
            return trim((string) constant($name));
        }

        return $default;
    }

    /**
     * Load key=value pairs from the plugin's local `.env` file if present, without overwriting existing env vars.
     */
    protected static function load_dotenv() {
        if (self::$env_loaded) {
            return;
        }
        self::$env_loaded = true;

        $env_path = defined('MINC_HIRING_PATH') ? MINC_HIRING_PATH . '.env' : dirname(__DIR__) . '/.env';
        if (!is_readable($env_path)) {
            return;
        }

        $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            if (
                (strlen($value) >= 2) &&
                (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '' && getenv($key) === false && !isset($_ENV[$key])) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * Log delivery errors to PHP error_log.
     *
     * @param string $message
     */
    protected static function log_error($message) {
        error_log('[MassINC Hiring Portal] ' . $message);
    }
}
