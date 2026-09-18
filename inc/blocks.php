<?php
defined('ABSPATH') || exit;

function minc_hiring_register_blocks() {
    add_action('init', function () {
        register_block_type(MINC_HIRING_PATH . 'src/job-list');
        register_block_type(MINC_HIRING_PATH . 'src/application-form');
    });
}
