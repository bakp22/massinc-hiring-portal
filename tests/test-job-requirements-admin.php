<?php
use MassINC\Attachments;
use MassINC\Database;
use MassINC\Models\Application;
use MassINC\Models\Attachment;
use MassINC\Models\Candidate;
use MassINC\Models\Job;

class Test_Job_Requirements_Admin extends WP_UnitTestCase {
    public function tear_down() {
        $_POST = [];
        unset($_GET['hiring_view']);
        wp_set_current_user(0);
        parent::tear_down();
    }

    private function job(array $required_fields = []) {
        return Job::create([
            'title' => 'Test position',
            'required_fields' => $required_fields,
        ]);
    }

    private function submit($job_id, array $params = [], array $files = []) {
        $request = new WP_REST_Request('POST', '/massinc/v1/applications');
        $request->set_body_params(array_merge([
            'job_id' => $job_id,
            'first_name' => 'Taylor',
            'last_name' => 'Test',
            'applicant_email' => 'taylor@example.com',
        ], $params));
        $request->set_file_params($files);
        return rest_get_server()->dispatch($request);
    }

    private function admin() {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function save_requirement($job_id, $field, $required) {
        $request = new WP_REST_Request('PATCH', '/massinc/v1/jobs/' . $job_id . '/requirements');
        $request->set_body_params(['field' => $field, 'required' => $required]);
        return rest_get_server()->dispatch($request);
    }

    public function test_schema_upgrade_preserves_legacy_requirements_and_data() {
        global $wpdb;
        $id = $this->job();
        $wpdb->update($wpdb->prefix . 'massinc_jobs', ['required_fields' => null], ['id' => $id]);
        update_option('minc_hiring_db_version', '5');
        Database::maybe_upgrade();

        $job = Job::get_by_id($id);
        $this->assertSame('Test position', $job->get('title'));
        $this->assertSame(Job::required_field_defaults(), $job->get_required_fields());
        $this->assertSame(Database::SCHEMA_VERSION, get_option('minc_hiring_db_version'));
    }

    public function test_admin_can_change_one_position_without_changing_another() {
        $first = $this->job();
        $second = $this->job();
        $this->admin();

        foreach (['phone' => true, 'resume' => false, 'writing_samples' => true, 'sample_url' => true] as $field => $required) {
            $this->assertSame(200, $this->save_requirement($first, $field, $required)->get_status());
        }
        $this->assertSame([
            'phone' => true, 'resume' => false, 'cover_letter' => false,
            'writing_samples' => true, 'sample_url' => true,
        ], Job::get_by_id($first)->get_required_fields());
        $this->assertSame(Job::required_field_defaults(), Job::get_by_id($second)->get_required_fields());

        foreach (array_keys(Job::required_field_defaults()) as $field) {
            $this->save_requirement($first, $field, false);
        }
        $this->assertNotContains(true, Job::get_by_id($first)->get_required_fields());
    }

    public function test_only_admins_can_change_requirements_and_unknown_fields_cannot_write() {
        $id = $this->job();
        wp_set_current_user(self::factory()->user->create(['role' => 'minc_hiring_staff']));
        $this->assertSame(403, $this->save_requirement($id, 'phone', true)->get_status());

        wp_set_current_user(0);
        $this->assertSame(401, $this->save_requirement($id, 'phone', true)->get_status());

        $this->admin();
        $this->assertSame(400, $this->save_requirement($id, 'unknown_field', true)->get_status());
        $this->assertSame(400, $this->save_requirement($id, 'phone', 'not a boolean')->get_status());
        $this->assertSame(404, $this->save_requirement(999999, 'phone', true)->get_status());
        $this->assertSame(Job::required_field_defaults(), Job::get_by_id($id)->get_required_fields());
    }

    public function test_portal_positions_view_is_inside_the_dashboard_and_admin_only() {
        $open_id = $this->job();
        $closed_id = Job::create(['title' => 'Closed position', 'status' => 'closed']);
        $_GET['hiring_view'] = 'positions';
        $this->admin();
        $html = render_block(['blockName' => 'minc-hiring/candidate-dashboard', 'attrs' => []]);
        $this->assertStringContainsString('massinc-positions', $html);
        $this->assertStringContainsString('data-position-id="' . $open_id . '"', $html);
        $this->assertStringNotContainsString('data-position-id="' . $closed_id . '"', $html);
        $this->assertStringContainsString('role="switch"', $html);
        $this->assertStringContainsString('CV / resume required', $html);
        $this->assertStringContainsString('aria-current="page">Positions', $html);

        wp_set_current_user(self::factory()->user->create(['role' => 'minc_hiring_staff']));
        $html = render_block(['blockName' => 'minc-hiring/candidate-dashboard', 'attrs' => []]);
        $this->assertStringNotContainsString('role="switch"', $html);
        $this->assertStringContainsString('Only administrators', $html);

        // When the last open role closes, show an empty state instead of its switches.
        global $wpdb;
        $wpdb->update($wpdb->prefix . 'massinc_jobs', ['status' => 'closed'], ['id' => $open_id]);
        $this->admin();
        $html = render_block(['blockName' => 'minc-hiring/candidate-dashboard', 'attrs' => []]);
        $this->assertStringContainsString('No open positions are currently available.', $html);
        $this->assertStringNotContainsString('role="switch"', $html);
    }

    public function test_requirements_are_returned_as_booleans_by_jobs_api() {
        $this->job(['phone' => true, 'resume' => false]);
        $response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/massinc/v1/jobs'));
        $requirements = $response->get_data()[0]['required_fields'];
        $this->assertTrue($requirements['phone']);
        $this->assertFalse($requirements['resume']);
    }

    public function test_phone_and_links_are_required_only_for_the_configured_position() {
        $required = $this->job(['resume' => false, 'phone' => true, 'sample_url' => true]);
        $optional = $this->job(['resume' => false]);
        $response = $this->submit($required, [
            'phone' => 'letters only', 'required_fields' => ['phone' => false, 'sample_url' => false],
        ]);

        $this->assertSame(400, $response->get_status());
        $errors = $response->as_error()->get_error_data()['params'];
        $this->assertArrayHasKey('phone', $errors);
        $this->assertArrayHasKey('sample_url', $errors);
        $this->assertNull(Candidate::get_by_email('taylor@example.com'));
        $this->assertSame(201, $this->submit($optional)->get_status());

        $accepted = $this->submit($required, [
            'phone' => '(617) 555-0100', 'sample_urls' => ['https://example.com/work'],
        ]);
        $this->assertSame(201, $accepted->get_status());
        $this->assertSame('6175550100', Candidate::get_by_email('taylor@example.com')->get('phone'));
    }

    public function test_each_required_upload_is_enforced_before_creating_an_application() {
        foreach (['resume', 'cover_letter', 'writing_samples'] as $field) {
            $id = $this->job(['resume' => false, $field => true]);
            $params = ['first_name' => $field, 'applicant_email' => $field . '@example.com'];
            $response = $this->submit($id, $params);
            $this->assertSame(400, $response->get_status(), $field);
            $this->assertArrayHasKey($field, $response->as_error()->get_error_data()['params']);
            $this->assertNull(Candidate::get_by_email($params['applicant_email']));

            $tmp = wp_tempnam('sample.pdf');
            file_put_contents($tmp, "%PDF-1.4\nTest sample\n");
            $accepted = $this->submit($id, $params, [$field => [
                'name' => 'sample.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
            ]]);
            $this->assertSame(201, $accepted->get_status(), $field);
            Attachments::purge($accepted->get_data()['application_id']);
        }
    }

    public function test_optional_uploads_still_reject_invalid_files() {
        $id = $this->job(['resume' => false]);
        $tmp = wp_tempnam('bad.pdf');
        file_put_contents($tmp, 'not a PDF');
        $response = $this->submit($id, [], ['resume' => [
            'name' => 'bad.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp),
        ]]);
        unlink($tmp);
        $this->assertSame(400, $response->get_status());
    }

    public function test_form_asterisks_and_accessible_requirements_follow_the_job() {
        $id = $this->job(['phone' => true, 'resume' => false, 'cover_letter' => true, 'sample_url' => true]);
        $html = render_block([
            'blockName' => 'minc-hiring/application-form', 'attrs' => ['jobId' => $id],
        ]);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//input[@name="phone"][@required]')->length);
        $this->assertSame(0, $xpath->query('//div[@data-field="resume"]//span[@aria-hidden="true"][text()="*"]')->length);
        $this->assertSame(1, $xpath->query('//div[@data-field="cover_letter"][@data-required="1"]')->length);
        $this->assertSame(1, $xpath->query('//input[@id="massinc-cover_letter"][@aria-required="true"]')->length);
        $this->assertSame(1, $xpath->query('//div[@data-field="sample_url"]//label/span[text()="*"]')->length);
    }

    public function test_admin_application_rows_show_only_their_own_files_and_links() {
        $id = $this->job(['resume' => false]);
        $applications = [];
        foreach (['First', 'Second'] as $name) {
            $candidate_id = Candidate::create(['first_name' => $name, 'last_name' => 'Candidate', 'email' => strtolower($name) . '@example.com']);
            $application_id = Application::create(['job_id' => $id, 'candidate_id' => $candidate_id]);
            $applications[] = $application_id;
            Attachment::create([
                'application_id' => $application_id, 'kind' => 'resume',
                'original_name' => $name . '-resume.pdf', 'stored_name' => str_repeat('a', 32) . '.pdf',
            ]);
            Attachment::create([
                'application_id' => $application_id, 'kind' => 'link',
                'url' => 'https://example.com/' . strtolower($name),
            ]);
        }
        $this->admin();
        ob_start();
        minc_hiring_admin_applications_page();
        $html = ob_get_clean();

        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $first = $xpath->query('//tbody/tr[td="First Candidate"]')->item(0);
        $second = $xpath->query('//tbody/tr[td="Second Candidate"]')->item(0);
        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertStringContainsString('First-resume.pdf', $first->textContent);
        $this->assertStringNotContainsString('Second-resume.pdf', $first->textContent);
        $this->assertStringContainsString('https://example.com/second', $second->textContent);
        $download_url = $xpath->query('.//a', $first)->item(0)->getAttribute('href');
        $this->assertStringContainsString('_wpnonce=', $download_url);
        $this->assertStringContainsString('/massinc/v1/attachments/', urldecode($download_url));
        $this->assertStringNotContainsString(str_repeat('a', 32), $html);
    }
}
