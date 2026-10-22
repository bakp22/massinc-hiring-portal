<?php
use MassINC\Attachments;
use MassINC\Models\Attachment;
use MassINC\Models\Candidate;
use MassINC\Models\Job;

/**
 * Covers attachments on POST /massinc/v1/applications and
 * GET /massinc/v1/attachments/{id}/download.
 */
class Test_Attachments_API extends WP_UnitTestCase {

    private $job_id;

    public function set_up() {
        parent::set_up();
        $this->job_id = Job::create([
            'title'           => 'Test Job',
            'description'     => 'A test job description.',
            'location'        => 'Remote',
            'employment_type' => 'full-time',
            'status'          => 'open',
        ]);
    }

    public function tear_down() {
        // Uploaded files live on disk, outside the test transaction.
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

    private function upload($name = 'resume.pdf', $contents = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n") {
        $tmp = wp_tempnam($name);
        file_put_contents($tmp, $contents);
        return [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp),
        ];
    }

    private function docx_upload($name = 'cover.docx') {
        $tmp = wp_tempnam($name);
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<document/>');
        $zip->close();
        return [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $tmp,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp),
        ];
    }

    private function submit(array $files, array $extra = []) {
        $request = new WP_REST_Request('POST', '/massinc/v1/applications');
        foreach (array_merge([
            'first_name'      => 'Jane',
            'last_name'       => 'Doe',
            'applicant_email' => 'jane@example.com',
            'job_id'          => $this->job_id,
        ], $extra) as $key => $value) {
            $request->set_param($key, $value);
        }
        $request->set_file_params($files);
        return rest_get_server()->dispatch($request);
    }

    private function count_rows($table) {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
    }

    private function download($attachment_id) {
        return rest_get_server()->dispatch(
            new WP_REST_Request('GET', "/massinc/v1/attachments/{$attachment_id}/download")
        );
    }

    public function test_files_and_links_are_stored_privately() {
        $response = $this->submit(
            [
                'resume'          => $this->upload(),
                'cover_letter'    => $this->docx_upload(),
                'writing_samples' => [
                    'name'     => ['a.pdf', 'b.pdf'],
                    'type'     => ['', ''],
                    'tmp_name' => [$this->upload('a.pdf')['tmp_name'], $this->upload('b.pdf')['tmp_name']],
                    'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                    'size'     => [20, 20],
                ],
            ],
            ['sample_urls' => ['https://example.com/one', ' ', 'https://example.com/two']]
        );

        $this->assertSame(201, $response->get_status());
        $attachments = Attachment::get_for_application($response->get_data()['application_id']);
        $kinds = array_map(function ($a) { return $a->get_kind(); }, $attachments);
        $this->assertSame(
            ['resume', 'cover_letter', 'writing_sample', 'writing_sample', 'link', 'link'],
            $kinds
        );

        $resume = $attachments[0];
        $this->assertSame('resume.pdf', $resume->get('original_name'));
        $this->assertSame('application/pdf', $resume->get('mime_type'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.pdf$/', $resume->get('stored_name'));
        $this->assertFileExists(Attachments::path_for($resume->get('stored_name')));
        $this->assertSame('https://example.com/one', $attachments[4]->get('url'));
    }

    public function test_storage_folder_denies_direct_access() {
        $dir = Attachments::directory();
        $this->assertFileExists($dir . '/index.php');
        $this->assertStringContainsString('Require all denied', file_get_contents($dir . '/.htaccess'));
    }

    public function test_resume_is_required_and_nothing_is_created() {
        $response = $this->submit([]);

        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('resume', $response->as_error()->get_error_data()['params']);
        $this->assertNull(Candidate::get_by_email('jane@example.com'));
        $this->assertSame(0, $this->count_rows('massinc_applications'));
    }

    public function test_rejects_unsupported_extension() {
        $response = $this->submit(['resume' => $this->upload('resume.exe', 'MZ binary')]);
        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('resume', $response->as_error()->get_error_data()['params']);
    }

    public function test_rejects_legacy_doc_files() {
        $response = $this->submit(['resume' => $this->upload('resume.doc', "\xD0\xCF\x11\xE0")]);
        $this->assertSame(400, $response->get_status());
    }

    public function test_rejects_file_whose_contents_do_not_match_its_extension() {
        $response = $this->submit(['resume' => $this->upload('resume.pdf', '<?php echo "not a pdf";')]);
        $this->assertSame(400, $response->get_status());
        $this->assertSame(0, $this->count_rows('massinc_applications'));

        $fake_docx = $this->submit(['resume' => $this->upload('resume.docx', 'PK just text')]);
        $this->assertSame(400, $fake_docx->get_status());
    }

    public function test_rejects_oversized_and_empty_files() {
        $big = $this->upload('big.pdf', '%PDF-' . str_repeat('a', Attachments::MAX_BYTES));
        $this->assertSame(400, $this->submit(['resume' => $big])->get_status());

        $this->assertSame(400, $this->submit(['resume' => $this->upload('empty.pdf', '')])->get_status());
    }

    public function test_rejects_php_upload_size_errors() {
        $file = $this->upload();
        $file['error'] = UPLOAD_ERR_INI_SIZE;
        $response = $this->submit(['resume' => $file]);

        $this->assertSame(400, $response->get_status());
        $this->assertStringContainsString('10 MB', $response->as_error()->get_error_message());
    }

    public function test_limits_writing_samples_to_three() {
        $names = ['1.pdf', '2.pdf', '3.pdf', '4.pdf'];
        $response = $this->submit([
            'resume'          => $this->upload(),
            'writing_samples' => [
                'name'     => $names,
                'type'     => array_fill(0, 4, ''),
                'tmp_name' => array_map(function ($n) { return $this->upload($n)['tmp_name']; }, $names),
                'error'    => array_fill(0, 4, UPLOAD_ERR_OK),
                'size'     => array_fill(0, 4, 20),
            ],
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('writing_samples', $response->as_error()->get_error_data()['params']);
    }

    public function test_rejects_invalid_links() {
        foreach (['not a url', 'javascript:alert(1)', 'ftp://example.com/file'] as $bad) {
            $response = $this->submit(['resume' => $this->upload()], ['sample_urls' => [$bad]]);
            $this->assertSame(400, $response->get_status(), $bad);
            $this->assertArrayHasKey('sample_url', $response->as_error()->get_error_data()['params']);
        }
    }

    public function test_limits_links_to_five() {
        $links = array_map(function ($i) { return "https://example.com/{$i}"; }, range(1, 6));
        $response = $this->submit(['resume' => $this->upload()], ['sample_urls' => $links]);
        $this->assertSame(400, $response->get_status());
    }

    public function test_download_requires_login_and_permission() {
        $response = $this->submit(['resume' => $this->upload()]);
        $attachment = Attachment::get_for_application($response->get_data()['application_id'])[0];

        wp_set_current_user(0);
        $this->assertSame(401, $this->download($attachment->get_id())->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertSame(403, $this->download($attachment->get_id())->get_status());

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame(200, $this->download($attachment->get_id())->get_status());
    }

    public function test_download_returns_404_for_missing_files_and_links() {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertSame(404, $this->download(999999)->get_status());

        $response = $this->submit(['resume' => $this->upload()], ['sample_urls' => ['https://example.com/x']]);
        $link = Attachment::get_for_application($response->get_data()['application_id'])[1];
        $this->assertSame(404, $this->download($link->get_id())->get_status());
    }

    public function test_path_for_rejects_names_we_did_not_generate() {
        $this->assertSame('', Attachments::path_for('../../wp-config.php'));
        $this->assertSame('', Attachments::path_for('resume.pdf'));
    }
}
