<?php
/**
 * Presentation helpers for the staff-facing ATS screens (candidate dashboard
 * and candidate detail). Shared by the two blocks in src/.
 *
 * Ratings and notes have no database tables yet, so applications read from
 * the database show as "Not rated yet" with no notes.
 * minc_hiring_sample_candidates() supplies the hi-fi design's sample data
 * whenever the applications table is empty so both screens can be reviewed.
 */
defined('ABSPATH') || exit;

use MassINC\Models\Attachment;

/**
 * ATS stages, in pipeline order.
 */
function minc_hiring_stages() {
    return [
        'applied'   => 'Applied',
        'reviewing' => 'Reviewing',
        'interview' => 'Interview',
        'offer'     => 'Offer',
        'rejected'  => 'Rejected',
    ];
}

/**
 * Stored application status => ATS stage.
 */
function minc_hiring_stage_for_status($status) {
    $map = [
        'submitted' => 'applied',
        'screening' => 'reviewing',
        'interview' => 'interview',
        'offer'     => 'offer',
        'hired'     => 'offer',
        'rejected'  => 'rejected',
    ];
    return $map[$status] ?? 'applied';
}

/**
 * Job or Internship, for the dashboard's Type column. employment_type is free
 * text on the job ("Internship, Full-time, etc."), so anything that mentions
 * an internship counts as one and everything else as a job.
 */
/**
 * URL of the published page that holds one of the portal's blocks (the
 * Candidates dashboard, the candidate page), so links and redirects don't
 * depend on what the site's pages happen to be called. Falls back to $fallback
 * when no page uses the block. Remembered for an hour and refreshed whenever
 * a page is saved.
 */
function minc_hiring_find_page_url($block_name, $fallback = '') {
    $cache_key = 'minc_hiring_page_' . md5($block_name);
    $url = get_transient($cache_key);

    if ($url === false) {
        $url = '';
        $pages = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'publish',
            's'              => $block_name,
            'posts_per_page' => 20,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);
        foreach ($pages as $page) {
            if (has_block($block_name, $page)) {
                $url = get_permalink($page);
                break;
            }
        }
        set_transient($cache_key, $url, HOUR_IN_SECONDS);
    }

    return $url ?: $fallback;
}

add_action('save_post_page', function () {
    delete_transient('minc_hiring_page_' . md5('minc-hiring/candidate-dashboard'));
    delete_transient('minc_hiring_page_' . md5('minc-hiring/candidate-detail'));
});

function minc_hiring_dashboard_url() {
    return minc_hiring_find_page_url('minc-hiring/candidate-dashboard', home_url('/'));
}

function minc_hiring_job_type($text) {
    return stripos((string) $text, 'intern') !== false ? 'Internship' : 'Job';
}

/**
 * Names offered in the "Select your name" dropdowns (reviewer and note
 * author). Staff share one sign-in, so reviewers say who they are on the page.
 * The saved list starts empty and staff add their own names from the page
 * (see MassINC\StaffNames); ?demo=1 uses the hi-fi's sample names instead.
 */
function minc_hiring_get_staff_names() {
    return minc_hiring_is_demo()
        ? ['Dana Whitfield', 'Luis Ferrer', 'Nadia Chen']
        : \MassINC\StaffNames::all();
}

function minc_hiring_initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials;
}

function minc_hiring_format_date($mysql) {
    return $mysql ? mysql2date('j M Y', $mysql) : '';
}

/**
 * Sample data copied from the hi-fi (04 Dashboard, 05/05b Candidate detail).
 * `rating`/`count` are the average shown on the dashboard; `reviews` are the
 * individual ratings (newest first), used on the detail screen.
 */
function minc_hiring_sample_candidates() {
    $materials = [
        ['name' => 'resume.pdf', 'ext' => 'PDF', 'meta' => '245 KB · Uploaded 12 Aug 2026', 'href' => '', 'action' => 'Download'],
        ['name' => 'cv.pdf', 'ext' => 'PDF', 'meta' => '190 KB · Uploaded 12 Aug 2026', 'href' => '', 'action' => 'Download'],
        ['name' => 'beacon-housing-series.pdf', 'ext' => 'PDF', 'meta' => '1.2 MB · Uploaded 12 Aug 2026', 'href' => '', 'action' => 'Download'],
        ['name' => 'chelsea-budget-explainer.pdf', 'ext' => 'PDF', 'meta' => '840 KB · Uploaded 12 Aug 2026', 'href' => '', 'action' => 'Download'],
        ['name' => 'lynn-schools-feature.docx', 'ext' => 'DOCX', 'meta' => '310 KB · Uploaded 12 Aug 2026', 'href' => '', 'action' => 'Download'],
    ];

    $rows = [
        ['sample-priya', 'Priya Raghunathan', 'Policy analyst', 'offer', '9 Aug 2026', 4.9, 4, 'priya.raghunathan@gmail.com'],
        ['sample-daniel', 'Daniel Reyes', 'Staff writer', 'reviewing', '14 Aug 2026', 4.7, 3, 'daniel.reyes@gmail.com'],
        ['sample-maya', 'Maya Okonkwo', 'Staff writer', 'interview', '12 Aug 2026', 4.3, 3, 'maya.okonkwo@gmail.com'],
        ['sample-colin', 'Colin Mercer', 'Data reporter', 'reviewing', '17 Aug 2026', 4.0, 3, 'colin.mercer@gmail.com'],
        ['sample-aisha', 'Aisha Bennett', 'Policy research intern', 'rejected', '4 Aug 2026', 2.5, 2, 'aisha.bennett@gmail.com'],
        ['sample-tomas', 'Tomás Oliveira', 'Policy research intern', 'applied', '18 Aug 2026', null, 0, 'tomas.oliveira@gmail.com'],
    ];

    $candidates = [];
    foreach ($rows as $i => [$id, $name, $position, $stage, $applied, $avg, $count, $email]) {
        $slug = preg_replace('/[^a-z]/', '', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)));
        $candidates[] = [
            'id'        => $id,
            'name'      => $name,
            'position'  => $position,
            'type'      => minc_hiring_job_type($position),
            'stage'     => $stage,
            'applied'   => $applied,
            'applied_ts' => (int) strtotime($applied),
            'rating'    => $avg,
            'count'     => $count,
            'reference' => 'BEACON-2026-' . (110 + $i),
            'email'     => $email,
            'phone'     => '(617) 555-0142',
            'location'  => 'Boston, MA',
            'materials' => $materials,
            'links'     => ["https://www.linkedin.com/in/$slug", "https://github.com/$slug", "https://$slug.com"],
            'reviews'   => [],
            'notes'     => [],
            'sample'    => true,
        ];
    }

    // Maya's detail screen is the one the hi-fi spells out in full.
    foreach ($candidates as &$candidate) {
        if ($candidate['id'] !== 'sample-maya') {
            continue;
        }
        $candidate['reference'] = 'BEACON-2026-114';
        $candidate['reviews']   = [
            ['reviewer' => 'Luis Ferrer', 'score' => 5, 'date' => '16 Aug 2026'],
            ['reviewer' => 'Nadia Chen', 'score' => 4, 'date' => '15 Aug 2026'],
        ];
        $candidate['notes']     = [
            ['author' => 'Dana Whitfield', 'date' => '18 Aug 2026', 'text' => 'Phone screen went well. Asked about municipal finance reporting — she has covered Chelsea and Lynn budgets before.'],
            ['author' => 'Luis Ferrer', 'date' => '16 Aug 2026', 'text' => 'Sent her clips to the editing team for a second read.'],
        ];
    }
    unset($candidate);

    // Newest application first, like the real list.
    usort($candidates, function ($a, $b) {
        return $b['applied_ts'] <=> $a['applied_ts'];
    });

    return $candidates;
}

function minc_hiring_format_bytes($bytes) {
    return $bytes >= 1024 * 1024
        ? number_format($bytes / (1024 * 1024), 1) . ' MB'
        : max(1, (int) round($bytes / 1024)) . ' KB';
}

/**
 * URLs the applicant submitted as links (LinkedIn, GitHub, portfolio, ...),
 * in the order they were saved.
 */
function minc_hiring_get_links($application_id) {
    $links = [];
    foreach (Attachment::get_for_application($application_id) as $attachment) {
        if ($attachment->is_link() && $attachment->get('url')) {
            $links[] = (string) $attachment->get('url');
        }
    }
    return $links;
}

/**
 * Display text, href and icon for a submitted link. The form stores links as
 * bare URLs with no label, so the icon is picked from the hostname.
 * Returns null for anything that isn't an http(s) URL.
 */
function minc_hiring_link_info($url) {
    $parts = wp_parse_url($url);
    if (empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)) {
        return null;
    }

    $host = strtolower(preg_replace('/^www\./i', '', $parts['host']));
    if ($host === 'linkedin.com' || substr($host, -13) === '.linkedin.com') {
        $icon = 'linkedin';
    } elseif ($host === 'github.com' || substr($host, -11) === '.github.com') {
        $icon = 'github';
    } else {
        $icon = 'globe';
    }

    $label = $host . rtrim($parts['path'] ?? '', '/');
    if (!empty($parts['query'])) {
        $label .= '?' . $parts['query'];
    }

    return ['label' => $label, 'href' => $url, 'icon' => $icon];
}

/**
 * Inline one of the SVGs in assets/icons so it takes the surrounding text
 * color and needs no extra request. Decorative, so hidden from screen readers.
 */
function minc_hiring_icon($name) {
    static $cache = [];
    if (!in_array($name, ['linkedin', 'github', 'globe', 'user'], true)) {
        return '';
    }
    if (!isset($cache[$name])) {
        $svg = (string) file_get_contents(MINC_HIRING_PATH . 'assets/icons/' . $name . '.svg');
        $cache[$name] = str_replace('<svg ', '<svg class="massinc-icon" aria-hidden="true" focusable="false" ', $svg);
    }
    return $cache[$name];
}

/**
 * Score => label shown in the Rating Dropdown, best first.
 */
function minc_hiring_rating_options() {
    return [
        5 => '5 — Excellent',
        4 => '4 — Strong',
        3 => '3 — Good',
        2 => '2 — Fair',
        1 => '1 — Poor',
    ];
}

/**
 * Stored files for an application, shaped for the Materials card.
 * Files download through the permission-checked REST route. Links are shown
 * separately (see minc_hiring_get_links()).
 */
function minc_hiring_get_materials($application_id) {
    $materials = [];
    foreach (Attachment::get_for_application($application_id) as $attachment) {
        if ($attachment->is_link()) {
            continue;
        }

        $uploaded = minc_hiring_format_date($attachment->get('created_at'));
        $name = (string) $attachment->get('original_name');
        $materials[] = [
            'name'   => $name,
            'ext'    => strtoupper(pathinfo($name, PATHINFO_EXTENSION)) ?: 'FILE',
            'meta'   => minc_hiring_format_bytes((int) $attachment->get('size_bytes')) . ' · Uploaded ' . $uploaded,
            'href'   => add_query_arg(
                '_wpnonce',
                wp_create_nonce('wp_rest'),
                rest_url('massinc/v1/attachments/' . $attachment->get_id() . '/download')
            ),
            'action' => 'Download',
        ];
    }
    return $materials;
}

/**
 * ?demo=1 shows the hi-fi sample data even when real applications exist, so
 * the screens can be compared with the design. Only reachable by staff
 * (both blocks check minc_hiring_require_staff() first).
 */
function minc_hiring_is_demo() {
    return isset($_GET['demo']) && $_GET['demo'] === '1';
}

/**
 * Applications joined with candidate and job, newest application first,
 * normalised to the shape used by the templates.
 */
function minc_hiring_get_candidates() {
    if (minc_hiring_is_demo()) {
        return minc_hiring_sample_candidates();
    }

    global $wpdb;
    $prefix = $wpdb->prefix . 'massinc_';

    $rows = $wpdb->get_results(
        "SELECT a.id, a.status, a.created_at,
                j.title AS job_title, j.employment_type AS job_type,
                c.first_name, c.last_name, c.email, c.phone
         FROM {$prefix}applications a
         JOIN {$prefix}jobs j ON j.id = a.job_id
         JOIN {$prefix}candidates c ON c.id = a.candidate_id
         ORDER BY a.created_at DESC, a.id DESC"
    );

    if (!$rows) {
        return minc_hiring_sample_candidates();
    }

    $summaries = \MassINC\Models\Rating::summaries();

    $candidates = [];
    foreach ($rows as $row) {
        $summary = $summaries[(int) $row->id] ?? null;
        $candidates[] = [
            'id'        => (int) $row->id,
            'name'      => trim($row->first_name . ' ' . $row->last_name),
            'position'  => $row->job_title,
            'type'      => minc_hiring_job_type($row->job_type . ' ' . $row->job_title),
            'stage'     => minc_hiring_stage_for_status($row->status),
            'applied'   => minc_hiring_format_date($row->created_at),
            'applied_ts' => (int) strtotime($row->created_at),
            'rating'    => $summary ? $summary['average'] : null,
            'count'     => $summary ? $summary['count'] : 0,
            'reference' => (string) $row->id,
            'email'     => $row->email,
            'phone'     => $row->phone,
            'location'  => '',
            'materials' => [],
            'links'     => [],
            'reviews'   => [],
            'notes'     => [],
            'sample'    => false,
        ];
    }

    return $candidates;
}

/**
 * One candidate with everything the detail page shows. Files, links, ratings
 * and notes are loaded here (not in the dashboard list) so the list stays cheap.
 */
function minc_hiring_get_candidate($id) {
    foreach (minc_hiring_get_candidates() as $candidate) {
        if ((string) $candidate['id'] !== (string) $id) {
            continue;
        }

        if (!$candidate['sample']) {
            $application_id = (int) $candidate['id'];
            $candidate['materials'] = minc_hiring_get_materials($application_id);
            $candidate['links'] = minc_hiring_get_links($application_id);
            $candidate['reviews'] = array_map(function ($rating) {
                return [
                    'reviewer' => (string) $rating->get('reviewer_name'),
                    'score'    => $rating->get_score(),
                    'date'     => minc_hiring_format_date($rating->get('updated_at')),
                ];
            }, \MassINC\Models\Rating::get_for_application($application_id));
            $candidate['notes'] = array_map(function ($note) {
                return [
                    'author' => (string) $note->get('author_name'),
                    'date'   => minc_hiring_format_date($note->get('created_at')),
                    'text'   => (string) $note->get('body'),
                ];
            }, \MassINC\Models\Note::get_for_application($application_id));
        }

        return $candidate;
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* Markup helpers                                                       */
/* ------------------------------------------------------------------ */

const MINC_HIRING_STAR_POINTS = '11,1.5 13.9,7.9 20.8,8.5 15.6,13.1 17.1,20 11,16.5 4.9,20 6.4,13.1 1.2,8.5 8.1,7.9';

/**
 * One star. $fill is 'full', 'half' or 'empty'. The filled layer is clipped
 * with CSS, so no SVG ids are needed (many stars share a page).
 */
function minc_hiring_star($fill) {
    $svg = '<svg viewBox="0 0 22 22" width="22" height="22" aria-hidden="true" focusable="false"><polygon points="' . MINC_HIRING_STAR_POINTS . '"/></svg>';
    return '<span class="massinc-star is-' . esc_attr($fill) . '">' . $svg . '<span class="massinc-star-fill">' . $svg . '</span></span>';
}

/**
 * Five stars for an average, rounded to the nearest half.
 */
function minc_hiring_stars($value) {
    $value = round(((float) $value) * 2) / 2;
    $html = '<span class="massinc-stars" aria-hidden="true">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= minc_hiring_star($value >= $i ? 'full' : ($value >= $i - 0.5 ? 'half' : 'empty'));
    }
    return $html . '</span>';
}

/**
 * Stars + number + count. Always carries text so rating never relies on the
 * star graphics alone.
 */
function minc_hiring_rating($avg, $count) {
    if ($avg === null || !$count) {
        return '<span class="massinc-rating">' . minc_hiring_stars(0)
            . '<span class="massinc-rating-count">Not rated yet</span></span>';
    }
    return '<span class="massinc-rating">' . minc_hiring_stars($avg)
        . '<span class="massinc-rating-value">' . esc_html(number_format((float) $avg, 1)) . '</span>'
        . '<span class="massinc-rating-count">(' . (int) $count . ')</span></span>';
}

function minc_hiring_stage_badge($stage) {
    $stages = minc_hiring_stages();
    $stage = isset($stages[$stage]) ? $stage : 'applied';
    return '<span class="massinc-stage massinc-stage-' . esc_attr($stage) . '" data-stage-badge>' . esc_html($stages[$stage]) . '</span>';
}

/**
 * Prints the custom dropdown (see src/shared/select.js, which does the
 * behavior). Options are handed to the script as JSON; the markup here is the
 * closed control plus an empty list.
 *
 * Args:
 *  - id:                id of the control, so a <label for> can point at it
 *  - field:             data-dd name, used to find the dropdown from view.js
 *  - mode:              'choice' (button + list) or 'names' (type to filter or add)
 *  - options:           [['value' => ..., 'label' => ..., 'disabled' => bool], ...]
 *  - value:             the selected value ('' for none)
 *  - placeholder:       text shown when nothing is selected
 *  - placeholder_option: also list the placeholder as a choosable "none" option
 *  - removable:         names mode: show an × on each name
 *  - disabled:          start disabled
 *  - class:             extra classes for the wrapper
 */
function minc_hiring_dropdown(array $args) {
    $args = wp_parse_args($args, [
        'id'                 => '',
        'field'              => '',
        'mode'               => 'choice',
        'options'            => [],
        'value'              => '',
        'placeholder'        => 'Select…',
        'placeholder_option' => false,
        'removable'          => false,
        'disabled'           => false,
        'class'              => '',
    ]);

    $names = $args['mode'] === 'names';
    $items = [];
    if ($args['placeholder_option']) {
        $items[] = ['value' => '', 'label' => $args['placeholder'], 'disabled' => false];
    }
    foreach ($args['options'] as $option) {
        $items[] = [
            'value'    => (string) $option['value'],
            'label'    => (string) $option['label'],
            'disabled' => !empty($option['disabled']),
        ];
    }

    $selected = null;
    foreach ($items as $item) {
        if ($args['value'] !== '' && $item['value'] === (string) $args['value']) {
            $selected = $item;
        }
    }

    $list_id = $args['id'] . '-list';
    ?>
    <div class="massinc-dd <?php echo esc_attr($args['class']); ?><?php echo $selected ? '' : ' is-placeholder'; ?>"
         data-dd="<?php echo esc_attr($args['field']); ?>"
         data-mode="<?php echo $names ? 'names' : 'choice'; ?>"
         data-removable="<?php echo $args['removable'] ? '1' : '0'; ?>"
         data-placeholder="<?php echo esc_attr($args['placeholder']); ?>"
         data-options="<?php echo esc_attr(wp_json_encode($items)); ?>">
        <input type="hidden" data-dd-value value="<?php echo esc_attr($selected ? $selected['value'] : ''); ?>">
        <?php if ($names) : ?>
            <input type="text" id="<?php echo esc_attr($args['id']); ?>" class="massinc-input massinc-dd-control"
                   role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="<?php echo esc_attr($list_id); ?>"
                   autocomplete="off" spellcheck="false" maxlength="100"
                   placeholder="<?php echo esc_attr($args['placeholder']); ?>"
                   value="<?php echo esc_attr($selected ? $selected['value'] : ''); ?>"
                   data-dd-control<?php disabled($args['disabled']); ?>>
        <?php else : ?>
            <button type="button" id="<?php echo esc_attr($args['id']); ?>" class="massinc-input massinc-dd-control"
                    role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-controls="<?php echo esc_attr($list_id); ?>"
                    data-dd-control<?php disabled($args['disabled']); ?>>
                <span class="massinc-dd-text" data-dd-text><?php echo esc_html($selected ? $selected['label'] : $args['placeholder']); ?></span>
            </button>
        <?php endif; ?>
        <span class="massinc-dd-caret" aria-hidden="true">&#9662;</span>
        <ul class="massinc-dd-list" id="<?php echo esc_attr($list_id); ?>" role="listbox" data-dd-list hidden></ul>
    </div>
    <?php
}

/**
 * Job / Internship tag for the dashboard's Type column.
 */
function minc_hiring_type_tag($type) {
    $class = $type === 'Internship' ? 'internship' : 'job';
    return '<span class="massinc-type massinc-type-' . $class . '">' . esc_html($type) . '</span>';
}

/**
 * Staff header (Top Nav / Staff): logo, the one section staff can use, and
 * who is signed in. Positions and Settings belong to the admin header.
 */
function minc_hiring_admin_nav() {
    $user = wp_get_current_user();
    $name = $user->exists() ? $user->display_name : '';
    ?>
    <header class="massinc-nav">
        <div class="massinc-nav-brand">
            <img class="massinc-nav-logo" src="<?php echo esc_url(MINC_HIRING_URL . 'assets/images/massinc-logo.png'); ?>" alt="MassINC" width="207" height="36">
            <span class="massinc-nav-divider" aria-hidden="true"></span>
            <span class="massinc-nav-product">Applicant Tracking</span>
        </div>
        <nav class="massinc-nav-tabs" aria-label="Applicant tracking">
            <span class="massinc-nav-tab is-active" aria-current="page">Candidates</span>
        </nav>
        <?php if ($name) : ?>
            <div class="massinc-nav-user">
                <?php echo minc_hiring_icon('user'); ?>
                <span><?php echo esc_html($name); ?></span>
                <span class="massinc-nav-divider" aria-hidden="true"></span>
                <a class="massinc-link" href="<?php echo esc_url(minc_hiring_logout_url()); ?>">Sign out</a>
            </div>
        <?php endif; ?>
    </header>
    <?php
}

/**
 * Staff-only gate shared by both screens. Returns true when the viewer may
 * see them; otherwise prints a short message and returns false.
 */
function minc_hiring_require_staff() {
    if (current_user_can('view_minc_applications')) {
        return true;
    }

    if (is_user_logged_in()) {
        echo '<p class="massinc-ats-denied">This account doesn&rsquo;t have access to the hiring portal. Ask an admin to add you, or <a href="' . esc_url(minc_hiring_logout_url()) . '">sign out</a> and sign in with a different account.</p>';
    } else {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $sign_in = add_query_arg('redirect_to', rawurlencode(home_url($request_uri)), minc_hiring_login_url());
        echo '<p class="massinc-ats-denied">Please <a href="' . esc_url($sign_in) . '">sign in</a> to view this page.</p>';
    }
    return false;
}

/**
 * The page that holds the sign-in block, or WordPress's own sign-in screen when
 * no page does.
 */
function minc_hiring_login_url() {
    return minc_hiring_find_page_url('minc-hiring/staff-login', wp_login_url());
}

/**
 * Signing out lands back on the sign-in page.
 */
function minc_hiring_logout_url() {
    return wp_logout_url(minc_hiring_login_url());
}
