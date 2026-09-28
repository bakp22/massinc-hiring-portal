<?php
use MassINC\Models\Job;

/**
 * Covers GET /massinc/v1/jobs.
 */
class Test_Jobs_API extends WP_UnitTestCase {

    private function create_job(array $overrides = []) {
        $defaults = [
            'title'           => 'Test Job',
            'description'     => 'A test job description.',
            'location'        => 'Boston, MA',
            'employment_type' => 'full-time',
            'status'          => 'open',
            'salary_min'      => 50000,
            'salary_max'      => 70000,
        ];

        return Job::create(array_merge($defaults, $overrides));
    }

    private function dispatch_get_jobs() {
        $request = new WP_REST_Request('GET', '/massinc/v1/jobs');
        return rest_get_server()->dispatch($request);
    }

    public function test_only_open_jobs_are_returned() {
        $open_id_1 = $this->create_job(['title' => 'Open Role 1']);
        $open_id_2 = $this->create_job(['title' => 'Open Role 2']);
        $closed_id = $this->create_job(['title' => 'Closed Role', 'status' => 'closed']);

        $data = $this->dispatch_get_jobs()->get_data();
        $returned_ids = array_map(function ($job) {
            return (int) $job['id'];
        }, $data);

        $this->assertContains($open_id_1, $returned_ids, 'An open job is missing from the response.');
        $this->assertContains($open_id_2, $returned_ids, 'An open job is missing from the response.');
        $this->assertNotContains($closed_id, $returned_ids, 'A closed job was incorrectly returned.');
        $this->assertCount(2, $returned_ids, 'Response should contain exactly the open jobs in the DB.');

        foreach ($data as $job) {
            $this->assertSame('open', $job['status'], 'A non-open job was returned by GET /jobs.');
        }
    }

    public function test_jobs_are_ordered_newest_first() {
        $oldest_id = $this->create_job(['title' => 'Oldest', 'created_at' => '2026-01-01 09:00:00']);
        $middle_id = $this->create_job(['title' => 'Middle', 'created_at' => '2026-06-01 09:00:00']);
        $newest_id = $this->create_job(['title' => 'Newest', 'created_at' => '2026-09-01 09:00:00']);

        $data = $this->dispatch_get_jobs()->get_data();
        $returned_ids = array_map(function ($job) {
            return (int) $job['id'];
        }, $data);

        $this->assertSame([$newest_id, $middle_id, $oldest_id], $returned_ids, 'Jobs should be ordered newest-created first.');
    }

    public function test_job_object_has_expected_fields_and_types() {
        $job_id = $this->create_job([
            'title'           => 'Backend Engineer',
            'description'     => 'Build APIs.',
            'location'        => 'Remote',
            'employment_type' => 'full-time',
            'salary_min'      => 85000,
            'salary_max'      => 110000,
        ]);

        $data = $this->dispatch_get_jobs()->get_data();

        $job = null;
        foreach ($data as $candidate) {
            if ((int) $candidate['id'] === $job_id) {
                $job = $candidate;
                break;
            }
        }
        $this->assertNotNull($job, 'Created job was not found in the response.');

        $expected_keys = [
            'id', 'title', 'description', 'location', 'employment_type',
            'salary_min', 'salary_max', 'status', 'created_at', 'updated_at',
        ];
        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey($key, $job);
        }

        $this->assertIsInt($job['id']);
        $this->assertIsString($job['title']);
        $this->assertIsString($job['description']);
        $this->assertIsString($job['location']);
        $this->assertIsString($job['employment_type']);
        $this->assertIsString($job['status']);
        $this->assertIsFloat($job['salary_min']);
        $this->assertIsFloat($job['salary_max']);
        $this->assertIsString($job['created_at']);
        $this->assertIsString($job['updated_at']);

        $this->assertSame('Backend Engineer', $job['title']);
        $this->assertSame(85000.0, $job['salary_min']);
        $this->assertSame(110000.0, $job['salary_max']);
    }

    public function test_response_status_and_body_shape() {
        $this->create_job(['title' => 'Any Open Role']);

        $response = $this->dispatch_get_jobs();

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey(0, $data, 'Response body should be a JSON list, not a keyed object.');

        $json = wp_json_encode($data);
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey(0, $decoded);
    }
}
