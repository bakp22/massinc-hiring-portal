<?php
/**
 * Frontend render template for Candidate Detail block.
 *
 * Two states, switched by data-state on the wrapper:
 *  - "before": the viewer hasn't rated yet. Other reviewers' scores and the
 *    average stay hidden so nobody anchors on someone else's score.
 *  - "after": the viewer has rated, so the average and each reviewer's rating
 *    and comment are shown.
 * view.js flips before -> after when "Submit rating" is clicked. Nothing is
 * persisted yet (no ratings table or endpoint), so reloading starts over; add
 * ?preview=rated to the URL to load the "after" state directly.
 */
defined('ABSPATH') || exit;

if (!minc_hiring_require_staff()) {
    return;
}

$dashboard_url = !empty($attributes['dashboardUrl']) ? $attributes['dashboardUrl'] : home_url('/');
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
$stage_keys = array_keys($stages);
$next_stage = $stage_keys[min(array_search($candidate['stage'], $stage_keys, true) + 1, count($stage_keys) - 1)];

// Sum and count of the other reviewers' ratings, for the running average.
if ($candidate['reviews']) {
    $others_count = count($candidate['reviews']);
    $others_sum = array_sum(array_column($candidate['reviews'], 'score'));
} else {
    $others_count = (int) $candidate['count'];
    $others_sum = (float) $candidate['rating'] * $others_count;
}

// Demo only: the "after" preview pretends the viewer gave 4 stars on 19 Aug 2026.
$viewer_score = (isset($_GET['preview']) && $_GET['preview'] === 'rated') ? 4 : 0;
$rated = $viewer_score > 0;
$avg = $rated ? ($others_sum + $viewer_score) / ($others_count + 1) : null;
$total_ratings = $others_count + 1;

$wrapper_attributes = get_block_wrapper_attributes([
    'class'       => 'massinc-ats massinc-detail',
    'data-state'  => $rated ? 'after' : 'before',
    'data-first-name' => $first_name,
]);

$meta = ['Applied for ' . $candidate['position'], $candidate['applied'], 'Reference ' . $candidate['reference']];
$contact = array_filter([$candidate['email'], $candidate['phone'], $candidate['location']]);

// Reviewer rows, rendered inline once the viewer has rated and held in a
// <template> before that.
$render_reviews = function () use ($candidate) {
    foreach ($candidate['reviews'] as $review) :
        ?>
        <div class="massinc-review">
            <span class="massinc-avatar massinc-avatar-sm" aria-hidden="true"><?php echo esc_html(minc_hiring_initials($review['reviewer'])); ?></span>
            <div class="massinc-review-info">
                <p class="massinc-review-name"><?php echo esc_html($review['reviewer']); ?></p>
                <p class="massinc-review-comment"><?php echo esc_html($review['comment']); ?></p>
            </div>
            <div class="massinc-review-score">
                <span class="massinc-rating"><?php echo minc_hiring_stars($review['score']); ?>
                    <span class="massinc-rating-value"><?php echo esc_html(number_format($review['score'], 1)); ?></span></span>
                <span class="massinc-rating-count"><?php echo esc_html($review['date']); ?></span>
            </div>
        </div>
        <?php
    endforeach;
};
?>

<div <?php echo $wrapper_attributes; ?>
     data-others-sum="<?php echo esc_attr($others_sum); ?>"
     data-others-count="<?php echo esc_attr($others_count); ?>">
    <?php minc_hiring_admin_nav(); ?>

    <div class="massinc-detail-body">
        <a class="massinc-back-link" href="<?php echo esc_url($dashboard_url); ?>">&larr;&nbsp; All candidates</a>

        <div class="massinc-detail-header">
            <div class="massinc-detail-identity">
                <h1 class="massinc-detail-name"><?php echo esc_html($candidate['name']); ?></h1>
                <?php echo minc_hiring_stage_badge($candidate['stage']); ?>
                <span class="massinc-rating massinc-state-after" data-avg-header>
                    <?php if ($rated) : ?>
                        <?php echo minc_hiring_stars($avg); ?>
                        <span class="massinc-rating-value"><?php echo esc_html(number_format($avg, 1)); ?></span>
                        <span class="massinc-rating-count">(<?php echo (int) $total_ratings; ?> ratings)</span>
                    <?php endif; ?>
                </span>
            </div>
            <p class="massinc-detail-meta"><?php echo esc_html(implode('  ·  ', $meta)); ?></p>
            <p class="massinc-detail-meta"><?php echo esc_html(implode('  ·  ', $contact)); ?></p>
        </div>

        <div class="massinc-detail-columns">
            <div class="massinc-detail-main">

                <section class="massinc-card" aria-labelledby="massinc-materials-title">
                    <h2 class="massinc-card-title" id="massinc-materials-title">Materials</h2>
                    <?php if (!$candidate['materials']) : ?>
                        <p class="massinc-muted">No files or links were submitted with this application.</p>
                    <?php endif; ?>
                    <?php foreach ($candidate['materials'] as $item) : ?>
                        <div class="massinc-material">
                            <span class="massinc-file-badge" aria-hidden="true"><?php echo esc_html($item['ext']); ?></span>
                            <div class="massinc-material-info">
                                <p class="massinc-material-name"><?php echo esc_html($item['name']); ?></p>
                                <p class="massinc-material-meta"><?php echo esc_html($item['meta']); ?></p>
                            </div>
                            <a class="massinc-btn"
                               href="<?php echo $item['href'] ? esc_url($item['href']) : '#'; ?>"
                               <?php echo $item['action'] === 'Open' ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
                                <?php echo esc_html($item['action']); ?><span class="screen-reader-text"> <?php echo esc_html($item['name']); ?></span>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </section>

                <section class="massinc-card" aria-labelledby="massinc-ratings-title">
                    <h2 class="massinc-card-title" id="massinc-ratings-title">Ratings</h2>

                    <div class="massinc-overall massinc-state-after">
                        <span class="massinc-row-label">Overall</span>
                        <span class="massinc-rating" data-avg-overall>
                            <?php if ($rated) : ?>
                                <?php echo minc_hiring_stars($avg); ?>
                                <span class="massinc-rating-value"><?php echo esc_html(number_format($avg, 1)); ?></span>
                                <span class="massinc-rating-count">average of <?php echo (int) $total_ratings; ?> reviewers</span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="massinc-your-rating">
                        <span class="massinc-row-label">Your rating</span>
                        <div class="massinc-star-input" role="group" aria-label="Your rating out of 5" data-score="<?php echo esc_attr($viewer_score); ?>">
                            <?php for ($i = 1; $i <= 5; $i++) : ?>
                                <button type="button" class="massinc-star-button" data-value="<?php echo $i; ?>"
                                        aria-label="<?php echo esc_attr($i . ($i === 1 ? ' star' : ' stars')); ?>"
                                        aria-pressed="<?php echo $viewer_score === $i ? 'true' : 'false'; ?>">
                                    <?php echo minc_hiring_star($i <= $viewer_score ? 'full' : 'empty'); ?>
                                </button>
                            <?php endfor; ?>
                        </div>
                        <span class="massinc-rating-count" data-your-hint aria-live="polite">
                            <?php echo $rated
                                ? esc_html($viewer_score . ' of 5 · submitted 19 Aug 2026 · click a star to change')
                                : 'Not rated yet · click a star to rate'; ?>
                        </span>
                        <button type="button" class="massinc-btn massinc-btn-primary massinc-state-before" data-submit-rating>Submit rating</button>
                    </div>

                    <div class="massinc-ratings-hidden massinc-state-before">
                        <?php if ($others_count) : ?>
                            <p class="massinc-ratings-hidden-title">
                                <?php echo esc_html(sprintf(_n('%1$d other reviewer has rated %2$s', '%1$d other reviewers have rated %2$s', $others_count, 'minc-hiring'), $others_count, $first_name)); ?>
                            </p>
                            <p class="massinc-muted">Their scores, comments and the overall average stay hidden until you submit your own rating, so nobody anchors on someone else&rsquo;s score.</p>
                        <?php else : ?>
                            <p class="massinc-ratings-hidden-title">No other reviewers have rated <?php echo esc_html($first_name); ?> yet</p>
                            <p class="massinc-muted">Your rating will be the first.</p>
                        <?php endif; ?>
                    </div>

                    <div class="massinc-ratings-visible massinc-state-after">
                        <?php if ($candidate['reviews']) : ?>
                            <hr class="massinc-divider">
                            <div data-reviews>
                                <?php if ($rated) { $render_reviews(); } ?>
                            </div>
                        <?php endif; ?>
                        <p class="massinc-muted massinc-visibility-note">You can see individual ratings because you have submitted yours. Reviewers who have not rated yet see only their own row.</p>
                    </div>

                    <?php if (!$rated && $candidate['reviews']) : ?>
                        <?php /* Demo only: held for the client-side reveal; a real version must not send other reviewers' scores to the browser before the viewer has rated. */ ?>
                        <template data-reviews-template>
                            <hr class="massinc-divider">
                            <?php $render_reviews(); ?>
                        </template>
                    <?php endif; ?>
                </section>

                <section class="massinc-card" aria-labelledby="massinc-notes-title">
                    <h2 class="massinc-card-title" id="massinc-notes-title">Notes</h2>
                    <?php if (!$candidate['notes']) : ?>
                        <p class="massinc-muted">No notes yet.</p>
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
                    <button type="button" class="massinc-btn massinc-btn-start">Add note</button>
                </section>
            </div>

            <aside class="massinc-detail-sidebar">
                <section class="massinc-card" aria-labelledby="massinc-stage-title">
                    <h2 class="massinc-card-title" id="massinc-stage-title">Stage</h2>
                    <div class="massinc-current-stage">
                        <span class="massinc-muted">Currently</span>
                        <?php echo minc_hiring_stage_badge($candidate['stage']); ?>
                    </div>
                    <div>
                        <label class="massinc-field-label" for="massinc-move-to">Move to</label>
                        <div class="massinc-select">
                            <select id="massinc-move-to" class="massinc-input">
                                <?php foreach ($stages as $key => $label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($key, $next_stage); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <button type="button" class="massinc-btn massinc-btn-primary massinc-btn-block">Advance stage</button>
                    <button type="button" class="massinc-btn massinc-btn-block">Reject candidate</button>
                </section>

                <section class="massinc-card" aria-labelledby="massinc-interview-title">
                    <h2 class="massinc-card-title" id="massinc-interview-title">Schedule interview</h2>
                    <div>
                        <label class="massinc-field-label" for="massinc-interview-date">Date</label>
                        <input type="text" id="massinc-interview-date" class="massinc-input" placeholder="Pick a date&hellip;" data-date-input>
                    </div>
                    <button type="button" class="massinc-btn massinc-btn-block">Send invite</button>
                </section>
            </aside>
        </div>
    </div>
</div>
