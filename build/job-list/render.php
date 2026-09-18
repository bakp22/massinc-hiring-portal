<?php
defined('ABSPATH') || exit;

global $wpdb;
$table = $wpdb->prefix . 'minc_jobs';

$jobs = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, title, description, location, employment_type
         FROM $table
         WHERE status = %s
         ORDER BY created_at DESC",
        'open'
    )
);

if (!$jobs) {
    echo '<p>No open positions are currently available.</p>';
    return;
}
?>

<div class="minc-hiring-job-list">
    <?php foreach ($jobs as $job): ?>
        <article class="minc-hiring-job">
            <h3><?php echo esc_html($job->title); ?></h3>

            <?php if ($job->location): ?>
                <p><strong>Location:</strong> <?php echo esc_html($job->location); ?></p>
            <?php endif; ?>

            <?php if ($job->employment_type): ?>
                <p><strong>Type:</strong> <?php echo esc_html($job->employment_type); ?></p>
            <?php endif; ?>

            <a href="<?php echo esc_url(add_query_arg('job_id', (int) $job->id, get_permalink())); ?>">
                View position / Apply
            </a>
        </article>
    <?php endforeach; ?>
</div>
