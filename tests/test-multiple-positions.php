<?php
use MassINC\Attachments;
use MassINC\Models\Application;
use MassINC\Models\Job;
use MassINC\Models\Note;
use MassINC\Models\Rating;

class Test_Multiple_Positions extends WP_UnitTestCase {

    private $writer_job;
    private $reporter_job;

    public function set_up() {
        parent::set_up();
        $this->writer_job   = $this->open_job('Staff writer');
        $this->reporter_job = $this->open_job('Data reporter');
    }

    public function tear_down() {
        unset($_GET['application']);
        wp_set_current_user(0);
        global $wpdb;
        $table = $wpdb->prefix . 'massinc_application_attachments';
        foreach ($wpdb->get_col("SELECT stored_name FROM {$table} WHERE stored_name != ''") as $stored) {
            $path = Attachments::path_for($stored);
            if ($path && file_exists($path)) {
                unlink($path);
            }
        }
        parent::tear_down();
    }

    private function open_job($title) {
        return Job::create([
            'title'           => $title,
            'description'     => 'x',
            'employment_type' => 'full-time',
            'status'          => 'open',
        ]);
    }

    private function upload($name) {
        $tmp = wp_tempnam($name);
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");
        return [
            'name'     => $name,
            'type'     => 'application/pdf',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp),
        ];
    }

    private function apply($job_id, $resume_name, $email = 'maya@example.com', $first = 'Maya') {
        $request = new WP_REST_Request('POST', '/massinc/v1/applications');
        $request->set_param('first_name', $first);
        $request->set_param('last_name', 'Okonkwo');
        $request->set_param('applicant_email', $email);
        $request->set_param('job_id', $job_id);
        $request->set_file_params(['resume' => $this->upload($resume_name)]);
        return rest_get_server()->dispatch($request);
    }

    private function apply_to_both() {
        $writer   = $this->apply($this->writer_job, 'writer-resume.pdf');
        $reporter = $this->apply($this->reporter_job, 'reporter-resume.pdf');
        $this->assertSame(201, $writer->get_status());
        $this->assertSame(201, $reporter->get_status());
        return [$writer->get_data()['application_id'], $reporter->get_data()['application_id']];
    }

    private function material_names($application_id) {
        return array_column(minc_hiring_get_candidate($application_id)['materials'], 'name');
    }

    public function test_each_position_gets_its_own_row_under_the_same_name() {
        [$writer_id, $reporter_id] = $this->apply_to_both();

        $rows = minc_hiring_get_candidates();

        $this->assertCount(2, $rows);
        $this->assertSame(['Maya Okonkwo', 'Maya Okonkwo'], array_column($rows, 'name'));
        $this->assertEqualsCanonicalizing(['Staff writer', 'Data reporter'], array_column($rows, 'position'));
        $this->assertEqualsCanonicalizing([$writer_id, $reporter_id], array_column($rows, 'id'));

        global $wpdb;
        $people = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}massinc_candidates");
        $this->assertSame(1, $people, 'Both rows belong to one candidate record.');
        $this->assertSame(
            Application::get_by_id($writer_id)->get_candidate_id(),
            Application::get_by_id($reporter_id)->get_candidate_id()
        );
    }

    public function test_each_row_shows_the_materials_sent_for_that_position() {
        [$writer_id, $reporter_id] = $this->apply_to_both();

        $this->assertSame(['writer-resume.pdf'], $this->material_names($writer_id));
        $this->assertSame(['reporter-resume.pdf'], $this->material_names($reporter_id));
    }

    public function test_ratings_notes_and_stage_stay_with_their_own_position() {
        [$writer_id, $reporter_id] = $this->apply_to_both();

        Rating::save($writer_id, 'Dana Whitfield', 5);
        Note::create($writer_id, 'Dana Whitfield', 'Strong clips.');
        Application::update_status($writer_id, 'interview');

        $writer   = minc_hiring_get_candidate($writer_id);
        $reporter = minc_hiring_get_candidate($reporter_id);

        $this->assertCount(1, $writer['reviews']);
        $this->assertCount(1, $writer['notes']);
        $this->assertSame('interview', $writer['stage']);

        $this->assertSame([], $reporter['reviews']);
        $this->assertSame([], $reporter['notes']);
        $this->assertNull($reporter['rating']);
        $this->assertSame('applied', $reporter['stage']);
    }

    public function test_applying_to_the_same_position_twice_is_still_refused() {
        $this->apply_to_both();

        $again = $this->apply($this->writer_job, 'another-resume.pdf');

        $this->assertSame(409, $again->get_status());
        $this->assertCount(2, minc_hiring_get_candidates());
    }

    public function test_different_people_for_the_same_position_stay_separate() {
        $this->apply($this->writer_job, 'maya.pdf');
        $this->apply($this->writer_job, 'sam.pdf', 'sam@example.com', 'Sam');

        $this->assertEqualsCanonicalizing(
            ['Maya Okonkwo', 'Sam Okonkwo'],
            array_column(minc_hiring_get_candidates(), 'name')
        );
    }

    /* Previous applicant: an earlier application already at Offer or Rejected. */

    private function previous_flags() {
        $flags = [];
        foreach (minc_hiring_get_candidates() as $row) {
            $flags[$row['id']] = $row['previous_applicant'];
        }
        return $flags;
    }

    public function test_applying_to_several_positions_at_once_is_not_a_previous_applicant() {
        [$writer_id, $reporter_id] = $this->apply_to_both();

        $this->assertSame([$reporter_id => false, $writer_id => false], $this->previous_flags());
    }

    public function test_a_rejected_earlier_application_makes_the_later_one_a_previous_applicant() {
        $writer_id = $this->apply($this->writer_job, 'writer.pdf')->get_data()['application_id'];
        Application::update_status($writer_id, 'rejected');
        $reporter_id = $this->apply($this->reporter_job, 'reporter.pdf')->get_data()['application_id'];

        $flags = $this->previous_flags();

        $this->assertTrue($flags[$reporter_id]);
        $this->assertFalse($flags[$writer_id], 'The decided application itself is not marked.');
    }

    public function test_an_earlier_offer_also_counts_as_a_decision() {
        $writer_id = $this->apply($this->writer_job, 'writer.pdf')->get_data()['application_id'];
        Application::update_status($writer_id, 'offer');
        $reporter_id = $this->apply($this->reporter_job, 'reporter.pdf')->get_data()['application_id'];

        $this->assertTrue($this->previous_flags()[$reporter_id]);
    }

    public function test_a_later_decision_flags_rows_without_reapplying() {
        [$writer_id, $reporter_id] = $this->apply_to_both();
        $this->assertFalse($this->previous_flags()[$reporter_id]);

        Application::update_status($writer_id, 'rejected');

        $this->assertTrue($this->previous_flags()[$reporter_id], 'Worked out on load, so the new stage counts at once.');
    }

    public function test_a_decision_on_the_newer_application_does_not_flag_the_older_one() {
        [$writer_id, $reporter_id] = $this->apply_to_both();
        Application::update_status($reporter_id, 'rejected');

        $this->assertSame([$reporter_id => false, $writer_id => false], $this->previous_flags());
    }

    public function test_someone_elses_rejection_does_not_flag_a_different_person() {
        $sam_id = $this->apply($this->writer_job, 'sam.pdf', 'sam@example.com', 'Sam')->get_data()['application_id'];
        Application::update_status($sam_id, 'rejected');
        $maya_id = $this->apply($this->reporter_job, 'maya.pdf')->get_data()['application_id'];

        $this->assertFalse($this->previous_flags()[$maya_id]);
    }

    public function test_the_detail_page_gets_the_flag_too() {
        $writer_id = $this->apply($this->writer_job, 'writer.pdf')->get_data()['application_id'];
        Application::update_status($writer_id, 'rejected');
        $reporter_id = $this->apply($this->reporter_job, 'reporter.pdf')->get_data()['application_id'];

        $this->assertTrue(minc_hiring_get_candidate($reporter_id)['previous_applicant']);
    }

    private function parse_html($html) {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        return new DOMXPath($document);
    }

    public static function previous_decisions() {
        return [['offer'], ['rejected']];
    }

    /** @dataProvider previous_decisions */
    public function test_previous_applicant_marker_is_visible_on_both_screens($decision) {
        [$writer_id, $reporter_id] = $this->apply_to_both();
        Application::update_status($writer_id, $decision);
        wp_set_current_user(self::factory()->user->create(['role' => 'minc_hiring_staff']));

        $dashboard = $this->parse_html(render_block([
            'blockName' => 'minc-hiring/candidate-dashboard', 'attrs' => [],
        ]));
        $this->assertSame(1, $dashboard->query('//div[@role="row"][@data-position="Data reporter"]//sup[@title="Past applicant"][text()="*"]')->length);
        $this->assertSame(0, $dashboard->query('//div[@role="row"][@data-position="Staff writer"]//sup[@title="Past applicant"]')->length);

        $_GET['application'] = $reporter_id;
        $detail = $this->parse_html(render_block([
            'blockName' => 'minc-hiring/candidate-detail', 'attrs' => [],
        ]));
        $this->assertSame(1, $detail->query('//h1/sup[@title="Past applicant"][text()="*"]')->length);
        $this->assertSame(1, $detail->query('//h1/span[contains(@class, "screen-reader-text")][contains(text(), "Past applicant")]')->length);
        $legend = $detail->query('//section[@aria-labelledby="massinc-notes-title"]/following-sibling::p[1][@id="massinc-previous-applicant-note"]');
        $this->assertSame(1, $legend->length, 'The explanation belongs below the Notes section.');
        $this->assertSame('* Past applicant: previously applied and received an offer or was rejected.', $legend->item(0)->textContent);

        // The earlier application itself is not marked as a return visit.
        $_GET['application'] = $writer_id;
        $original = $this->parse_html(render_block([
            'blockName' => 'minc-hiring/candidate-detail', 'attrs' => [],
        ]));
        $this->assertSame(0, $original->query('//h1/sup[@title="Past applicant"]')->length);
        $this->assertSame(0, $original->query('//*[@id="massinc-previous-applicant-note"]')->length);
    }

    public function test_in_progress_applications_have_no_previous_applicant_marker() {
        [$writer_id, $reporter_id] = $this->apply_to_both();
        wp_set_current_user(self::factory()->user->create(['role' => 'minc_hiring_staff']));
        $dashboard = $this->parse_html(render_block([
            'blockName' => 'minc-hiring/candidate-dashboard', 'attrs' => [],
        ]));
        $this->assertSame(0, $dashboard->query('//sup[@title="Past applicant"]')->length);

        $_GET['application'] = $reporter_id;
        $detail = $this->parse_html(render_block([
            'blockName' => 'minc-hiring/candidate-detail', 'attrs' => [],
        ]));
        $this->assertSame(0, $detail->query('//h1/sup[@title="Past applicant"]')->length);
        $this->assertSame(0, $detail->query('//*[@id="massinc-previous-applicant-note"]')->length);
    }

    public function test_the_sample_data_shows_one_person_under_two_positions() {
        $_GET['demo'] = '1';
        $maya = array_values(array_filter(minc_hiring_get_candidates(), function ($row) {
            return $row['name'] === 'Maya Okonkwo';
        }));
        unset($_GET['demo']);

        $this->assertCount(2, $maya);
        $this->assertEqualsCanonicalizing(['Staff writer', 'Data reporter'], array_column($maya, 'position'));
        $this->assertSame([false, false], array_column($maya, 'previous_applicant'), 'Both of her applications are still in progress.');
        $this->assertNotSame(
            array_column($maya[0]['materials'], 'name'),
            array_column($maya[1]['materials'], 'name'),
            'Each sample application shows its own materials.'
        );
    }
}
