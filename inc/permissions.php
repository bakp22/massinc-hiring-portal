<?php
defined('ABSPATH') || exit;

function minc_hiring_register_roles() {
    add_role('minc_hiring_manager', 'Hiring Manager', [
        'read' => true,
        'manage_minc_hiring' => true,
        'view_minc_applications' => true,
        'edit_minc_applications' => true,
    ]);

    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap('manage_minc_hiring');
        $admin->add_cap('view_minc_applications');
        $admin->add_cap('edit_minc_applications');
    }
}

function minc_hiring_can_manage() {
    return current_user_can('manage_minc_hiring');
}
