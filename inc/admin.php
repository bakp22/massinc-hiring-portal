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
