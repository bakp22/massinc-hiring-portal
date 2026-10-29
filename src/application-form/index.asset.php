<?php
// Declares the wp.* globals index.js relies on so WordPress loads them first.
return [
    'dependencies' => ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components'],
    'version'      => '1',
];
