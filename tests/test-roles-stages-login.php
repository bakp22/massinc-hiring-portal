<?php
use MassINC\Models\Application;
use MassINC\Models\Candidate;
use MassINC\Models\Job;

/**
 * The two kinds of account (administrator and Hiring Staff), who may change a
 * candidate's stage, and the Staff / Admin sign-in tabs.
 */
class Test_Roles_Stages_Login extends WP_UnitTestCase {

    private $application_id;

    public function set_up() {
        parent::set_up();
        $job_id = Job::create(['title' => 'Staff writer', 'description' => 'x', 'status' => 'open']);
        $candidate_id = Candidate::create(['first_name' => 'Maya', 'last_name' => 'Okonkwo', 'email' => 'maya@example.com']);
        $this->application_id = Application::create(['job_id' => $job_id, 'candidate_id' => $candidate_id]);
    }

    public function tear_down() {
        $_POST = [];
        delete_transient('minc_hiring_page_' . md5('minc-hiring/candidate-dashboard'));
        delete_transient('minc_hiring_page_' . md5('minc-hiring/staff-login'));
        parent::tear_down();
    }

    private function staff() {
        return self::factory()->user->create_and_get(['role' => 'minc_hiring_staff']);
    }

    private function admin() {
        return self::factory()->user->create_and_get(['role' => 'administrator']);
    }

    private function change_stage($stage, $application_id = null) {
        $request = new WP_REST_Request('POST', '/massinc/v1/applications/' . ($application_id ?? $this->application_id) . '/stage');
        $request->set_param('stage', $stage);
        return rest_get_server()->dispatch($request);
    }

    private function status() {
        return Application::get_by_id($this->application_id)->get_status();
    }

    /* ---------------- roles ---------------- */

    public function test_staff_can_view_but_not_change_or_manage() {
        $staff = $this->staff();

        $this->assertTrue(user_can($staff, 'view_minc_applications'));
        $this->assertFalse(user_can($staff, 'edit_minc_applications'));
        $this->assertFalse(user_can($staff, 'manage_minc_hiring'));
        $this->assertFalse(user_can($staff, 'manage_options'));
    }

    public function test_administrators_can_do_everything() {
        $admin = $this->admin();

        foreach (['view_minc_applications', 'edit_minc_applications', 'manage_minc_hiring'] as $capability) {
            $this->assertTrue(user_can($admin, $capability), $capability);
        }
    }

    public function test_the_old_hiring_manager_role_is_folded_into_staff() {
        add_role('minc_hiring_manager', 'Hiring Manager', ['read' => true, 'manage_minc_hiring' => true]);
        $user_id = self::factory()->user->create(['role' => 'minc_hiring_manager']);
        delete_option('minc_hiring_roles_version');

        minc_hiring_register_roles();

        $user = new WP_User($user_id);
        $this->assertContains('minc_hiring_staff', $user->roles);
        $this->assertNotContains('minc_hiring_manager', $user->roles);
        $this->assertNull(get_role('minc_hiring_manager'));
        $this->assertFalse(user_can($user, 'manage_minc_hiring'), 'Former managers no longer get the admin-only parts.');
        $this->assertTrue(user_can($user, 'view_minc_applications'));
    }

    /* ---------------- changing a stage ---------------- */

    public function test_an_admin_can_change_the_stage_and_it_is_saved() {
        wp_set_current_user($this->admin()->ID);
        $expected = ['applied' => 'submitted', 'reviewing' => 'screening', 'interview' => 'interview', 'offer' => 'offer', 'rejected' => 'rejected'];

        foreach ($expected as $stage => $status) {
            $response = $this->change_stage($stage);
            $this->assertSame(200, $response->get_status(), $stage);
            $this->assertSame($status, $this->status(), $stage);
        }
    }

    public function test_the_page_shows_the_saved_stage() {
        wp_set_current_user($this->admin()->ID);
        $this->change_stage('interview');

        $this->assertSame('interview', minc_hiring_get_candidate($this->application_id)['stage']);
    }

    public function test_staff_cannot_change_a_stage() {
        wp_set_current_user($this->staff()->ID);

        $response = $this->change_stage('offer');

        $this->assertSame(403, $response->get_status());
        $this->assertSame('submitted', $this->status());
    }

    public function test_signed_out_visitors_cannot_change_a_stage() {
        wp_set_current_user(0);

        $this->assertSame(401, $this->change_stage('offer')->get_status());
        $this->assertSame('submitted', $this->status());
    }

    public function test_an_unknown_stage_is_rejected() {
        wp_set_current_user($this->admin()->ID);

        foreach (['hired', 'bogus', '', 'OFFER ', 'offer; DROP'] as $bad) {
            $status = $this->change_stage($bad)->get_status();
            $this->assertContains($status, [200, 400], var_export($bad, true));
        }
        $this->assertSame(400, $this->change_stage('bogus')->get_status());
        $this->assertSame(400, $this->change_stage('hired')->get_status());
        $this->assertNotSame('hired', $this->status());
    }

    public function test_changing_the_stage_of_an_unknown_application_is_not_found() {
        wp_set_current_user($this->admin()->ID);

        $this->assertSame(404, $this->change_stage('offer', 999999)->get_status());
    }

    public function test_update_status_only_accepts_known_statuses() {
        $this->assertFalse(Application::update_status($this->application_id, 'anything'));
        $this->assertTrue(Application::update_status($this->application_id, 'offer'));
        $this->assertSame('offer', $this->status());
    }

    /* ---------------- sign-in tabs ---------------- */

    private function authenticate($user, $tab) {
        $_POST['massinc_login_type'] = $tab;
        return apply_filters('authenticate', $user, 'name', 'pass');
    }

    public function test_the_shared_staff_account_signs_in_on_the_staff_tab_only() {
        $staff = $this->staff();

        $this->assertSame($staff, $this->authenticate($staff, 'staff'));
        $result = $this->authenticate($staff, 'admin');
        $this->assertWPError($result);
        $this->assertSame('massinc_wrong_login_type', $result->get_error_code());
    }

    public function test_an_admin_signs_in_on_the_admin_tab_only() {
        $admin = $this->admin();

        $this->assertSame($admin, $this->authenticate($admin, 'admin'));
        $result = $this->authenticate($admin, 'staff');
        $this->assertWPError($result);
        $this->assertSame('massinc_wrong_login_type', $result->get_error_code());
    }

    public function test_an_account_without_portal_access_is_refused_on_either_tab() {
        $subscriber = self::factory()->user->create_and_get(['role' => 'subscriber']);

        foreach (['staff', 'admin'] as $tab) {
            $result = $this->authenticate($subscriber, $tab);
            $this->assertWPError($result);
            $this->assertSame('massinc_no_access', $result->get_error_code());
        }
    }

    public function test_signing_in_elsewhere_is_not_restricted() {
        $admin = $this->admin();
        unset($_POST['massinc_login_type']);

        $this->assertSame($admin, apply_filters('authenticate', $admin, 'name', 'pass'));
    }

    public function test_failed_credentials_stay_a_plain_failure() {
        $_POST['massinc_login_type'] = 'staff';

        $result = apply_filters('authenticate', new WP_Error('incorrect_password', 'Nope'), 'name', 'pass');

        $this->assertWPError($result);
        $this->assertNotContains($result->get_error_code(), ['massinc_wrong_login_type', 'massinc_no_access']);
    }

    /* ---------------- keeping staff in the portal ---------------- */

    public function test_staff_land_on_the_candidates_page_after_signing_in() {
        $dashboard = minc_hiring_dashboard_url();

        $this->assertSame($dashboard, apply_filters('login_redirect', admin_url(), '', $this->staff()));
        $this->assertSame(admin_url(), apply_filters('login_redirect', admin_url(), '', $this->admin()));
    }

    public function test_staff_get_no_admin_bar_but_admins_keep_it() {
        wp_set_current_user($this->staff()->ID);
        $this->assertFalse(apply_filters('show_admin_bar', true));

        wp_set_current_user($this->admin()->ID);
        $this->assertTrue(apply_filters('show_admin_bar', true));
    }

    public function test_staff_are_sent_away_from_the_wordpress_dashboard() {
        wp_set_current_user($this->staff()->ID);
        $this->assertSame(minc_hiring_dashboard_url(), minc_hiring_staff_admin_redirect());

        wp_set_current_user($this->admin()->ID);
        $this->assertSame('', minc_hiring_staff_admin_redirect());

        wp_set_current_user(0);
        $this->assertSame('', minc_hiring_staff_admin_redirect());
    }

    public function test_staff_may_still_make_background_requests_to_admin_ajax() {
        wp_set_current_user($this->staff()->ID);
        add_filter('wp_doing_ajax', '__return_true');

        $this->assertSame('', minc_hiring_staff_admin_redirect());

        remove_filter('wp_doing_ajax', '__return_true');
    }

    /* ---------------- finding the portal's pages ---------------- */

    public function test_the_candidates_page_is_found_by_its_block() {
        $page_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Whatever it is called',
            'post_content' => '<!-- wp:minc-hiring/candidate-dashboard /-->',
        ]);

        $this->assertSame(get_permalink($page_id), minc_hiring_dashboard_url());
    }

    public function test_falls_back_to_the_home_page_when_no_page_has_the_block() {
        $this->assertSame(home_url('/'), minc_hiring_dashboard_url());
    }

    public function test_the_sign_in_page_is_found_by_its_block() {
        $page_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:minc-hiring/staff-login /-->',
        ]);

        $this->assertSame(get_permalink($page_id), minc_hiring_login_url());
        $this->assertStringContainsString(rawurlencode(get_permalink($page_id)), minc_hiring_logout_url());
    }

    /* ---------------- the sign-in block ---------------- */

    private function render_login($tab = null, $login = null) {
        $page_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:minc-hiring/staff-login /-->',
        ]);
        $this->go_to(get_permalink($page_id));
        // go_to() resets the query string, so set it afterwards.
        if ($tab) {
            $_GET['type'] = $tab;
        }
        if ($login) {
            $_GET['login'] = $login;
        }
        return do_blocks(get_post($page_id)->post_content);
    }

    public function test_the_sign_in_page_defaults_to_the_staff_tab() {
        $html = $this->render_login();

        $this->assertStringContainsString('Staff sign in', $html);
        $this->assertStringContainsString('Staff username', $html);
        $this->assertStringContainsString('name="massinc_login_type" value="staff"', $html);
        $this->assertStringContainsString('shared MassInc WordPress account', $html);
    }

    public function test_the_admin_tab_asks_for_an_email() {
        $html = $this->render_login('admin');

        $this->assertStringContainsString('Admin sign in', $html);
        $this->assertStringContainsString('Admin email', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('name="massinc_login_type" value="admin"', $html);
        $this->assertStringContainsString('actions are recorded against it', $html);
    }

    public function test_the_tabs_link_to_each_other() {
        $html = $this->render_login('admin');

        $this->assertMatchesRegularExpression('/class="massinc-login-tab"\s+href="[^"]*"\s*>Staff</', $html);
        $this->assertMatchesRegularExpression('/class="massinc-login-tab is-active"\s+href="[^"]*type=admin[^"]*"/', $html);
    }

    public function test_a_failed_sign_in_message_depends_on_the_reason_and_tab() {
        $this->assertStringContainsString('Use the Admin tab to sign in', $this->render_login('staff', 'wrong_type'));
        $this->assertStringContainsString('Use the Staff tab to sign in', $this->render_login('admin', 'wrong_type'));
        $this->assertStringContainsString('Invalid username or password', $this->render_login('staff', 'failed'));
        $this->assertStringContainsString('Invalid email or password', $this->render_login('admin', 'failed'));
        $this->assertStringNotContainsString('Invalid', $this->render_login('staff'));
    }

    /* ---------------- the gate on the portal pages ---------------- */

    private function gate_output() {
        ob_start();
        $allowed = minc_hiring_require_staff();
        return [$allowed, ob_get_clean()];
    }

    public function test_signed_out_visitors_are_pointed_to_sign_in() {
        wp_set_current_user(0);

        [$allowed, $output] = $this->gate_output();

        $this->assertFalse($allowed);
        $this->assertStringContainsString('sign in', $output);
        $this->assertStringContainsString('<a href=', $output);
    }

    public function test_signed_in_users_without_access_are_told_so() {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        [$allowed, $output] = $this->gate_output();

        $this->assertFalse($allowed);
        $this->assertStringContainsString('doesn&rsquo;t have access', $output);
    }

    public function test_staff_and_admins_get_through() {
        foreach ([$this->staff(), $this->admin()] as $user) {
            wp_set_current_user($user->ID);
            $this->assertTrue($this->gate_output()[0]);
        }
    }
}
