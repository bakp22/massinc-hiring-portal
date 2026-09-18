<?php
namespace MassIncHiring\Models;

defined('ABSPATH') || exit;

class Application extends Model {
    public function get_status() {
        return (string) $this->get('status', 'submitted');
    }

    public function get_job_id() {
        return (int) $this->get('job_id', 0);
    }

    public function get_candidate_id() {
        return (int) $this->get('candidate_id', 0);
    }
}
