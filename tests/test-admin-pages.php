<?php
/**
 * The wp-admin Hiring screens (Jobs, Applications) read and write the same
 * massinc_* tables as the rest of the plugin.
 */
class Test_Admin_Pages extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        minc_hiring_register_roles();
    }

    public function tear_down() {
        $_POST = [];
        parent::tear_down();
    }

    private function render($callback) {
        global $wpdb;
        $wpdb->last_error = '';
        ob_start();
        $callback();
        return ob_get_clean();
    }

    public function test_creating_a_job_saves_it_and_lists_it() {
        global $wpdb;
        $_POST = [
            'minc_hiring_action' => 'create_job',
            '_wpnonce'           => wp_create_nonce('minc_create_job'),
            'title'              => 'Policy research intern',
            'description'        => 'Spring 2027',
            'location'           => 'Boston, MA',
            'employment_type'    => 'Internship',
        ];

        $html = $this->render('minc_hiring_admin_jobs_page');

        $this->assertSame('', $wpdb->last_error);
        $this->assertSame('1', $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}massinc_jobs WHERE title = 'Policy research intern'"
        ));
        $this->assertStringContainsString('Policy research intern', $html);
    }

    public function test_the_applications_page_lists_saved_applications() {
        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}massinc_jobs", ['title' => 'Staff writer', 'description' => '', 'status' => 'open']);
        $job_id = $wpdb->insert_id;
        $wpdb->insert("{$wpdb->prefix}massinc_candidates", ['first_name' => 'Maya', 'last_name' => 'Okonkwo', 'email' => 'maya@example.org']);
        $candidate_id = $wpdb->insert_id;
        $wpdb->insert("{$wpdb->prefix}massinc_applications", ['job_id' => $job_id, 'candidate_id' => $candidate_id]);

        $html = $this->render('minc_hiring_admin_applications_page');

        $this->assertSame('', $wpdb->last_error);
        $this->assertStringContainsString('Maya Okonkwo', $html);
        $this->assertStringContainsString('Staff writer', $html);
    }
}
