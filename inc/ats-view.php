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

function minc_hiring_initials($name) {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $initials;
}

function minc_hiring_format_date($mysql) {
    $timestamp = strtotime($mysql);
    return $timestamp ? wp_date('j M Y', $timestamp) : '';
}

/**
 * Sample data copied from the hi-fi (04 Dashboard, 05/05b Candidate detail).
 * `rating`/`count` are the average shown on the dashboard; `reviews` are the
 * other reviewers' individual ratings, used on the detail screen.
 */
function minc_hiring_sample_candidates() {
    $materials = [
        ['name' => 'resume.pdf', 'ext' => 'PDF', 'meta' => '245 KB · Uploaded 12 Aug 2026', 'href' => '', 'action' => 'Download'],
        ['name' => 'cover-letter.pdf', 'ext' => 'PDF', 'meta' => '88 KB · Uploaded 12 Aug 2026', 'href' => '', 'action' => 'Download'],
        ['name' => 'writing-sample-beacon.pdf', 'ext' => 'PDF', 'meta' => '1.2 MB · Uploaded 12 Aug 2026', 'href' => '', 'action' => 'Download'],
    ];

    $rows = [
        ['sample-priya', 'Priya Raghunathan', 'Policy analyst', 'offer', '9 Aug 2026', 4.9, 4, 'priya.raghunathan@gmail.com'],
        ['sample-daniel', 'Daniel Reyes', 'Staff writer', 'reviewing', '14 Aug 2026', 4.7, 3, 'daniel.reyes@gmail.com'],
        ['sample-maya', 'Maya Okonkwo', 'Staff writer', 'interview', '12 Aug 2026', 4.3, 3, 'maya.okonkwo@gmail.com'],
        ['sample-colin', 'Colin Mercer', 'Data reporter', 'reviewing', '17 Aug 2026', 4.0, 3, 'colin.mercer@gmail.com'],
        ['sample-aisha', 'Aisha Bennett', 'Policy analyst', 'rejected', '4 Aug 2026', 2.5, 2, 'aisha.bennett@gmail.com'],
        ['sample-tomas', 'Tomás Oliveira', 'Data reporter', 'applied', '18 Aug 2026', null, 0, 'tomas.oliveira@gmail.com'],
    ];

    $candidates = [];
    foreach ($rows as $i => [$id, $name, $position, $stage, $applied, $avg, $count, $email]) {
        $candidates[] = [
            'id'        => $id,
            'name'      => $name,
            'position'  => $position,
            'stage'     => $stage,
            'applied'   => $applied,
            'rating'    => $avg,
            'count'     => $count,
            'reference' => 'BEACON-2026-' . (110 + $i),
            'email'     => $email,
            'phone'     => '(617) 555-0142',
            'location'  => 'Boston, MA',
            'materials' => $materials,
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
            ['reviewer' => 'Luis Ferrer', 'score' => 5, 'date' => '16 Aug 2026', 'comment' => 'Best writing sample in the pool — would hire on the clips alone.'],
            ['reviewer' => 'Nadia Chen', 'score' => 4, 'date' => '15 Aug 2026', 'comment' => 'Strong voice and clear structure. Worth a second interview.'],
        ];
        $candidate['notes']     = [
            ['author' => 'Dana Whitfield', 'date' => '18 Aug 2026', 'text' => 'Phone screen went well. Asked about municipal finance reporting — she has covered Chelsea and Lynn budgets before.'],
            ['author' => 'Luis Ferrer', 'date' => '16 Aug 2026', 'text' => 'Sent her clips to the editing team for a second read.'],
        ];
    }
    unset($candidate);

    return $candidates;
}

function minc_hiring_format_bytes($bytes) {
    return $bytes >= 1024 * 1024
        ? number_format($bytes / (1024 * 1024), 1) . ' MB'
        : max(1, (int) round($bytes / 1024)) . ' KB';
}

/**
 * Stored files and links for an application, shaped for the Materials card.
 * Files download through the permission-checked REST route; links open as-is.
 */
function minc_hiring_get_materials($application_id) {
    $materials = [];
    foreach (Attachment::get_for_application($application_id) as $attachment) {
        $uploaded = minc_hiring_format_date($attachment->get('created_at'));

        if ($attachment->is_link()) {
            $url = (string) $attachment->get('url');
            $materials[] = [
                'name'   => (string) (wp_parse_url($url, PHP_URL_HOST) ?: $url),
                'ext'    => 'LINK',
                'meta'   => 'Added ' . $uploaded,
                'href'   => $url,
                'action' => 'Open',
            ];
            continue;
        }

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
 * Applications joined with candidate and job, highest rated first, normalised
 * to the shape used by the templates.
 */
function minc_hiring_get_candidates() {
    if (minc_hiring_is_demo()) {
        return minc_hiring_sample_candidates();
    }

    global $wpdb;
    $prefix = $wpdb->prefix . 'massinc_';

    $rows = $wpdb->get_results(
        "SELECT a.id, a.status, a.created_at,
                j.title AS job_title,
                c.first_name, c.last_name, c.email, c.phone
         FROM {$prefix}applications a
         JOIN {$prefix}jobs j ON j.id = a.job_id
         JOIN {$prefix}candidates c ON c.id = a.candidate_id
         ORDER BY a.created_at DESC"
    );

    if (!$rows) {
        return minc_hiring_sample_candidates();
    }

    $candidates = [];
    foreach ($rows as $row) {
        $candidates[] = [
            'id'        => (int) $row->id,
            'name'      => trim($row->first_name . ' ' . $row->last_name),
            'position'  => $row->job_title,
            'stage'     => minc_hiring_stage_for_status($row->status),
            'applied'   => minc_hiring_format_date($row->created_at),
            'rating'    => null,
            'count'     => 0,
            'reference' => (string) $row->id,
            'email'     => $row->email,
            'phone'     => $row->phone,
            'location'  => '',
            'materials' => minc_hiring_get_materials((int) $row->id),
            'reviews'   => [],
            'notes'     => [],
            'sample'    => false,
        ];
    }

    // Unrated candidates go last; usort is stable, so ties stay newest-first.
    usort($candidates, function ($a, $b) {
        return ($b['rating'] ?? -1) <=> ($a['rating'] ?? -1);
    });

    return $candidates;
}

function minc_hiring_get_candidate($id) {
    foreach (minc_hiring_get_candidates() as $candidate) {
        if ((string) $candidate['id'] === (string) $id) {
            return $candidate;
        }
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
    return '<span class="massinc-stage massinc-stage-' . esc_attr($stage) . '">' . esc_html($stages[$stage]) . '</span>';
}

/**
 * Staff header (Top Nav / Admin). Positions and Settings have no screens yet,
 * so they render as plain text instead of dead links.
 */
function minc_hiring_admin_nav() {
    $user = wp_get_current_user();
    $name = $user->exists() ? $user->display_name : '';
    ?>
    <header class="massinc-nav">
        <div class="massinc-nav-brand">
            <span class="massinc-wordmark">MassINC</span>
            <span class="massinc-nav-divider" aria-hidden="true"></span>
            <span class="massinc-nav-product">Applicant Tracking</span>
        </div>
        <nav class="massinc-nav-tabs" aria-label="Applicant tracking">
            <span class="massinc-nav-tab is-active" aria-current="page">Candidates</span>
            <span class="massinc-nav-tab">Positions</span>
            <span class="massinc-nav-tab">Settings</span>
        </nav>
        <?php if ($name) : ?>
            <div class="massinc-nav-user">
                <span class="massinc-avatar" aria-hidden="true"><?php echo esc_html(minc_hiring_initials($name)); ?></span>
                <span><?php echo esc_html($name); ?></span>
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
    echo '<p class="massinc-ats-denied">You need to be signed in as hiring staff to view this page.</p>';
    return false;
}
