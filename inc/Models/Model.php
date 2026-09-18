<?php
namespace MassIncHiring\Models;

defined('ABSPATH') || exit;

abstract class Model {
    protected $data = [];

    public function __construct($data = []) {
        $this->data = is_object($data) ? get_object_vars($data) : (array) $data;
    }

    public function get($key, $default = null) {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function get_id() {
        return (int) $this->get('id', 0);
    }
}
