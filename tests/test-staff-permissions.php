<?php
use MassINC\Models\Application;
use MassINC\Models\Candidate;
use MassINC\Models\Job;
use MassINC\StaffNames;

/**
 * What the shared Hiring Staff account can and can't do, end to end:
 * it sees every rating before rating, rates, writes notes and adds its own
 * name, and nothing an admin does (stages, removing names, job and email
 * settings).
 */
class Test_Staff_Permissions extends WP_UnitTestCase {

    private $application_id;
    private $staff_id;
    private $admin_id;

    public function set_up() {
        parent::set_up();
        minc_hiring_register_roles();
        delete_option(StaffNames::OPTION);
        $this->staff_id = self::factory()->user->create(['role' => 'minc_hiring_staff']);
        $this->admin_id = self::factory()->user->create(['role' => 'administrator']);

        $job_id = Job::create([
            'title'           => 'Staff writer',
            'description'     => 'A test job.',
            'location'        => 'Boston',
            'employment_type' => 'full-time',
            'status'          => 'open',
        ]);
        $candidate_id = Candidate::create([
            'first_name' => 'Maya',
            'last_name'  => 'Okonkwo',
            'email'      => 'maya@example.com',
        ]);
        $this->application_id = Application::create(['job_id' => $job_id, 'candidate_id' => $candidate_id]);

        StaffNames::add('Dana Whitfield');
        StaffNames::add('Luis Ferrer');
    }

    public function tear_down() {
        $_GET = [];
        parent::tear_down();
    }

    private function as_staff() {
        wp_set_current_user($this->staff_id);
    }

    private function as_admin() {
        wp_set_current_user($this->admin_id);
    }

    private function send($method, $path, array $params = []) {
        $request = new WP_REST_Request($method, '/massinc/v1/' . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    private function render_candidate_page() {
        $page_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => '<!-- wp:minc-hiring/candidate-detail /-->',
        ]);
        $this->go_to(get_permalink($page_id));
        $_GET['application'] = (string) $this->application_id;
        return do_blocks(get_post($page_id)->post_content);
    }

    /* ---------------- what staff can do ---------------- */

    public function test_staff_can_rate() {
        $this->as_staff();
        $response = $this->send('POST', "applications/{$this->application_id}/ratings", ['reviewer' => 'Dana Whitfield', 'score' => 4]);
        $this->assertSame(201, $response->get_status());
    }

    public function test_staff_can_add_notes() {
        $this->as_staff();
        $response = $this->send('POST', "applications/{$this->application_id}/notes", ['author' => 'Dana Whitfield', 'text' => 'Strong clips.']);
        $this->assertSame(201, $response->get_status());
    }

    public function test_staff_can_add_their_own_name_to_the_list() {
        $this->as_staff();
        $response = $this->send('POST', 'staff-names', ['name' => 'Nadia Chen']);
        $this->assertSame(201, $response->get_status());
    }

    public function test_staff_see_everyones_ratings_before_they_rate() {
        $this->as_admin();
        $this->send('POST', "applications/{$this->application_id}/ratings", ['reviewer' => 'Luis Ferrer', 'score' => 5]);

        $this->as_staff();
        $html = $this->render_candidate_page();

        // The ratings list and the average are shown, not hidden behind a first rating.
        $this->assertMatchesRegularExpression('/data-ratings-list(?![^>]*hidden)[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/data-overall-row(?![^>]*hidden)[^>]*>/', $html);
        $this->assertMatchesRegularExpression('/data-no-ratings[^>]*hidden/', $html);
        $this->assertStringContainsString('Luis Ferrer', $html);
        $this->assertStringNotContainsString('stay hidden until you', $html);
    }

    public function test_with_no_ratings_staff_are_invited_to_rate_first() {
        $this->as_staff();
        $html = $this->render_candidate_page();

        $this->assertStringContainsString('No one has rated Maya yet', $html);
        $this->assertMatchesRegularExpression('/data-ratings-list[^>]*hidden/', $html);
    }

    /* ---------------- what staff can't do ---------------- */

    public function test_staff_cannot_change_a_stage() {
        $this->as_staff();
        $response = $this->send('POST', "applications/{$this->application_id}/stage", ['stage' => 'interview']);
        $this->assertSame(403, $response->get_status());
    }

    public function test_staff_cannot_remove_names_but_admins_can() {
        $this->as_staff();
        $this->assertSame(403, $this->send('DELETE', 'staff-names', ['name' => 'Luis Ferrer'])->get_status());
        $this->assertContains('Luis Ferrer', StaffNames::all());

        $this->as_admin();
        $this->assertSame(200, $this->send('DELETE', 'staff-names', ['name' => 'Luis Ferrer'])->get_status());
        $this->assertNotContains('Luis Ferrer', StaffNames::all());
    }

    public function test_staff_page_has_no_stage_controls_and_no_name_remove_buttons() {
        $this->as_staff();
        $html = $this->render_candidate_page();

        $this->assertStringContainsString('Only admins can change stages', $html);
        $this->assertStringNotContainsString('data-dd="stage"', $html);
        $this->assertStringNotContainsString('data-removable="1"', $html);
    }

    public function test_admin_page_has_stage_controls_and_name_remove_buttons() {
        $this->as_admin();
        $html = $this->render_candidate_page();

        $this->assertStringContainsString('data-removable="1"', $html);
        $this->assertStringNotContainsString('Only admins can change stages', $html);
    }

    public function test_staff_cannot_manage_jobs_or_email_settings() {
        $this->as_staff();
        $this->assertFalse(minc_hiring_can_manage());
        $this->assertFalse(current_user_can('manage_minc_hiring'));
        $this->assertFalse(current_user_can('edit_minc_applications'));
    }
}
