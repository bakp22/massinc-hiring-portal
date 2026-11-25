<?php
/** The admin Positions view inside the Hiring Candidate Dashboard block. */
defined('ABSPATH') || exit;

use MassINC\Models\Job;

$positions = Job::get_open_with_applicant_counts();
$requirement_labels = Job::required_field_labels();
$wrapper_attributes = get_block_wrapper_attributes([
    'class' => 'massinc-ats massinc-positions',
    'data-jobs-endpoint' => rest_url('massinc/v1/jobs'),
    'data-nonce' => wp_create_nonce('wp_rest'),
]);
?>
<div <?php echo $wrapper_attributes; ?>>
    <?php minc_hiring_admin_nav(true, 'positions'); ?>
    <div class="massinc-positions-body">
        <div class="massinc-positions-header">
            <h1 class="massinc-positions-title">Positions</h1>
            <p>Toggle the fields required for each open position. Changes save automatically.</p>
        </div>
        <p class="massinc-positions-error" role="alert" hidden></p>
        <div class="massinc-positions-scroll" role="region" aria-label="Position requirements" tabindex="0">
            <table class="massinc-positions-table">
                <thead><tr>
                    <th scope="col">Title</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Applicants</th>
                    <?php foreach ($requirement_labels as $label) : ?><th scope="col"><?php echo esc_html($label); ?> required</th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php if (!$positions) : ?><tr><td colspan="9">No open positions are currently available.</td></tr><?php endif; ?>
                    <?php foreach ($positions as $position) : $requirements = $position->get_required_fields(); ?>
                        <tr data-position-id="<?php echo esc_attr($position->get_id()); ?>">
                            <th scope="row"><?php echo esc_html($position->get('title')); ?></th>
                            <td><?php echo minc_hiring_type_tag(minc_hiring_job_type($position->get('employment_type'))); ?></td>
                            <td><span class="massinc-position-status <?php echo $position->get('status') === 'open' ? 'is-open' : ''; ?>"><?php echo esc_html(ucfirst($position->get('status'))); ?></span></td>
                            <td><?php echo esc_html($position->get('applicant_count')); ?></td>
                            <?php foreach ($requirement_labels as $field => $label) : ?>
                                <td>
                                    <label class="massinc-position-switch">
                                        <input type="checkbox" role="switch" data-field="<?php echo esc_attr($field); ?>" <?php checked($requirements[$field]); ?>>
                                        <span class="massinc-position-track" aria-hidden="true"></span>
                                        <span class="screen-reader-text"><?php echo esc_html('Require ' . $label . ' for ' . $position->get('title')); ?></span>
                                    </label>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="massinc-positions-hint">Name and email are always required. Fields switched off remain optional.</p>
    </div>
</div>
