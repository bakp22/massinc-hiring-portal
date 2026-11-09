<?php
use MassINC\Models\Application;
use MassINC\Models\Candidate;
use MassINC\Models\Job;

/**
 * Covers POST /massinc/v1/applications.
 */
class Test_Applications_API extends WP_UnitTestCase {

    private function create_open_job(array $overrides = []) {
        return Job::create(array_merge([
            'title'           => 'Test Job',
            'description'     => 'A test job description.',
            'location'        => 'Remote',
            'employment_type' => 'full-time',
            'status'          => 'open',
        ], $overrides));
    }

    /**
     * A resume is required, so attach a minimal valid one unless the caller
     * passes its own $files.
     */
    private function dispatch_application(array $params, ?array $files = null) {
        $request = new WP_REST_Request('POST', '/massinc/v1/applications');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_file_params($files ?? ['resume' => $this->make_upload()]);
        return rest_get_server()->dispatch($request);
    }

    private function make_upload($name = 'resume.pdf', $contents = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n") {
        $tmp = wp_tempnam($name);
        file_put_contents($tmp, $contents);
        return [
            'name'     => $name,
            'type'     => 'application/pdf',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp),
        ];
    }

    public function test_valid_submission_persists_all_submitted_fields() {
        $job_id = $this->create_open_job();

        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'jane@example.com',
            'phone'           => '111-222-3333',
            'job_id'          => $job_id,
        ]);

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('submitted', $data['status']);
        $this->assertArrayHasKey('application_id', $data);

        $candidate = Candidate::get_by_email('jane@example.com');
        $this->assertNotNull($candidate, 'Candidate was not created.');
        $this->assertSame('Jane', $candidate->get('first_name'));
        $this->assertSame('Doe', $candidate->get('last_name'));
        $this->assertSame('jane@example.com', $candidate->get('email'));
        $this->assertSame('1112223333', $candidate->get('phone'));

        $application = Application::get_by_id($data['application_id']);
        $this->assertNotNull($application, 'Application was not created.');
        $this->assertSame($job_id, $application->get_job_id());
        $this->assertSame($candidate->get_id(), $application->get_candidate_id());
    }

    public function test_names_and_phone_are_sanitized() {
        $job_id = $this->create_open_job();

        $this->dispatch_application([
            'first_name'      => '  <b>Jane</b>  ',
            'last_name'       => "Doe\n\n",
            'applicant_email' => 'sanitize@example.com',
            'phone'           => '(111) 222-3333',
            'job_id'          => $job_id,
        ]);

        $candidate = Candidate::get_by_email('sanitize@example.com');
        $this->assertNotNull($candidate);
        $this->assertSame('Jane', $candidate->get('first_name'), 'HTML tags/whitespace should be stripped.');
        $this->assertSame('Doe', $candidate->get('last_name'), 'Line breaks/whitespace should be stripped.');
        $this->assertSame('1112223333', $candidate->get('phone'), 'Phone should be normalized to digits only.');
    }

    public function test_names_are_normalized_to_title_case() {
        $job_id = $this->create_open_job();

        $this->dispatch_application([
            'first_name'      => 'JOHN',
            'last_name'       => 'smith-jones',
            'applicant_email' => 'titlecase@example.com',
            'job_id'          => $job_id,
        ]);

        $candidate = Candidate::get_by_email('titlecase@example.com');
        $this->assertSame('John', $candidate->get('first_name'));
        $this->assertSame('Smith-Jones', $candidate->get('last_name'));
    }

    public function test_email_is_normalized_to_lowercase() {
        $job_id = $this->create_open_job();

        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'MixedCase@EXAMPLE.com',
            'job_id'          => $job_id,
        ]);

        $this->assertSame(201, $response->get_status());
        $this->assertNotNull(Candidate::get_by_email('mixedcase@example.com'), 'Email should be stored lowercase.');
    }

    public function test_missing_required_fields_return_400() {
        $job_id = $this->create_open_job();

        $base = [
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'required-fields@example.com',
            'job_id'          => $job_id,
        ];

        foreach (['first_name', 'last_name', 'applicant_email', 'job_id'] as $missing_field) {
            $params = $base;
            unset($params[$missing_field]);

            $response = $this->dispatch_application($params);
            $this->assertSame(400, $response->get_status(), "Missing {$missing_field} should return 400.");
        }
    }

    public function test_whitespace_only_name_returns_400() {
        $job_id = $this->create_open_job();

        $response = $this->dispatch_application([
            'first_name'      => '   ',
            'last_name'       => 'Doe',
            'applicant_email' => 'whitespace-name@example.com',
            'job_id'          => $job_id,
        ]);

        $this->assertSame(400, $response->get_status(), 'A present-but-empty/whitespace name should be rejected, not just a missing one.');
    }

    public function test_phone_with_no_digits_is_stored_as_empty_string() {
        $job_id = $this->create_open_job();

        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'garbagephone@example.com',
            'phone'           => 'abc-not-a-number',
            'job_id'          => $job_id,
        ]);

        $this->assertSame(201, $response->get_status(), 'Phone has no format validation, so non-digit input should not be rejected.');

        $candidate = Candidate::get_by_email('garbagephone@example.com');
        $this->assertSame('', $candidate->get('phone'), 'A phone value with no digits should normalize to an empty string, not error.');
    }

    public function test_phone_is_optional() {
        $job_id = $this->create_open_job();

        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'nophone@example.com',
            'job_id'          => $job_id,
        ]);

        $this->assertSame(201, $response->get_status(), 'Missing phone should NOT be rejected.');
    }

    public function test_invalid_email_returns_400() {
        $job_id = $this->create_open_job();

        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'not-an-email',
            'job_id'          => $job_id,
        ]);

        $this->assertSame(400, $response->get_status());
    }

    public function test_non_integer_job_id_returns_400() {
        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'badjobid@example.com',
            'job_id'          => '5.5',
        ]);

        $this->assertSame(400, $response->get_status(), 'A non-integer job_id should be rejected by validation, not silently truncated.');
    }

    public function test_closed_job_returns_400() {
        $closed_job_id = $this->create_open_job(['status' => 'closed']);

        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'closedjob@example.com',
            'job_id'          => $closed_job_id,
        ]);

        $this->assertSame(400, $response->get_status());
    }

    public function test_nonexistent_job_id_returns_400() {
        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'nosuchjob@example.com',
            'job_id'          => 999999,
        ]);

        $this->assertSame(400, $response->get_status(), 'A job_id with no matching job at all should 400, independent of the closed-job check.');
    }

    public function test_zero_job_id_returns_400() {
        $response = $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'zerojobid@example.com',
            'job_id'          => '0',
        ]);

        $this->assertSame(400, $response->get_status(), 'job_id=0 passes ctype_digit but must fail the > 0 check.');
    }

    public function test_reapplying_with_same_email_updates_existing_candidate() {
        $job_id_1 = $this->create_open_job(['title' => 'Job One']);
        $job_id_2 = $this->create_open_job(['title' => 'Job Two']);

        $this->dispatch_application([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'reapply@example.com',
            'phone'           => '111-222-3333',
            'job_id'          => $job_id_1,
        ]);
        $first_candidate = Candidate::get_by_email('reapply@example.com');
        $this->assertFalse($first_candidate->is_past_applicant(), 'First-time applicant should not be flagged as a past applicant.');

        $response = $this->dispatch_application([
            'first_name'      => 'Janet',
            'last_name'       => 'Doe-Smith',
            'applicant_email' => 'reapply@example.com',
            'phone'           => '999-888-7777',
            'job_id'          => $job_id_2,
        ]);
        $this->assertSame(201, $response->get_status());

        $updated_candidate = Candidate::get_by_email('reapply@example.com');
        $this->assertSame(
            $first_candidate->get_id(),
            $updated_candidate->get_id(),
            'Reapplying should reuse the existing candidate, not create a second one.'
        );
        $this->assertSame('Janet', $updated_candidate->get('first_name'));
        $this->assertSame('Doe-Smith', $updated_candidate->get('last_name'));
        $this->assertSame('9998887777', $updated_candidate->get('phone'));
        $this->assertTrue($updated_candidate->is_past_applicant(), 'Reapplying with the same email should flag the candidate as a past applicant.');
    }

    public function test_reapplying_with_same_name_updates_existing_candidate_and_flags_past_applicant() {
        $job_id_1 = $this->create_open_job(['title' => 'Job One']);
        $job_id_2 = $this->create_open_job(['title' => 'Job Two']);

        $this->dispatch_application([
            'first_name'      => 'Alex',
            'last_name'       => 'Rivera',
            'applicant_email' => 'alex.old@example.com',
            'phone'           => '111-222-3333',
            'job_id'          => $job_id_1,
        ]);
        $first_candidate = Candidate::get_by_email('alex.old@example.com');
        $this->assertFalse($first_candidate->is_past_applicant());

        $response = $this->dispatch_application([
            'first_name'      => 'alex',
            'last_name'       => 'RIVERA',
            'applicant_email' => 'alex.new@example.com',
            'phone'           => '444-555-6666',
            'job_id'          => $job_id_2,
        ]);
        $this->assertSame(201, $response->get_status());

        $updated_candidate = Candidate::get_by_id($first_candidate->get_id());
        $this->assertNotNull($updated_candidate);
        $this->assertSame('Alex', $updated_candidate->get('first_name'));
        $this->assertSame('Rivera', $updated_candidate->get('last_name'));
        $this->assertSame('alex.new@example.com', $updated_candidate->get('email'), 'Past email should be updated with new email.');
        $this->assertSame('4445556666', $updated_candidate->get('phone'), 'Past phone should be updated with new phone.');
        $this->assertTrue($updated_candidate->is_past_applicant(), 'Reapplying with the same name should flag the candidate as a past applicant.');
    }

    public function test_applying_to_same_job_twice_returns_409() {
        $job_id = $this->create_open_job(['title' => 'Job One']);
        $payload = [
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'dupe@example.com',
            'phone'           => '111-222-3333',
            'job_id'          => $job_id,
        ];

        $this->assertSame(201, $this->dispatch_application($payload)->get_status());

        $payload['first_name'] = 'Janet';
        $response = $this->dispatch_application($payload);
        $this->assertSame(409, $response->get_status());

        $candidate = Candidate::get_by_email('dupe@example.com');
        $this->assertSame('Jane', $candidate->get('first_name'), 'A rejected duplicate must not update the candidate.');
        $this->assertFalse($candidate->is_past_applicant(), 'A rejected duplicate must not flag a first-time candidate as a past applicant.');
    }
}
