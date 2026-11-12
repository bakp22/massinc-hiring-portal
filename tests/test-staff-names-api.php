<?php
use MassINC\StaffNames;

/**
 * Covers POST /massinc/v1/staff-names and the list behind the
 * "Select your name" dropdowns.
 */
class Test_Staff_Names_API extends WP_UnitTestCase {

    public function set_up() {
        parent::set_up();
        delete_option(StaffNames::OPTION);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    private function add($name) {
        $request = new WP_REST_Request('POST', '/massinc/v1/staff-names');
        $request->set_param('name', $name);
        return rest_get_server()->dispatch($request);
    }

    public function test_list_starts_empty() {
        $this->assertSame([], StaffNames::all());
    }

    public function test_adding_a_name_saves_it() {
        $response = $this->add('Dana Whitfield');

        $this->assertSame(201, $response->get_status());
        $this->assertSame(['name' => 'Dana Whitfield', 'created' => true], $response->get_data());
        $this->assertSame(['Dana Whitfield'], StaffNames::all());
    }

    public function test_names_are_listed_alphabetically_ignoring_case() {
        $this->add('nadia Chen');
        $this->add('Luis Ferrer');
        $this->add('Dana Whitfield');

        $this->assertSame(['Dana Whitfield', 'Luis Ferrer', 'nadia Chen'], StaffNames::all());
    }

    public function test_adding_an_existing_name_ignores_case_and_keeps_original_spelling() {
        $this->add('Dana Whitfield');
        $response = $this->add('  dana   WHITFIELD ');

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['name' => 'Dana Whitfield', 'created' => false], $response->get_data());
        $this->assertSame(['Dana Whitfield'], StaffNames::all());
    }

    public function test_extra_spaces_and_markup_are_cleaned() {
        $response = $this->add("  Luis \t  <b>Ferrer</b> ");

        $this->assertSame(201, $response->get_status());
        $this->assertSame('Luis Ferrer', $response->get_data()['name']);
    }

    public function test_blank_name_is_rejected() {
        foreach (['', '   ', '<b></b>'] as $blank) {
            $response = $this->add($blank);
            $this->assertSame(400, $response->get_status(), 'Rejected: ' . var_export($blank, true));
        }
        $this->assertSame([], StaffNames::all());
    }

    public function test_missing_name_is_rejected() {
        $request = new WP_REST_Request('POST', '/massinc/v1/staff-names');
        $this->assertSame(400, rest_get_server()->dispatch($request)->get_status());
    }

    public function test_overlong_name_is_rejected() {
        $response = $this->add(str_repeat('a', StaffNames::MAX_LENGTH + 1));

        $this->assertSame(400, $response->get_status());
        $this->assertSame('massinc_name_too_long', $response->get_data()['code']);
        $this->assertSame([], StaffNames::all());
    }

    public function test_list_size_is_capped() {
        update_option(StaffNames::OPTION, array_map(function ($i) {
            return "Person $i";
        }, range(1, StaffNames::MAX_NAMES)));

        $response = $this->add('One Too Many');

        $this->assertSame(400, $response->get_status());
        $this->assertSame('massinc_too_many_names', $response->get_data()['code']);
        $this->assertCount(StaffNames::MAX_NAMES, StaffNames::all());
    }

    public function test_signed_out_visitors_cannot_add_names() {
        wp_set_current_user(0);

        $this->assertSame(401, $this->add('Dana Whitfield')->get_status());
        $this->assertSame([], StaffNames::all());
    }

    public function test_signed_in_users_without_hiring_access_cannot_add_names() {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertSame(403, $this->add('Dana Whitfield')->get_status());
        $this->assertSame([], StaffNames::all());
    }

    private function remove($name) {
        $request = new WP_REST_Request('DELETE', '/massinc/v1/staff-names');
        $request->set_param('name', $name);
        return rest_get_server()->dispatch($request);
    }

    public function test_removing_a_name_takes_it_off_the_list() {
        $this->add('Dana Whitfield');
        $this->add('Luis Ferrer');

        $response = $this->remove('Dana Whitfield');

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['name' => 'Dana Whitfield', 'removed' => true], $response->get_data());
        $this->assertSame(['Luis Ferrer'], StaffNames::all());
    }

    public function test_removing_ignores_case_and_extra_spaces() {
        $this->add('Dana Whitfield');

        $this->assertTrue($this->remove('  dana   WHITFIELD ')->get_data()['removed']);
        $this->assertSame([], StaffNames::all());
    }

    public function test_removing_a_name_that_is_not_listed_changes_nothing() {
        $this->add('Dana Whitfield');

        $response = $this->remove('Someone Else');

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($response->get_data()['removed']);
        $this->assertSame(['Dana Whitfield'], StaffNames::all());
    }

    public function test_a_removed_name_can_be_added_again() {
        $this->add('Dana Whitfield');
        $this->remove('Dana Whitfield');

        $this->assertSame(201, $this->add('Dana Whitfield')->get_status());
        $this->assertSame(['Dana Whitfield'], StaffNames::all());
    }

    public function test_removing_a_name_leaves_applications_and_candidates_alone() {
        global $wpdb;
        $tables = ['massinc_jobs', 'massinc_candidates', 'massinc_applications', 'massinc_application_attachments'];
        $count = function () use ($wpdb, $tables) {
            return array_map(function ($table) use ($wpdb) {
                return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
            }, $tables);
        };

        $this->add('Dana Whitfield');
        $before = $count();
        $this->remove('Dana Whitfield');

        $this->assertSame($before, $count());
    }

    public function test_signed_out_visitors_cannot_remove_names() {
        $this->add('Dana Whitfield');
        wp_set_current_user(0);

        $this->assertSame(401, $this->remove('Dana Whitfield')->get_status());
        $this->assertSame(['Dana Whitfield'], StaffNames::all());
    }

    public function test_signed_in_users_without_hiring_access_cannot_remove_names() {
        $this->add('Dana Whitfield');
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertSame(403, $this->remove('Dana Whitfield')->get_status());
        $this->assertSame(['Dana Whitfield'], StaffNames::all());
    }

    public function test_corrupt_stored_value_is_treated_as_empty() {
        update_option(StaffNames::OPTION, 'not an array');

        $this->assertSame([], StaffNames::all());
        $this->assertSame(201, $this->add('Dana Whitfield')->get_status());
    }
}
