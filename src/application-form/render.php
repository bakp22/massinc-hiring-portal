<?php
/**
 * Frontend render template for Application Form block.
 */
defined('ABSPATH') || exit;

use MassINC\Models\Job;

// The job list links here with ?job_id=; the block's jobId setting is the fallback.
$job_id = isset($_GET['job_id']) ? absint($_GET['job_id']) : 0;
if (!$job_id && !empty($attributes['jobId'])) {
    $job_id = absint($attributes['jobId']);
}

$job = $job_id ? Job::get_by_id($job_id) : null;
$back_url = remove_query_arg('job_id', get_permalink());

if (!$job || $job->get('status') !== 'open') {
    ?>
    <div <?php echo get_block_wrapper_attributes(['class' => 'massinc-application-form-block']); ?>>
        <p>This position isn't open for applications.</p>
        <p><a class="massinc-back-link" href="<?php echo esc_url($back_url); ?>">&larr; All positions</a></p>
    </div>
    <?php
    return;
}

$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'massinc-application-form-block',
    'data-endpoint' => esc_url(rest_url('massinc/v1/applications')),
]);

$job_meta = array_filter([
    $job->get('location'),
    $job->get('employment_type'),
]);

// Shared by all three upload areas: [field name, label, hint, max files, required].
$upload_fields = [
    ['resume', 'Resume', '', 1, true],
    ['cover_letter', 'Cover letter', 'Optional, but it helps us hear your voice.', 1, false],
    ['writing_samples', 'Writing or content samples', 'Up to 3 files.', 3, false],
];
?>

<div <?php echo $wrapper_attributes; ?>>
    <a class="massinc-back-link" href="<?php echo esc_url($back_url); ?>">&larr; All positions</a>

    <div class="massinc-form-header">
        <h2 class="massinc-form-title">Apply &mdash; <?php echo esc_html($job->get('title')); ?></h2>
        <?php if ($job_meta) : ?>
            <p class="massinc-form-meta"><?php echo esc_html(implode(' · ', $job_meta)); ?></p>
        <?php endif; ?>
        <p class="massinc-form-required-note">Required fields are marked *</p>
    </div>

    <form id="massinc-app-form" novalidate>
        <input type="hidden" name="job_id" value="<?php echo esc_attr($job->get_id()); ?>">

        <div class="massinc-form-error-summary" role="alert" hidden></div>

        <div class="massinc-field" data-field="first_name">
            <label for="massinc-first-name">First name <span aria-hidden="true">*</span></label>
            <input type="text" id="massinc-first-name" name="first_name" autocomplete="given-name" required>
            <p class="massinc-field-error" hidden></p>
        </div>

        <div class="massinc-field" data-field="last_name">
            <label for="massinc-last-name">Last name <span aria-hidden="true">*</span></label>
            <input type="text" id="massinc-last-name" name="last_name" autocomplete="family-name" required>
            <p class="massinc-field-hint">As it appears on your ID.</p>
            <p class="massinc-field-error" hidden></p>
        </div>

        <div class="massinc-field" data-field="applicant_email">
            <label for="massinc-email">Email <span aria-hidden="true">*</span></label>
            <input type="email" id="massinc-email" name="applicant_email" autocomplete="email" required>
            <p class="massinc-field-hint">We'll send your confirmation here.</p>
            <p class="massinc-field-error" hidden></p>
        </div>

        <div class="massinc-field" data-field="phone">
            <label for="massinc-phone">Phone</label>
            <input type="tel" id="massinc-phone" name="phone" autocomplete="tel" placeholder="e.g. (617) 555-0142">
            <p class="massinc-field-hint">Optional &mdash; only used if we need to reach you quickly.</p>
            <p class="massinc-field-error" hidden></p>
        </div>

        <?php foreach ($upload_fields as [$name, $label, $hint, $max, $required]) : ?>
            <div class="massinc-field massinc-upload"
                 data-field="<?php echo esc_attr($name); ?>"
                 data-max-files="<?php echo esc_attr($max); ?>"
                 data-required="<?php echo $required ? '1' : '0'; ?>">
                <span class="massinc-label" id="massinc-<?php echo esc_attr($name); ?>-label">
                    <?php echo esc_html($label); ?><?php if ($required) : ?> <span aria-hidden="true">*</span><?php endif; ?>
                </span>
                <ul class="massinc-file-list" aria-labelledby="massinc-<?php echo esc_attr($name); ?>-label"></ul>
                <div class="massinc-dropzone">
                    <input type="file" class="massinc-file-input"
                           id="massinc-<?php echo esc_attr($name); ?>"
                           accept=".pdf,.docx"
                           <?php echo $max > 1 ? 'multiple' : ''; ?>
                           aria-labelledby="massinc-<?php echo esc_attr($name); ?>-label">
                    <label for="massinc-<?php echo esc_attr($name); ?>" class="massinc-dropzone-prompt">
                        <span aria-hidden="true">+</span> <span class="massinc-link">Drop a file or browse</span>
                    </label>
                    <span class="massinc-dropzone-hint">PDF or DOCX, up to 10 MB</span>
                </div>
                <?php if ($hint) : ?>
                    <p class="massinc-field-hint"><?php echo esc_html($hint); ?></p>
                <?php endif; ?>
                <p class="massinc-field-error" hidden></p>
            </div>
        <?php endforeach; ?>

        <div class="massinc-field" data-field="sample_url">
            <label for="massinc-sample-url">Link to writing or content samples</label>
            <div class="massinc-link-list">
                <div class="massinc-link-row">
                    <input type="url" id="massinc-sample-url" name="sample_url" placeholder="https://">
                </div>
            </div>
            <button type="button" class="massinc-add-link">+ Add another link</button>
            <p class="massinc-field-hint">Optional &mdash; an alternative to uploading a file.</p>
            <p class="massinc-field-error" hidden></p>
        </div>

        <div class="massinc-submit-group">
            <p class="massinc-confirmation-note" hidden>
                We'll email a copy of your application to <span class="massinc-confirmation-email"></span> as soon as you submit.
            </p>

            <button type="submit" class="massinc-submit">Submit application</button>
        </div>
    </form>

    <div class="massinc-form-success" role="status" hidden>
        <div class="massinc-success-mark" aria-hidden="true">&#10003;</div>
        <h2 class="massinc-success-title">Application received</h2>
        <p class="massinc-success-lead">
            Thanks, <span data-confirm="first_name"></span>. We've emailed a copy of your application to
            <span data-confirm="email"></span>. The MassInc team reviews applications on a rolling basis
            and will be in touch within two weeks.
        </p>
        <dl class="massinc-success-summary">
            <div class="massinc-success-row">
                <dt>Position</dt>
                <dd><?php echo esc_html($job->get('title')); ?></dd>
            </div>
            <div class="massinc-success-row">
                <dt>Reference ID</dt>
                <dd data-confirm="reference"></dd>
            </div>
            <div class="massinc-success-row">
                <dt>Submitted</dt>
                <dd data-confirm="submitted"></dd>
            </div>
            <div class="massinc-success-row" data-confirm-row="attached">
                <dt>Attached</dt>
                <dd data-confirm="attached"></dd>
            </div>
        </dl>
        <a class="massinc-back-link" href="<?php echo esc_url($back_url); ?>">Back to open positions</a>
    </div>
</div>
