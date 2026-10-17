<?php
defined('ABSPATH') || exit;

function minc_hiring_register_admin() {
    add_action('admin_menu', function () {
        add_menu_page(
            'Hiring',
            'Hiring',
            'manage_minc_hiring',
            'minc-hiring',
            'minc_hiring_admin_jobs_page',
            'dashicons-businessperson',
            25
        );

        add_submenu_page('minc-hiring', 'Jobs', 'Jobs', 'manage_minc_hiring', 'minc-hiring', 'minc_hiring_admin_jobs_page');
        add_submenu_page('minc-hiring', 'Applications', 'Applications', 'view_minc_applications', 'minc-hiring-applications', 'minc_hiring_admin_applications_page');
        add_submenu_page('minc-hiring', 'Email Notifications', 'Email Notifications', 'manage_minc_hiring', 'minc-hiring-emails', 'minc_hiring_admin_emails_page');
    });
}

function minc_hiring_admin_jobs_page() {
    if (!minc_hiring_can_manage()) {
        wp_die('You do not have permission to manage hiring.');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'minc_jobs';

    if (
        isset($_POST['minc_hiring_action'], $_POST['_wpnonce']) &&
        $_POST['minc_hiring_action'] === 'create_job' &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'minc_create_job')
    ) {
        $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
        $description = wp_kses_post(wp_unslash($_POST['description'] ?? ''));
        $location = sanitize_text_field(wp_unslash($_POST['location'] ?? ''));
        $employment_type = sanitize_text_field(wp_unslash($_POST['employment_type'] ?? ''));

        if ($title !== '') {
            $now = current_time('mysql');
            $wpdb->insert($table, [
                'title' => $title,
                'description' => $description,
                'location' => $location,
                'employment_type' => $employment_type,
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ], ['%s','%s','%s','%s','%s','%s','%s']);
            echo '<div class="notice notice-success"><p>Job created.</p></div>';
        }
    }

    $jobs = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

    ?>
    <div class="wrap">
        <h1>Hiring — Jobs</h1>

        <h2>Add Job</h2>
        <form method="post">
            <?php wp_nonce_field('minc_create_job'); ?>
            <input type="hidden" name="minc_hiring_action" value="create_job">

            <p><label>Title<br><input class="regular-text" name="title" required></label></p>
            <p><label>Location<br><input class="regular-text" name="location"></label></p>
            <p><label>Employment type<br><input class="regular-text" name="employment_type" placeholder="Internship, Full-time, etc."></label></p>
            <p><label>Description<br><textarea class="large-text" rows="7" name="description"></textarea></label></p>

            <p><button class="button button-primary">Create Job</button></p>
        </form>

        <h2>Existing Jobs</h2>
        <table class="widefat striped">
            <thead><tr><th>Title</th><th>Location</th><th>Status</th><th>Created</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><?php echo esc_html($job->title); ?></td>
                    <td><?php echo esc_html($job->location); ?></td>
                    <td><?php echo esc_html($job->status); ?></td>
                    <td><?php echo esc_html($job->created_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function minc_hiring_admin_applications_page() {
    if (!current_user_can('view_minc_applications')) {
        wp_die('You do not have permission to view applications.');
    }

    global $wpdb;
    $applications = $wpdb->get_results(
        "SELECT a.*, j.title AS job_title,
                CONCAT(c.first_name, ' ', c.last_name) AS candidate_name,
                c.email AS candidate_email
         FROM {$wpdb->prefix}minc_applications a
         JOIN {$wpdb->prefix}minc_jobs j ON j.id = a.job_id
         JOIN {$wpdb->prefix}minc_candidates c ON c.id = a.candidate_id
         ORDER BY a.created_at DESC"
    );
    ?>
    <div class="wrap">
        <h1>Hiring — Applications</h1>
        <table class="widefat striped">
            <thead>
                <tr><th>Candidate</th><th>Email</th><th>Job</th><th>Status</th><th>Submitted</th></tr>
            </thead>
            <tbody>
            <?php foreach ($applications as $application): ?>
                <tr>
                    <td><?php echo esc_html($application->candidate_name); ?></td>
                    <td><?php echo esc_html($application->candidate_email); ?></td>
                    <td><?php echo esc_html($application->job_title); ?></td>
                    <td><?php echo esc_html($application->status); ?></td>
                    <td><?php echo esc_html($application->created_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function minc_hiring_admin_emails_page() {
    if (!minc_hiring_can_manage()) {
        wp_die('You do not have permission to manage email notifications.');
    }

    if (
        isset($_POST['minc_hiring_action'], $_POST['_wpnonce']) &&
        $_POST['minc_hiring_action'] === 'save_email_templates' &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'minc_save_email_templates')
    ) {
        $raw_templates = isset($_POST['templates']) && is_array($_POST['templates'])
            ? wp_unslash($_POST['templates'])
            : [];
        \MassINC\Notifications::save_templates($raw_templates);

        if (isset($_POST['staff_notification_email'])) {
            $staff_email = sanitize_text_field(wp_unslash($_POST['staff_notification_email']));
            update_option(\MassINC\Notifications::OPTION_STAFF_EMAIL, $staff_email);
        }

        echo '<div class="notice notice-success"><p>Email notification templates saved.</p></div>';
    }

    if (
        isset($_POST['minc_hiring_action'], $_POST['notification_id'], $_POST['_wpnonce']) &&
        $_POST['minc_hiring_action'] === 'retry_notification' &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'minc_retry_notification')
    ) {
        $notification_id = absint($_POST['notification_id']);
        $retried = \MassINC\Notifications::retry_notification($notification_id);
        if ($retried) {
            echo '<div class="notice notice-success"><p>Notification #' . esc_html((string) $notification_id) . ' resent successfully.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Retry attempt for notification #' . esc_html((string) $notification_id) . ' failed. Check the error log below.</p></div>';
        }
    }

    $triggers     = \MassINC\Notifications::get_triggers();
    $logs         = \MassINC\Notifications::get_recent_logs(50);
    $has_api_key  = \MassINC\Notifications::get_api_key() !== '';
    $staff_option = (string) get_option(\MassINC\Notifications::OPTION_STAFF_EMAIL, '');
    $env_staff    = \MassINC\Notifications::get_env('MASSINC_STAFF_NOTIFICATION_EMAIL', '');
    ?>
    <div class="wrap">
        <h1>Hiring — Email Notifications (SendGrid)</h1>

        <div class="card" style="max-width: 900px; margin-bottom: 20px;">
            <h2>SendGrid Environment Status</h2>
            <p>
                <strong>SendGrid API Key (<code>SENDGRID_API_KEY</code>):</strong>
                <?php if ($has_api_key): ?>
                    <span style="color: #008a20; font-weight: 600;">Configured via environment variable</span>
                <?php else: ?>
                    <span style="color: #d63638; font-weight: 600;">Not set (set <code>SENDGRID_API_KEY</code> in your environment or <code>.env</code>)</span>
                <?php endif; ?>
            </p>
            <p>
                <strong>Sender:</strong>
                <?php echo esc_html(\MassINC\Notifications::get_from_name() . ' <' . \MassINC\Notifications::get_from_email() . '>'); ?>
            </p>
            <p>
                <strong>Active Staff Recipients:</strong>
                <code><?php echo esc_html(implode(', ', \MassINC\Notifications::get_staff_recipient_emails())); ?></code>
            </p>
        </div>

        <h2>Editable Email Templates</h2>
        <p>
            Edit email subjects and HTML bodies below without changing code. Available merge placeholders:
            <code>{{applicant_name}}</code>, <code>{{first_name}}</code>, <code>{{last_name}}</code>,
            <code>{{candidate_email}}</code>, <code>{{candidate_phone}}</code>, <code>{{job_title}}</code>,
            <code>{{job_location}}</code>, <code>{{application_id}}</code>, <code>{{submitted_at}}</code>,
            <code>{{admin_applications_url}}</code>.
        </p>

        <form method="post">
            <?php wp_nonce_field('minc_save_email_templates'); ?>
            <input type="hidden" name="minc_hiring_action" value="save_email_templates">

            <?php if (empty($env_staff)): ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="staff_notification_email">Fallback Staff Notification Email(s)</label></th>
                        <td>
                            <input
                                type="text"
                                id="staff_notification_email"
                                name="staff_notification_email"
                                class="regular-text"
                                value="<?php echo esc_attr($staff_option); ?>"
                                placeholder="hiring@massinc.org, hr@massinc.org"
                            >
                            <p class="description">Comma-separated list used when <code>MASSINC_STAFF_NOTIFICATION_EMAIL</code> is not set in the environment.</p>
                        </td>
                    </tr>
                </table>
            <?php endif; ?>

            <?php foreach ($triggers as $key => $trigger): ?>
                <div class="card" style="max-width: 900px; margin-bottom: 20px; padding: 16px;">
                    <h3 style="margin-top: 0;">
                        <?php echo esc_html($trigger['label']); ?>
                        <small style="font-weight: normal; color: #646970;">(<code><?php echo esc_html($key); ?></code>)</small>
                    </h3>
                    <?php if (!empty($trigger['description'])): ?>
                        <p class="description"><?php echo esc_html($trigger['description']); ?></p>
                    <?php endif; ?>

                    <p>
                        <label>
                            <input
                                type="checkbox"
                                name="templates[<?php echo esc_attr($key); ?>][enabled]"
                                value="1"
                                <?php checked(!empty($trigger['enabled'])); ?>
                            >
                            Enable this email notification
                        </label>
                    </p>

                    <p>
                        <label>
                            <strong>Subject Line</strong><br>
                            <input
                                type="text"
                                class="large-text"
                                name="templates[<?php echo esc_attr($key); ?>][subject]"
                                value="<?php echo esc_attr($trigger['subject']); ?>"
                            >
                        </label>
                    </p>

                    <p>
                        <label>
                            <strong>SendGrid Dynamic Template ID (Optional)</strong><br>
                            <input
                                type="text"
                                class="regular-text"
                                name="templates[<?php echo esc_attr($key); ?>][sendgrid_template_id]"
                                value="<?php echo esc_attr($trigger['sendgrid_template_id'] ?? ''); ?>"
                                placeholder="d-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            >
                        </label>
                        <br>
                        <span class="description">If set, SendGrid will render this Dynamic Template ID with the placeholder variables instead of the HTML body below.</span>
                    </p>

                    <p>
                        <label>
                            <strong>Email Body (HTML)</strong><br>
                            <textarea
                                class="large-text code"
                                rows="7"
                                name="templates[<?php echo esc_attr($key); ?>][body]"
                            ><?php echo esc_textarea($trigger['body']); ?></textarea>
                        </label>
                    </p>
                </div>
            <?php endforeach; ?>

            <p><button type="submit" class="button button-primary">Save Email Templates</button></p>
        </form>

        <hr style="margin: 30px 0;">

        <h2>Delivery Log &amp; Retry Queue</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Trigger</th>
                    <th>Recipient</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Last Error / Next Retry</th>
                    <th>Time</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="9">No email delivery logs recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>#<?php echo esc_html((string) $log->id); ?></td>
                        <td><code><?php echo esc_html($log->trigger_key); ?></code></td>
                        <td><?php echo esc_html($log->recipient_email); ?></td>
                        <td><?php echo esc_html($log->subject); ?></td>
                        <td><strong><?php echo esc_html($log->status); ?></strong></td>
                        <td><?php echo esc_html((string) $log->attempts); ?></td>
                        <td>
                            <?php if (!empty($log->last_error)): ?>
                                <div style="color: #d63638;"><?php echo esc_html($log->last_error); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($log->next_retry_at)): ?>
                                <small>Next retry: <?php echo esc_html($log->next_retry_at); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($log->sent_at ?: $log->created_at); ?></td>
                        <td>
                            <?php if ($log->status !== 'sent'): ?>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('minc_retry_notification'); ?>
                                    <input type="hidden" name="minc_hiring_action" value="retry_notification">
                                    <input type="hidden" name="notification_id" value="<?php echo esc_attr((string) $log->id); ?>">
                                    <button type="submit" class="button button-small">Retry Now</button>
                                </form>
                            <?php else: ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

