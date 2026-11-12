<?php
use MassINC\Models\Application;
use MassINC\Models\Candidate;
use MassINC\Models\Job;
use MassINC\Models\Note;
use MassINC\Models\Rating;
use MassINC\StaffNames;

/**
 * Covers POST /massinc/v1/applications/{id}/ratings and /notes, and how saved
 * ratings and notes feed the dashboard and candidate page.
 */
class Test_Ratings_Notes_API extends WP_UnitTestCase {

    private $application_id;

    public function set_up() {
        parent::set_up();
        delete_option(StaffNames::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

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
            'phone'      => '6175550142',
        ]);
        $this->application_id = Application::create(['job_id' => $job_id, 'candidate_id' => $candidate_id]);
    }

    private function post($path, array $params) {
        $request = new WP_REST_Request('POST', '/massinc/v1/applications/' . $path);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }
        return rest_get_server()->dispatch($request);
    }

    private function rate($reviewer, $score, $application_id = null) {
        return $this->post(($application_id ?? $this->application_id) . '/ratings', ['reviewer' => $reviewer, 'score' => $score]);
    }

    private function note($author, $text, $application_id = null) {
        return $this->post(($application_id ?? $this->application_id) . '/notes', ['author' => $author, 'text' => $text]);
    }

    private function row_count($table) {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE application_id = {$this->application_id}");
    }

    /* ---------------- ratings ---------------- */

    public function test_first_rating_is_saved() {
        $response = $this->rate('Dana Whitfield', 4);

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('Dana Whitfield', $data['reviewer']);
        $this->assertSame(4, $data['score']);
        $this->assertTrue($data['created']);
        $this->assertNotEmpty($data['date']);

        $saved = Rating::get_for_application($this->application_id);
        $this->assertCount(1, $saved);
        $this->assertSame('Dana Whitfield', $saved[0]->get('reviewer_name'));
        $this->assertSame(4, $saved[0]->get_score());
    }

    public function test_rating_again_replaces_that_reviewers_old_rating() {
        $this->rate('Dana Whitfield', 2);
        $response = $this->rate('Dana Whitfield', 5);

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($response->get_data()['created']);

        $saved = Rating::get_for_application($this->application_id);
        $this->assertCount(1, $saved, 'The old rating is erased, not kept next to the new one.');
        $this->assertSame(5, $saved[0]->get_score());
    }

    public function test_the_same_reviewer_is_matched_ignoring_case_and_spacing() {
        $this->rate('Dana Whitfield', 2);
        $this->rate('  dana   WHITFIELD ', 3);

        $this->assertSame(1, $this->row_count('massinc_ratings'));
        $this->assertSame(3, Rating::get_for_application($this->application_id)[0]->get_score());
    }

    public function test_reviewer_is_spelled_as_on_the_staff_list() {
        StaffNames::add('Dana Whitfield');

        $response = $this->rate('dana whitfield', 4);

        $this->assertSame('Dana Whitfield', $response->get_data()['reviewer']);
        $this->assertSame('Dana Whitfield', Rating::get_for_application($this->application_id)[0]->get('reviewer_name'));
    }

    public function test_a_reviewer_who_is_not_on_the_staff_list_can_still_rate() {
        $this->assertSame(201, $this->rate('Someone New', 3)->get_status());
    }

    public function test_different_reviewers_each_keep_their_own_rating() {
        $this->rate('Dana Whitfield', 4);
        $this->rate('Luis Ferrer', 5);
        $this->rate('Nadia Chen', 4);

        $this->assertSame(3, $this->row_count('massinc_ratings'));
    }

    public function test_same_reviewer_can_rate_different_applications() {
        $other = Application::create([
            'job_id'       => Job::create(['title' => 'Other', 'description' => 'x', 'status' => 'open']),
            'candidate_id' => Candidate::create(['first_name' => 'Bob', 'last_name' => 'Smith', 'email' => 'bob@example.com']),
        ]);

        $this->assertSame(201, $this->rate('Dana Whitfield', 4)->get_status());
        $this->assertSame(201, $this->rate('Dana Whitfield', 2, $other)->get_status());
        $this->assertSame(4, Rating::get_for_application($this->application_id)[0]->get_score());
        $this->assertSame(2, Rating::get_for_application($other)[0]->get_score());
    }

    public function test_scores_must_be_whole_numbers_from_1_to_5() {
        foreach ([0, 6, -1, 'abc', 3.5, ''] as $bad) {
            $this->assertSame(400, $this->rate('Dana Whitfield', $bad)->get_status(), 'Rejected: ' . var_export($bad, true));
        }
        $this->assertSame(0, $this->row_count('massinc_ratings'));

        foreach ([1, 2, 3, 4, 5, '4'] as $good) {
            $this->assertContains($this->rate('Dana Whitfield', $good)->get_status(), [200, 201], 'Accepted: ' . var_export($good, true));
        }
    }

    public function test_a_reviewer_name_is_required() {
        foreach (['', '   ', '<b></b>'] as $blank) {
            $this->assertSame(400, $this->rate($blank, 4)->get_status(), 'Rejected: ' . var_export($blank, true));
        }
        $this->assertSame(
            400,
            $this->post($this->application_id . '/ratings', ['score' => 4])->get_status()
        );
        $this->assertSame(0, $this->row_count('massinc_ratings'));
    }

    public function test_overlong_reviewer_name_is_rejected() {
        $response = $this->rate(str_repeat('a', StaffNames::MAX_LENGTH + 1), 4);

        $this->assertSame(400, $response->get_status());
        $this->assertSame(0, $this->row_count('massinc_ratings'));
    }

    public function test_rating_an_unknown_application_is_not_found() {
        $this->assertSame(404, $this->rate('Dana Whitfield', 4, 999999)->get_status());
    }

    public function test_signed_out_visitors_cannot_rate() {
        wp_set_current_user(0);

        $this->assertSame(401, $this->rate('Dana Whitfield', 4)->get_status());
        $this->assertSame(0, $this->row_count('massinc_ratings'));
    }

    public function test_signed_in_users_without_hiring_access_cannot_rate() {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertSame(403, $this->rate('Dana Whitfield', 4)->get_status());
        $this->assertSame(0, $this->row_count('massinc_ratings'));
    }

    public function test_one_reviewer_cannot_have_two_rows_for_an_application() {
        global $wpdb;
        $this->rate('Dana Whitfield', 4);

        $suppress = $wpdb->suppress_errors(true);
        $duplicate = $wpdb->insert($wpdb->prefix . 'massinc_ratings', [
            'application_id' => $this->application_id,
            'reviewer_name'  => 'Dana Whitfield',
            'score'          => 2,
        ]);
        $wpdb->suppress_errors($suppress);

        $this->assertFalse($duplicate, 'The unique key backs up the replace-on-rate rule.');
    }

    /* ---------------- notes ---------------- */

    public function test_a_note_is_saved() {
        $response = $this->note('Dana Whitfield', 'Phone screen went well.');

        $this->assertSame(201, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('Dana Whitfield', $data['author']);
        $this->assertSame('Phone screen went well.', $data['text']);
        $this->assertNotEmpty($data['date']);

        $saved = Note::get_for_application($this->application_id);
        $this->assertCount(1, $saved);
        $this->assertSame('Phone screen went well.', $saved[0]->get('body'));
        $this->assertSame('Dana Whitfield', $saved[0]->get('author_name'));
    }

    public function test_notes_are_listed_newest_first() {
        $this->note('Dana Whitfield', 'first');
        $this->note('Luis Ferrer', 'second');
        $this->note('Dana Whitfield', 'third');

        $bodies = array_map(function ($note) {
            return $note->get('body');
        }, Note::get_for_application($this->application_id));

        $this->assertSame(['third', 'second', 'first'], $bodies);
    }

    public function test_note_keeps_line_breaks_but_not_markup() {
        $response = $this->note('Dana Whitfield', "  Line one\nLine <b>two</b><script>alert(1)</script>  ");

        $this->assertSame("Line one\nLine two", $response->get_data()['text']);
    }

    public function test_a_note_needs_an_author_and_some_text() {
        $this->assertSame(400, $this->note('', 'Hello')->get_status());
        $this->assertSame(400, $this->note('Dana Whitfield', '   ')->get_status());
        $this->assertSame(400, $this->note('Dana Whitfield', '<b></b>')->get_status());
        $this->assertSame(0, $this->row_count('massinc_notes'));
    }

    public function test_overlong_note_is_rejected() {
        $response = $this->note('Dana Whitfield', str_repeat('a', Note::MAX_LENGTH + 1));

        $this->assertSame(400, $response->get_status());
        $this->assertSame('massinc_note_too_long', $response->get_data()['code']);
        $this->assertSame(0, $this->row_count('massinc_notes'));
    }

    public function test_noting_an_unknown_application_is_not_found() {
        $this->assertSame(404, $this->note('Dana Whitfield', 'Hi', 999999)->get_status());
    }

    public function test_signed_out_visitors_and_non_staff_cannot_add_notes() {
        wp_set_current_user(0);
        $this->assertSame(401, $this->note('Dana Whitfield', 'Hi')->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->note('Dana Whitfield', 'Hi')->get_status());

        $this->assertSame(0, $this->row_count('massinc_notes'));
    }

    public function test_notes_cannot_be_edited_or_deleted() {
        $id = $this->note('Dana Whitfield', 'Keep me')->get_data()['id'];

        foreach (['PUT', 'PATCH', 'DELETE'] as $method) {
            foreach (["/massinc/v1/applications/{$this->application_id}/notes", "/massinc/v1/applications/{$this->application_id}/notes/{$id}"] as $route) {
                $status = rest_get_server()->dispatch(new WP_REST_Request($method, $route))->get_status();
                $this->assertContains($status, [404, 405], "$method $route");
            }
        }
        $this->assertSame(1, $this->row_count('massinc_notes'));
    }

    /* ---------------- staff list and saved work ---------------- */

    public function test_removing_a_name_from_the_staff_list_keeps_its_ratings_and_notes() {
        StaffNames::add('Dana Whitfield');
        $this->rate('Dana Whitfield', 4);
        $this->note('Dana Whitfield', 'A note that should stay.');

        StaffNames::remove('Dana Whitfield');

        $this->assertSame([], StaffNames::all());
        $this->assertSame(1, $this->row_count('massinc_ratings'));
        $this->assertSame(1, $this->row_count('massinc_notes'));
        $this->assertSame('Dana Whitfield', Rating::get_for_application($this->application_id)[0]->get('reviewer_name'));
        $this->assertSame('Dana Whitfield', Note::get_for_application($this->application_id)[0]->get('author_name'));
    }

    /* ---------------- dashboard and candidate page data ---------------- */

    private function candidate_row($id) {
        foreach (minc_hiring_get_candidates() as $candidate) {
            if ($candidate['id'] === $id) {
                return $candidate;
            }
        }
        return null;
    }

    public function test_dashboard_shows_not_rated_until_someone_rates() {
        $row = $this->candidate_row($this->application_id);

        $this->assertNull($row['rating']);
        $this->assertSame(0, $row['count']);
    }

    public function test_dashboard_average_and_count_come_from_saved_ratings() {
        $this->rate('Dana Whitfield', 5);
        $this->rate('Luis Ferrer', 4);
        $this->rate('Nadia Chen', 4);

        $row = $this->candidate_row($this->application_id);

        $this->assertEqualsWithDelta(4.33, $row['rating'], 0.01);
        $this->assertSame(3, $row['count']);
    }

    public function test_changing_a_rating_changes_the_dashboard_average_without_adding_to_the_count() {
        $this->rate('Dana Whitfield', 2);
        $this->rate('Luis Ferrer', 4);
        $this->rate('Dana Whitfield', 4);

        $row = $this->candidate_row($this->application_id);

        $this->assertEqualsWithDelta(4.0, $row['rating'], 0.001);
        $this->assertSame(2, $row['count']);
    }

    public function test_candidate_page_loads_saved_ratings_and_notes() {
        $this->rate('Dana Whitfield', 4);
        $this->note('Luis Ferrer', 'Sent her clips to the editing team.');

        $candidate = minc_hiring_get_candidate($this->application_id);

        $this->assertCount(1, $candidate['reviews']);
        $this->assertSame('Dana Whitfield', $candidate['reviews'][0]['reviewer']);
        $this->assertSame(4, $candidate['reviews'][0]['score']);
        $this->assertArrayNotHasKey('comment', $candidate['reviews'][0]);
        $this->assertCount(1, $candidate['notes']);
        $this->assertSame('Luis Ferrer', $candidate['notes'][0]['author']);
        $this->assertSame('Sent her clips to the editing team.', $candidate['notes'][0]['text']);
    }
}
