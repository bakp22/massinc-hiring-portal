<?php
namespace MassINC;

use MassINC\Models\Attachment;

defined('ABSPATH') || exit;

/**
 * Validation and private storage for application attachments.
 *
 * Files are saved under a random name in a protected folder inside the
 * uploads directory. They are never served by URL: staff download them
 * through GET /massinc/v1/attachments/{id}/download, which checks
 * permissions first.
 */
class Attachments {
    const MAX_BYTES           = 10 * 1024 * 1024;
    const MAX_WRITING_SAMPLES = 3;
    const MAX_LINKS           = 5;
    const MAX_URL_LENGTH      = 2048;
    const DIRECTORY           = 'massinc-hiring';

    const MIME_TYPES = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Group uploaded files by field: resume, cover_letter, writing_samples.
     * Empty file inputs (UPLOAD_ERR_NO_FILE) are ignored.
     *
     * @param array $file_params $_FILES-style array.
     * @return array<string, array[]>
     */
    public static function collect(array $file_params) {
        $grouped = [];
        foreach (['resume', 'cover_letter', 'writing_samples'] as $field) {
            $grouped[$field] = self::flatten($file_params[$field] ?? null);
        }
        return $grouped;
    }

    private static function flatten($entry) {
        if (!is_array($entry) || !isset($entry['name'])) {
            return [];
        }

        // A single input gives scalars; name="field[]" gives parallel arrays.
        if (!is_array($entry['name'])) {
            $entries = [$entry];
        } else {
            $entries = [];
            foreach ($entry['name'] as $i => $name) {
                $entries[] = [
                    'name'     => $name,
                    'type'     => $entry['type'][$i] ?? '',
                    'tmp_name' => $entry['tmp_name'][$i] ?? '',
                    'error'    => $entry['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $entry['size'][$i] ?? 0,
                ];
            }
        }

        return array_values(array_filter($entries, function ($file) {
            return (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        }));
    }

    /**
     * Trim and drop blank sample links.
     *
     * @param mixed $raw
     * @return string[]
     */
    public static function normalize_links($raw) {
        $raw = is_array($raw) ? $raw : [$raw];
        $links = [];
        foreach ($raw as $link) {
            if (!is_string($link)) {
                continue;
            }
            $link = trim($link);
            if ($link !== '') {
                $links[] = $link;
            }
        }
        return array_values(array_unique($links));
    }

    /**
     * Check everything without writing anything.
     *
     * @return array<string, string> Field name => error message; empty if valid.
     */
    public static function validate(array $files, array $links) {
        $errors = [];

        if (!$files['resume']) {
            $errors['resume'] = 'Please attach your resume.';
        }
        if (count($files['resume']) > 1) {
            $errors['resume'] = 'Only one resume can be attached.';
        }
        if (count($files['cover_letter']) > 1) {
            $errors['cover_letter'] = 'Only one cover letter can be attached.';
        }
        if (count($files['writing_samples']) > self::MAX_WRITING_SAMPLES) {
            $errors['writing_samples'] = 'You can attach up to ' . self::MAX_WRITING_SAMPLES . ' writing samples.';
        }

        foreach ($files as $field => $list) {
            if (isset($errors[$field])) {
                continue;
            }
            foreach ($list as $file) {
                $problem = self::check_file($file);
                if ($problem) {
                    $errors[$field] = $problem;
                    break;
                }
            }
        }

        if (count($links) > self::MAX_LINKS) {
            $errors['sample_url'] = 'You can add up to ' . self::MAX_LINKS . ' links.';
        } else {
            foreach ($links as $link) {
                if (!self::is_valid_link($link)) {
                    $errors['sample_url'] = 'Please enter full links starting with http:// or https://.';
                    break;
                }
            }
        }

        return $errors;
    }

    public static function is_valid_link($link) {
        if (strlen($link) > self::MAX_URL_LENGTH || !filter_var($link, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = wp_parse_url($link, PHP_URL_SCHEME);
        return in_array($scheme, ['http', 'https'], true);
    }

    /**
     * @return string Error message, or '' if the file is acceptable.
     */
    private static function check_file(array $file) {
        $name = sanitize_text_field(wp_basename((string) ($file['name'] ?? '')));
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return '"' . $name . '" is over the 10 MB limit.';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return '"' . $name . '" could not be uploaded. Please try again.';
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_readable($tmp)) {
            return '"' . $name . '" could not be uploaded. Please try again.';
        }

        $extension = self::extension($name);
        if (!isset(self::MIME_TYPES[$extension])) {
            return '"' . $name . '" isn\'t a supported file type. Please upload a PDF or DOCX.';
        }

        $size = filesize($tmp);
        if (!$size) {
            return '"' . $name . '" is empty. Please choose a different file.';
        }
        if ($size > self::MAX_BYTES) {
            return '"' . $name . '" is over the 10 MB limit.';
        }

        // Trust the file's contents, not its name or the browser's MIME type.
        if (!self::matches_signature($tmp, $extension)) {
            return '"' . $name . '" isn\'t a valid ' . strtoupper($extension) . ' file.';
        }

        return '';
    }

    private static function extension($name) {
        return strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
    }

    private static function matches_signature($path, $extension) {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $head = fread($handle, 5);
        fclose($handle);

        if ($extension === 'pdf') {
            return $head === '%PDF-';
        }

        // DOCX is a ZIP archive that contains word/document.xml.
        if (substr((string) $head, 0, 4) !== "PK\x03\x04") {
            return false;
        }
        if (!class_exists('ZipArchive')) {
            return true;
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $has_document = $zip->locateName('word/document.xml') !== false;
        $zip->close();
        return $has_document;
    }

    /**
     * Absolute path of the protected storage folder, creating it (and its
     * deny rules) if needed.
     *
     * @return string|\WP_Error
     */
    public static function directory() {
        $uploads = wp_upload_dir(null, false);
        $dir = trailingslashit($uploads['basedir']) . self::DIRECTORY;

        if (!wp_mkdir_p($dir) || !is_writable($dir)) {
            return new \WP_Error('massinc_storage_unavailable', 'Could not save your files. Please try again.', ['status' => 500]);
        }

        // Defense in depth: direct requests are refused where the server
        // honors .htaccess; the random file names cover the rest.
        $guards = [
            'index.php'  => "<?php\n// Silence is golden.\n",
            '.htaccess'  => "# Files are served only through the REST API.\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n",
        ];
        foreach ($guards as $file => $contents) {
            if (!file_exists($dir . '/' . $file)) {
                file_put_contents($dir . '/' . $file, $contents);
            }
        }

        return $dir;
    }

    /**
     * Absolute path for a stored file name, or '' if the name isn't one we generate.
     */
    public static function path_for($stored_name) {
        if (!preg_match('/^[a-f0-9]{32}\.(pdf|docx)$/', (string) $stored_name)) {
            return '';
        }
        $dir = self::directory();
        return is_wp_error($dir) ? '' : $dir . '/' . $stored_name;
    }

    /**
     * Move one validated upload into private storage under a random name.
     *
     * @return array|\WP_Error Attachment data for Attachment::create().
     */
    public static function store(array $file) {
        $dir = self::directory();
        if (is_wp_error($dir)) {
            return $dir;
        }

        $original = sanitize_text_field(wp_basename((string) $file['name']));
        $extension = self::extension($original);
        $stored_name = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $dir . '/' . $stored_name;
        $tmp = $file['tmp_name'];

        if (is_uploaded_file($tmp)) {
            $moved = move_uploaded_file($tmp, $destination);
        } elseif (defined('WP_TESTS_DOMAIN')) {
            // The PHPUnit suite can't produce real HTTP uploads.
            $moved = copy($tmp, $destination);
            unlink($tmp);
        } else {
            $moved = false;
        }

        if (!$moved) {
            return new \WP_Error('massinc_storage_failed', 'Could not save your files. Please try again.', ['status' => 500]);
        }
        chmod($destination, 0640);

        return [
            'original_name' => $original,
            'stored_name'   => $stored_name,
            'mime_type'     => self::MIME_TYPES[$extension],
            'size_bytes'    => filesize($destination),
        ];
    }

    /**
     * Store every file and link for an application.
     *
     * @return true|\WP_Error On error the caller should call purge().
     */
    public static function save_all($application_id, array $files, array $links) {
        $kinds = [
            'resume'          => Attachment::KIND_RESUME,
            'cover_letter'    => Attachment::KIND_COVER_LETTER,
            'writing_samples' => Attachment::KIND_WRITING_SAMPLE,
        ];

        foreach ($kinds as $field => $kind) {
            foreach ($files[$field] as $file) {
                $stored = self::store($file);
                if (is_wp_error($stored)) {
                    return $stored;
                }
                $id = Attachment::create(array_merge($stored, [
                    'application_id' => $application_id,
                    'kind'           => $kind,
                ]));
                if (!$id) {
                    @unlink(self::path_for($stored['stored_name']));
                    return new \WP_Error('massinc_attachment_failed', 'Could not save your files. Please try again.', ['status' => 500]);
                }
            }
        }

        foreach ($links as $link) {
            $id = Attachment::create([
                'application_id' => $application_id,
                'kind'           => Attachment::KIND_LINK,
                'url'            => $link,
            ]);
            if (!$id) {
                return new \WP_Error('massinc_attachment_failed', 'Could not save your files. Please try again.', ['status' => 500]);
            }
        }

        return true;
    }

    /**
     * Remove an application's stored files and attachment rows.
     */
    public static function purge($application_id) {
        foreach (Attachment::get_for_application($application_id) as $attachment) {
            $path = self::path_for($attachment->get('stored_name'));
            if ($path && file_exists($path)) {
                unlink($path);
            }
        }
        Attachment::delete_for_application($application_id);
    }
}
