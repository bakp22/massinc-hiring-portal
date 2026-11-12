<?php
// view.js uses window.MincSelect from src/shared/select.js, so load that first.
// The version follows the file's modified time so browsers pick up every change.
return [
    'dependencies' => ['minc-hiring-ats-select'],
    'version'      => (string) @filemtime(__DIR__ . '/view.js'),
];
