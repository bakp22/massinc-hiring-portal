<?php
/**
 * Links between the portal's pages: jobs → application form → back to jobs,
 * dashboard ↔ candidate page, and keeping those links current when pages
 * are published or removed.
 */
use MassINC\Models\Job;

class Test_Page_Navigation extends WP_UnitTestCase {

    private function page($block, $status = 'publish') {
        return self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => $status,
            'post_content' => '<!-- wp:' . $block . ' /-->',
        ]);
    }

    private function render_page($page_id, array $get = []) {
        $this->go_to(get_permalink($page_id));
        // go_to() resets the query string, so set it afterwards.
        foreach ($get as $key => $value) {
            $_GET[$key] = $value;
        }
        return do_blocks(get_post($page_id)->post_content);
    }

    private function open_job($title = 'Policy research intern') {
        return Job::create([
            'title'           => $title,
            'description'     => 'Spring 2027',
            'location'        => 'Boston, MA',
            'employment_type' => 'Internship',
            'status'          => 'open',
        ]);
    }

    public function tear_down() {
        $_GET = [];
        parent::tear_down();
    }

    /* ---------------- jobs and the application form ---------------- */

    public function test_a_job_links_to_the_form_on_its_own_page() {
        $job_id  = $this->open_job();
        $jobs    = $this->page('minc-hiring/job-list');
        $form    = $this->page('minc-hiring/application-form');

        $html = $this->render_page($jobs);

        $expected = esc_url(add_query_arg('job_id', $job_id, get_permalink($form)));
        $this->assertStringContainsString('href="' . $expected . '"', $html);
    }

    public function test_a_job_links_to_the_same_page_when_list_and_form_share_it() {
        $job_id = $this->open_job();
        $careers = self::factory()->post->create([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_content' => "<!-- wp:minc-hiring/job-list /-->\n<!-- wp:minc-hiring/application-form /-->",
        ]);

        $html = $this->render_page($careers);

        $expected = esc_url(add_query_arg('job_id', $job_id, get_permalink($careers)));
        $this->assertStringContainsString('href="' . $expected . '"', $html);
    }

    public function test_following_a_job_link_shows_the_form_for_that_job() {
        $job_id = $this->open_job('Staff writer');
        $form   = $this->page('minc-hiring/application-form');

        $html = $this->render_page($form, ['job_id' => (string) $job_id]);

        $this->assertStringContainsString('Staff writer', $html);
        $this->assertStringContainsString('name="job_id" value="' . $job_id . '"', $html);
    }

    public function test_all_positions_goes_back_to_the_job_list_page() {
        $jobs = $this->page('minc-hiring/job-list');
        $form = $this->page('minc-hiring/application-form');

        $html = $this->render_page($form, ['job_id' => '999999']);

        $this->assertStringContainsString("This position isn't open", $html);
        $this->assertStringContainsString('href="' . esc_url(get_permalink($jobs)) . '"', $html);
    }

    public function test_a_draft_form_page_is_not_linked_to() {
        $job_id = $this->open_job();
        $jobs   = $this->page('minc-hiring/job-list');
        $this->page('minc-hiring/application-form', 'draft');

        $html = $this->render_page($jobs);

        $expected = esc_url(add_query_arg('job_id', $job_id, get_permalink($jobs)));
        $this->assertStringContainsString('href="' . $expected . '"', $html);
    }

    /* ---------------- staff header ---------------- */

    public function test_the_staff_header_links_back_to_the_candidates_page() {
        $dashboard = $this->page('minc-hiring/candidate-dashboard');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        minc_hiring_register_roles();

        ob_start();
        minc_hiring_admin_nav();
        $html = ob_get_clean();

        $this->assertMatchesRegularExpression(
            '#<a class="massinc-nav-tab is-active" href="' . preg_quote(esc_url(get_permalink($dashboard)), '#') . '">Candidates</a>#',
            $html
        );
    }

    private function nav_html($role) {
        wp_set_current_user(self::factory()->user->create(['role' => $role]));
        minc_hiring_register_roles();
        ob_start();
        minc_hiring_admin_nav(true);
        return ob_get_clean();
    }

    private function nav_labels($html) {
        preg_match_all('#class="massinc-nav-tab[^"]*"[^>]*>([^<]+)</a>#', $html, $m);
        return $m[1];
    }

    public function test_staff_header_shows_only_candidates() {
        $this->assertSame(['Candidates'], $this->nav_labels($this->nav_html('minc_hiring_staff')));
    }

    public function test_admin_header_falls_back_to_wp_admin_without_portal_pages() {
        $html = $this->nav_html('administrator');

        $this->assertSame(['Candidates', 'Jobs', 'Settings'], $this->nav_labels($html));
        $this->assertStringContainsString('href="' . esc_url(admin_url('admin.php?page=minc-hiring')) . '"', $html);
        $this->assertStringContainsString('href="' . esc_url(admin_url('admin.php?page=minc-hiring-emails')) . '"', $html);
    }

    public function test_admin_header_jobs_link_goes_to_the_jobs_page() {
        $jobs     = $this->page('minc-hiring/job-list');
        $settings = $this->page('minc-hiring/email-settings');

        $html = $this->nav_html('administrator');

        $this->assertStringContainsString('href="' . esc_url(get_permalink($jobs)) . '"', $html);
        $this->assertStringContainsString('href="' . esc_url(get_permalink($settings)) . '"', $html);
    }

    public function test_only_the_current_section_is_highlighted() {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        minc_hiring_register_roles();
        ob_start();
        minc_hiring_admin_nav(false, 'jobs');
        $html = ob_get_clean();

        $this->assertSame(1, substr_count($html, 'is-active'));
        $this->assertMatchesRegularExpression('#is-active"[^>]*aria-current="page">Jobs<#', $html);
    }

    /* ---------------- which header a page gets ---------------- */

    private function template_for($page_id) {
        $this->go_to(get_permalink($page_id));
        return minc_hiring_portal_template('theme-page.php');
    }

    public function test_staff_pages_use_the_portal_template_without_the_site_header() {
        $portal = MINC_HIRING_PATH . 'templates/portal-page.php';

        $this->assertSame($portal, $this->template_for($this->page('minc-hiring/staff-login')));
        $this->assertSame($portal, $this->template_for($this->page('minc-hiring/candidate-dashboard')));
        $this->assertSame($portal, $this->template_for($this->page('minc-hiring/candidate-detail')));
        $this->assertFileExists($portal);
    }

    public function test_public_pages_keep_the_site_header() {
        $this->assertSame('theme-page.php', $this->template_for($this->page('minc-hiring/job-list')));
        $this->assertSame('theme-page.php', $this->template_for($this->page('minc-hiring/application-form')));
        $this->assertSame('theme-page.php', $this->template_for($this->page('core/paragraph')));
    }

    public function test_signed_in_staff_and_admins_get_the_portal_template_on_jobs_pages() {
        $portal = MINC_HIRING_PATH . 'templates/portal-page.php';
        $jobs   = $this->page('minc-hiring/job-list');
        $form   = $this->page('minc-hiring/application-form');
        minc_hiring_register_roles();

        foreach (['administrator', 'minc_hiring_staff'] as $role) {
            wp_set_current_user(self::factory()->user->create(['role' => $role]));
            $this->assertSame($portal, $this->template_for($jobs), "$role on Jobs");
            $this->assertSame($portal, $this->template_for($form), "$role on the form");
            $this->assertSame('jobs', minc_hiring_portal_nav_section(get_post($jobs)));
        }
    }

    public function test_applicants_and_other_accounts_see_jobs_pages_in_the_site_header() {
        $jobs = $this->page('minc-hiring/job-list');

        wp_set_current_user(0);
        $this->assertSame('theme-page.php', $this->template_for($jobs));

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame('theme-page.php', $this->template_for($jobs));
        $this->assertSame('', minc_hiring_portal_nav_section(get_post($jobs)));
    }

    public function test_staff_pages_draw_their_own_header_not_a_second_one() {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        minc_hiring_register_roles();

        $this->assertSame('', minc_hiring_portal_nav_section(get_post($this->page('minc-hiring/candidate-dashboard'))));
    }

    public function test_a_site_can_keep_its_own_header_on_staff_pages() {
        add_filter('minc_hiring_use_portal_template', '__return_false');

        $this->assertSame('theme-page.php', $this->template_for($this->page('minc-hiring/candidate-dashboard')));

        remove_filter('minc_hiring_use_portal_template', '__return_false');
    }

    /* ---------------- remembered page URLs ---------------- */

    public function test_publishing_a_sign_in_page_updates_links_straight_away() {
        $this->assertSame(wp_login_url(), minc_hiring_login_url());

        $login = $this->page('minc-hiring/staff-login', 'draft');
        $this->assertSame(wp_login_url(), minc_hiring_login_url());

        wp_update_post(['ID' => $login, 'post_status' => 'publish']);
        $this->assertSame(get_permalink($login), minc_hiring_login_url());
    }

    public function test_trashing_a_page_stops_links_pointing_at_it() {
        $jobs = $this->page('minc-hiring/job-list');
        $this->assertSame(get_permalink($jobs), minc_hiring_find_page_url('minc-hiring/job-list'));

        wp_trash_post($jobs);
        $this->assertSame('', minc_hiring_find_page_url('minc-hiring/job-list'));
    }
}
