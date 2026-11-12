<?php
/**
 * Frontend render template for Candidate Detail block.
 *
 * Staff share one sign-in, so a reviewer first picks their own name from the
 * staff list, then chooses a rating. view.js switches data-state on the wrapper:
 *  - "before": the picked name hasn't rated this candidate. Everyone's scores
 *    and the average stay hidden so nobody anchors on someone else's score.
 *    Your rating and Submit stay disabled until a name is picked.
 *  - "after": the picked name has rated, so the average and each reviewer's
 *    rating are shown, and their own rating can be changed (it replaces the
 *    earlier one).
 * Ratings and notes are saved through the REST API (see inc/api.php) and read
 * back from the database on every load. The hiding is only on the page: the
 * ratings are in the page data so the page can show them without another request.
 * ?demo=1 uses the hi-fi's sample data and saves nothing; add ?preview=rated to
 * start as a reviewer who has already rated.
 */
defined('ABSPATH') || exit;

if (!minc_hiring_require_staff()) {
    return;
}

$dashboard_url = !empty($attributes['dashboardUrl']) ? $attributes['dashboardUrl'] : minc_hiring_dashboard_url();
if (minc_hiring_is_demo()) {
    $dashboard_url = add_query_arg('demo', '1', $dashboard_url);
}
$candidate = isset($_GET['application'])
    ? minc_hiring_get_candidate(sanitize_text_field(wp_unslash($_GET['application'])))
    : null;

if (!$candidate) {
    ?>
    <div <?php echo get_block_wrapper_attributes(['class' => 'massinc-ats massinc-detail']); ?>>
        <?php minc_hiring_admin_nav(); ?>
        <div class="massinc-detail-body">
            <a class="massinc-back-link" href="<?php echo esc_url($dashboard_url); ?>">&larr;&nbsp; All candidates</a>
            <p>We couldn't find that candidate.</p>
        </div>
    </div>
    <?php
    return;
}

$first_name = strtok($candidate['name'], ' ');
$stages = minc_hiring_stages();
$is_admin = minc_hiring_can_change_stage();

$staff_names = minc_hiring_get_staff_names();

// Ratings the page starts with, newest first. In the demo, ?preview=rated adds
// the first staff name's rating (4, on 19 Aug 2026) and starts as that reviewer.
$ratings = $candidate['reviews'];
$initial_reviewer = '';
if (minc_hiring_is_demo() && isset($_GET['preview']) && $_GET['preview'] === 'rated') {
    $initial_reviewer = $staff_names[0] ?? 'Dana Whitfield';
    array_unshift($ratings, ['reviewer' => $initial_reviewer, 'score' => 4, 'date' => '19 Aug 2026']);
}
$initial_score = $initial_reviewer ? (string) $ratings[0]['score'] : '';

// Sample candidates without individual ratings only carry a dashboard average,
// so it is counted as a fixed starting point. Real candidates start from zero.
$base_count = ($candidate['sample'] && !$candidate['reviews']) ? (int) $candidate['count'] : 0;
$base_sum = $base_count ? (float) $candidate['rating'] * $base_count : 0;
$total_ratings = count($ratings) + $base_count;

$wrapper_args = [
    'class'                => 'massinc-ats massinc-detail',
    'data-state'           => $initial_reviewer ? 'after' : 'before',
    'data-first-name'      => $first_name,
    'data-ratings'         => wp_json_encode(array_values($ratings)),
    'data-base-sum'        => $base_sum,
    'data-base-count'      => $base_count,
    'data-initial-reviewer' => $initial_reviewer,
    'data-stage'           => $candidate['stage'],
    'data-stages'          => wp_json_encode($stages),
];
// Names, ratings and notes are saved through the REST API. In ?demo=1 (and for
// the built-in sample candidates, which have no database row) changes stay on
// the page, so reviewing the design doesn't touch real data.
if (!minc_hiring_is_demo()) {
    $wrapper_args['data-rest-nonce'] = wp_create_nonce('wp_rest');
    $wrapper_args['data-staff-endpoint'] = esc_url(rest_url('massinc/v1/staff-names'));
    if (!$candidate['sample']) {
        $application_url = 'massinc/v1/applications/' . (int) $candidate['id'];
        $wrapper_args['data-ratings-endpoint'] = esc_url(rest_url($application_url . '/ratings'));
        $wrapper_args['data-notes-endpoint'] = esc_url(rest_url($application_url . '/notes'));
        $wrapper_args['data-stage-endpoint'] = esc_url(rest_url($application_url . '/stage'));
    }
}
$wrapper_attributes = get_block_wrapper_attributes($wrapper_args);

$meta = ['Applied for ' . $candidate['position'], $candidate['applied'], 'Reference ' . $candidate['reference']];
$other_contact = implode('  ·  ', array_filter([$candidate['phone'], $candidate['location']]));
$links = array_values(array_filter(array_map('minc_hiring_link_info', $candidate['links'])));
$rating_options = minc_hiring_rating_options();

// Options for the dropdowns. Anyone on the staff list can rate: a reviewer who
// rates again replaces their earlier score.
$name_options = array_map(function ($name) {
    return ['value' => $name, 'label' => $name];
}, $staff_names);
$rating_dropdown_options = [];
foreach ($rating_options as $score => $label) {
    $rating_dropdown_options[] = ['value' => (string) $score, 'label' => $label];
}
$stage_dropdown_options = [];
foreach ($stages as $key => $label) {
    $stage_dropdown_options[] = ['value' => $key, 'label' => $label];
}
?>

<div <?php echo $wrapper_attributes; ?>>
    <?php minc_hiring_admin_nav(); ?>

    <div class="massinc-detail-body">
        <a class="massinc-back-link" href="<?php echo esc_url($dashboard_url); ?>">&larr;&nbsp; All candidates</a>

        <div class="massinc-detail-header">
            <div class="massinc-detail-identity">
                <h1 class="massinc-detail-name"><?php echo esc_html($candidate['name']); ?></h1>
                <?php echo minc_hiring_stage_badge($candidate['stage']); ?>
                <span class="massinc-rating massinc-state-after" data-avg-header></span>
            </div>
            <p class="massinc-detail-meta"><?php echo esc_html(implode('  ·  ', $meta)); ?></p>
            <?php if ($candidate['email'] || $other_contact) : ?>
                <div class="massinc-detail-contact">
                    <?php if ($candidate['email']) : ?>
                        <span class="massinc-detail-meta"><?php echo esc_html($candidate['email']); ?></span>
                        <a class="massinc-btn" href="<?php echo esc_url('mailto:' . $candidate['email']); ?>">Email applicant<span class="screen-reader-text"> <?php echo esc_html($candidate['name']); ?></span></a>
                    <?php endif; ?>
                    <?php if ($other_contact) : ?>
                        <span class="massinc-detail-meta"><?php echo esc_html(($candidate['email'] ? '·  ' : '') . $other_contact); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="massinc-detail-columns">
            <div class="massinc-detail-main">

                <section class="massinc-card" aria-labelledby="massinc-materials-title">
                    <h2 class="massinc-card-title" id="massinc-materials-title">Materials</h2>
                    <?php if (!$candidate['materials']) : ?>
                        <p class="massinc-muted">No files were submitted with this application.</p>
                    <?php endif; ?>
                    <?php foreach ($candidate['materials'] as $item) : ?>
                        <div class="massinc-material">
                            <span class="massinc-file-badge" aria-hidden="true"><?php echo esc_html($item['ext']); ?></span>
                            <div class="massinc-material-info">
                                <p class="massinc-material-name"><?php echo esc_html($item['name']); ?></p>
                                <p class="massinc-material-meta"><?php echo esc_html($item['meta']); ?></p>
                            </div>
                            <a class="massinc-btn" href="<?php echo $item['href'] ? esc_url($item['href']) : '#'; ?>">
                                <?php echo esc_html($item['action']); ?><span class="screen-reader-text"> <?php echo esc_html($item['name']); ?></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </section>

                <section class="massinc-card" aria-labelledby="massinc-ratings-title">
                    <h2 class="massinc-card-title" id="massinc-ratings-title">Ratings</h2>

                    <div class="massinc-overall massinc-state-after">
                        <span class="massinc-row-label">Overall</span>
                        <span class="massinc-rating" data-avg-overall></span>
                    </div>

                    <div class="massinc-rating-row">
                        <label class="massinc-row-label" for="massinc-reviewer">Reviewer</label>
                        <?php minc_hiring_dropdown([
                            'id'          => 'massinc-reviewer',
                            'field'       => 'reviewer',
                            'mode'        => 'names',
                            'options'     => $name_options,
                            'value'       => $initial_reviewer,
                            'placeholder' => 'Select your name',
                            'removable'   => true,
                            'class'       => 'massinc-rating-control',
                        ]); ?>
                    </div>

                    <div class="massinc-rating-row">
                        <label class="massinc-row-label" for="massinc-your-rating">Your rating</label>
                        <?php minc_hiring_dropdown([
                            'id'                 => 'massinc-your-rating',
                            'field'              => 'rating',
                            'options'            => $rating_dropdown_options,
                            'value'              => $initial_score,
                            'placeholder'        => 'Select a rating',
                            'placeholder_option' => true,
                            'disabled'           => !$initial_reviewer,
                            'class'              => 'massinc-rating-control',
                        ]); ?>
                        <button type="button" class="massinc-btn massinc-btn-primary massinc-state-before" data-submit-rating disabled>Submit rating</button>
                        <span class="massinc-rating-count massinc-state-after" data-submitted-note></span>
                        <p class="massinc-field-error" data-rating-error role="alert" hidden></p>
                    </div>

                    <?php /* view.js rewrites this box as ratings come in. */ ?>
                    <div class="massinc-ratings-hidden massinc-state-before">
                        <?php if ($total_ratings) : ?>
                            <p class="massinc-ratings-hidden-title" data-hidden-title>
                                <?php echo esc_html(sprintf(_n('%1$d reviewer has already rated %2$s', '%1$d reviewers have already rated %2$s', $total_ratings, 'minc-hiring'), $total_ratings, $first_name)); ?>
                            </p>
                            <p class="massinc-muted" data-hidden-body>Their scores and the overall average stay hidden until you pick your name and submit a rating, so nobody anchors on someone else&rsquo;s score.</p>
                        <?php else : ?>
                            <p class="massinc-ratings-hidden-title" data-hidden-title>No one has rated <?php echo esc_html($first_name); ?> yet</p>
                            <p class="massinc-muted" data-hidden-body>Pick your name and submit a rating to be the first.</p>
                        <?php endif; ?>
                    </div>

                    <div class="massinc-ratings-visible massinc-state-after">
                        <hr class="massinc-divider">
                        <div data-reviews></div>
                        <p class="massinc-muted massinc-visibility-note">Individual ratings are visible because <span data-viewer-name></span> has submitted a rating. Reviewers who have not rated yet see only their own row.</p>
                    </div>
                </section>

                <section class="massinc-card" aria-labelledby="massinc-notes-title">
                    <h2 class="massinc-card-title" id="massinc-notes-title">Notes</h2>
                    <div class="massinc-notes" data-notes>
                        <?php if (!$candidate['notes']) : ?>
                            <p class="massinc-muted" data-notes-empty>No notes yet.</p>
                        <?php endif; ?>
                        <?php foreach ($candidate['notes'] as $note) : ?>
                            <div class="massinc-note">
                                <span class="massinc-avatar massinc-avatar-sm" aria-hidden="true"><?php echo esc_html(minc_hiring_initials($note['author'])); ?></span>
                                <div class="massinc-note-info">
                                    <p class="massinc-note-byline"><?php echo esc_html($note['author'] . '  ·  ' . $note['date']); ?></p>
                                    <p class="massinc-note-text"><?php echo esc_html($note['text']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <form class="massinc-note-form" data-note-form novalidate>
                        <div>
                            <label class="massinc-field-label" for="massinc-note-author">Your name</label>
                            <?php minc_hiring_dropdown([
                                'id'          => 'massinc-note-author',
                                'field'       => 'author',
                                'mode'        => 'names',
                                'options'     => $name_options,
                                'placeholder' => 'Select your name',
                                'removable'   => true,
                                'class'       => 'massinc-rating-control',
                            ]); ?>
                        </div>
                        <textarea class="massinc-input" data-note-text rows="3" placeholder="Add a note about this candidate&hellip;" aria-label="Note"></textarea>
                        <p class="massinc-field-error" data-note-error role="alert" hidden></p>
                        <button type="submit" class="massinc-btn massinc-btn-primary massinc-btn-start">Save note</button>
                    </form>
                </section>
            </div>

            <aside class="massinc-detail-sidebar">
                <section class="massinc-card" aria-labelledby="massinc-stage-title">
                    <h2 class="massinc-card-title" id="massinc-stage-title">Stage</h2>
                    <div class="massinc-current-stage">
                        <span class="massinc-muted">Currently</span>
                        <?php echo minc_hiring_stage_badge($candidate['stage']); ?>
                    </div>
                    <?php if ($is_admin) : ?>
                        <div>
                            <label class="massinc-field-label" for="massinc-move-to">Move to</label>
                            <?php minc_hiring_dropdown([
                                'id'      => 'massinc-move-to',
                                'field'   => 'stage',
                                'options' => $stage_dropdown_options,
                                'value'   => $candidate['stage'],
                            ]); ?>
                        </div>
                        <button type="button" class="massinc-btn massinc-btn-primary massinc-btn-block" data-confirm-stage disabled>Confirm</button>
                        <p class="massinc-note-small" data-stage-status role="status" hidden></p>
                        <p class="massinc-field-error" data-stage-error role="alert" hidden></p>
                    <?php else : ?>
                        <p class="massinc-note-small">Only admins can change stages.</p>
                    <?php endif; ?>
                </section>

                <section class="massinc-card" aria-labelledby="massinc-links-title">
                    <h2 class="massinc-card-title" id="massinc-links-title">Links</h2>
                    <?php if (!$links) : ?>
                        <p class="massinc-muted">No links were submitted.</p>
                    <?php else : ?>
                        <ul class="massinc-links">
                            <?php foreach ($links as $link) : ?>
                                <li class="massinc-link-row">
                                    <?php echo minc_hiring_icon($link['icon']); ?>
                                    <a class="massinc-link" href="<?php echo esc_url($link['href']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($link['label']); ?></a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </aside>
        </div>
    </div>
</div>
