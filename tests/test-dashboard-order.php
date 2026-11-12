<?php
use MassINC\Models\Application;
use MassINC\Models\Candidate;
use MassINC\Models\Job;
use MassINC\Models\Rating;

/**
 * The Candidates page lists the newest applications first. Sorting by rating
 * or by date happens in the browser (view.js), so what's checked here is the
 * order the list arrives in and the data the sort relies on.
 */
class Test_Dashboard_Order extends WP_UnitTestCase {

    private $job_id;

    public function set_up() {
        parent::set_up();
        $this->job_id = Job::create(['title' => 'Staff writer', 'description' => 'x', 'status' => 'open']);
    }

    private function apply($first, $created_at) {
        $candidate_id = Candidate::create([
            'first_name' => $first,
            'last_name'  => 'Tester',
            'email'      => strtolower($first) . '@example.com',
        ]);
        return Application::create([
            'job_id'       => $this->job_id,
            'candidate_id' => $candidate_id,
            'created_at'   => $created_at,
        ]);
    }

    private function names() {
        return array_map(function ($candidate) {
            return explode(' ', $candidate['name'])[0];
        }, minc_hiring_get_candidates());
    }

    public function test_newest_application_comes_first() {
        $this->apply('Oldest', '2026-08-01 09:00:00');
        $this->apply('Newest', '2026-08-20 09:00:00');
        $this->apply('Middle', '2026-08-10 09:00:00');

        $this->assertSame(['Newest', 'Middle', 'Oldest'], $this->names());
    }

    public function test_rating_does_not_change_the_default_order() {
        $oldest = $this->apply('Oldest', '2026-08-01 09:00:00');
        $this->apply('Newest', '2026-08-20 09:00:00');
        Rating::save($oldest, 'Dana Whitfield', 5);

        $this->assertSame(['Newest', 'Oldest'], $this->names(), 'A high rating no longer lifts an older application to the top.');
    }

    public function test_applications_from_the_same_moment_list_the_latest_added_first() {
        $this->apply('First', '2026-08-01 09:00:00');
        $this->apply('Second', '2026-08-01 09:00:00');

        $this->assertSame(['Second', 'First'], $this->names());
    }

    public function test_each_candidate_carries_the_time_the_page_sorts_by() {
        $this->apply('Dated', '2026-08-12 15:30:00');

        $candidate = minc_hiring_get_candidates()[0];

        $this->assertIsInt($candidate['applied_ts']);
        $this->assertSame(strtotime('2026-08-12 15:30:00'), $candidate['applied_ts']);
        $this->assertSame('12 Aug 2026', $candidate['applied']);
    }

    public function test_the_sample_data_is_also_newest_first() {
        $_GET['demo'] = '1';

        $stamps = array_column(minc_hiring_get_candidates(), 'applied_ts');

        unset($_GET['demo']);
        $sorted = $stamps;
        rsort($sorted);
        $this->assertSame($sorted, $stamps);
        $this->assertCount(6, $stamps);
    }
}
