<?php
/**
 * Frontend render template for Candidate Dashboard block.
 */
defined('ABSPATH') || exit;

$wrapper_attributes = get_block_wrapper_attributes(['class' => 'massinc-ats massinc-dashboard']);

if (!minc_hiring_require_staff()) {
    return;
}

$candidates = minc_hiring_get_candidates();
$detail_base = !empty($attributes['detailUrl'])
    ? $attributes['detailUrl']
    : minc_hiring_find_page_url('minc-hiring/candidate-detail', get_permalink());
if (minc_hiring_is_demo()) {
    $detail_base = add_query_arg('demo', '1', $detail_base);
}
$stages = minc_hiring_stages();
$rating_filters = ['Any' => 0, '2+' => 2, '3+' => 3, '4+' => 4, '5' => 5];

// Counts on the stage pills are totals, so they don't change with other filters.
$stage_counts = array_count_values(array_column($candidates, 'stage'));
$positions = array_unique(array_column($candidates, 'position'));
sort($positions, SORT_NATURAL | SORT_FLAG_CASE);
?>

<div <?php echo $wrapper_attributes; ?>>
    <?php minc_hiring_admin_nav(); ?>

    <div class="massinc-dashboard-body">
        <div class="massinc-dashboard-header">
            <h1 class="massinc-dashboard-title">Candidates</h1>
            <p class="massinc-dashboard-count" aria-live="polite">
                <span data-count><?php echo esc_html(count($candidates)); ?></span>
                <span data-count-label><?php echo count($candidates) === 1 ? 'candidate' : 'candidates'; ?></span>
                &middot; sorted by <span data-sort-label>date applied, newest first</span>
            </p>
        </div>

        <div class="massinc-dashboard-toolbar">
            <div class="massinc-dashboard-filters">
                <div class="massinc-dashboard-search">
                    <label class="massinc-field-label" for="massinc-candidate-search">Search</label>
                    <input type="search" id="massinc-candidate-search" class="massinc-input" placeholder="Name, position or email&hellip;" autocomplete="off">
                </div>

                <div class="massinc-dashboard-position">
                    <label class="massinc-field-label" for="massinc-candidate-position">Position</label>
                    <?php minc_hiring_dropdown([
                        'id'                 => 'massinc-candidate-position',
                        'field'              => 'position',
                        'options'            => array_map(function ($position) {
                            return ['value' => $position, 'label' => $position];
                        }, $positions),
                        'placeholder'        => 'All positions',
                        'placeholder_option' => true,
                    ]); ?>
                </div>
            </div>

            <div class="massinc-pill-group" role="group" aria-labelledby="massinc-min-rating-label" data-filter="rating">
                <span class="massinc-pill-label" id="massinc-min-rating-label">Minimum rating</span>
                <?php foreach ($rating_filters as $label => $value) : ?>
                    <button type="button" class="massinc-pill<?php echo $value === 0 ? ' is-active' : ''; ?>"
                            data-value="<?php echo esc_attr($value); ?>"
                            aria-pressed="<?php echo $value === 0 ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?></button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="massinc-pill-group" role="group" aria-label="Stage" data-filter="stage">
            <button type="button" class="massinc-pill is-active" data-value="" aria-pressed="true">All (<?php echo (int) count($candidates); ?>)</button>
            <?php foreach ($stages as $key => $label) : ?>
                <button type="button" class="massinc-pill" data-value="<?php echo esc_attr($key); ?>" aria-pressed="false"><?php echo esc_html($label . ' (' . (int) ($stage_counts[$key] ?? 0) . ')'); ?></button>
            <?php endforeach; ?>
        </div>

        <div class="massinc-table" role="table" aria-label="Candidates">
            <div class="massinc-table-row massinc-table-head" role="row">
                <span class="massinc-col-name" role="columnheader">Name</span>
                <span class="massinc-col-position" role="columnheader">Position</span>
                <span class="massinc-col-type" role="columnheader">Type</span>
                <span class="massinc-col-stage" role="columnheader">Stage</span>
                <?php /* Rating and Applied sort the table when clicked; the list starts newest application first. */ ?>
                <span class="massinc-col-rating" role="columnheader" aria-sort="none">
                    <button type="button" class="massinc-sort" data-sort="rating">Rating <span class="massinc-sort-arrow" aria-hidden="true"></span></button>
                </span>
                <span class="massinc-col-applied" role="columnheader" aria-sort="descending">
                    <button type="button" class="massinc-sort" data-sort="applied">Applied <span class="massinc-sort-arrow" aria-hidden="true">&#9660;</span></button>
                </span>
                <span class="massinc-col-action" role="columnheader"><span class="screen-reader-text">Actions</span></span>
            </div>

            <?php foreach ($candidates as $candidate) : ?>
                <div class="massinc-table-row"
                     role="row"
                     data-stage="<?php echo esc_attr($candidate['stage']); ?>"
                     data-position="<?php echo esc_attr($candidate['position']); ?>"
                     data-rating="<?php echo esc_attr($candidate['rating'] ?? -1); ?>"
                     data-applied="<?php echo esc_attr($candidate['applied_ts']); ?>"
                     data-search="<?php echo esc_attr(mb_strtolower($candidate['name'] . ' ' . $candidate['position'] . ' ' . $candidate['email'])); ?>">
                    <span class="massinc-col-name massinc-candidate-name" role="cell"><?php echo esc_html($candidate['name']); ?></span>
                    <span class="massinc-col-position" role="cell"><?php echo esc_html($candidate['position']); ?></span>
                    <span class="massinc-col-type" role="cell"><?php echo minc_hiring_type_tag($candidate['type']); ?></span>
                    <span class="massinc-col-stage" role="cell"><?php echo minc_hiring_stage_badge($candidate['stage']); ?></span>
                    <span class="massinc-col-rating" role="cell"><?php echo minc_hiring_rating($candidate['rating'], $candidate['count']); ?></span>
                    <span class="massinc-col-applied" role="cell"><?php echo esc_html($candidate['applied']); ?></span>
                    <span class="massinc-col-action" role="cell">
                        <a class="massinc-btn" href="<?php echo esc_url(add_query_arg('application', $candidate['id'], $detail_base)); ?>">
                            View<span class="screen-reader-text"> <?php echo esc_html($candidate['name']); ?></span>
                        </a>
                    </span>
                </div>
            <?php endforeach; ?>

            <p class="massinc-table-empty" hidden>No candidates match these filters.</p>
        </div>
    </div>
</div>
