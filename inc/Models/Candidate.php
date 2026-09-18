<?php
namespace MassIncHiring\Models;

defined('ABSPATH') || exit;

class Candidate extends Model {
    public function get_name() {
        return trim($this->get('first_name', '') . ' ' . $this->get('last_name', ''));
    }

    public function get_email() {
        return (string) $this->get('email', '');
    }
}
