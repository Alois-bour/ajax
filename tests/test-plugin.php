<?php

// A very basic test runner
function run_tests($tests) {
    $passes = 0;
    $failures = 0;
    $errors = 0;

    foreach ($tests as $test_name => $test_function) {
        try {
            $result = $test_function();
            if ($result === true) {
                echo "[PASS] $test_name\n";
                $passes++;
            } else {
                echo "[FAIL] $test_name: $result\n";
                $failures++;
            }
        } catch (Exception $e) {
            echo "[ERROR] $test_name: " . $e->getMessage() . "\n";
            $errors++;
        }
    }

    echo "\n-------------------\n";
    echo "Tests complete: $passes passes, $failures failures, $errors errors.\n";

    // Exit with a non-zero status code if there are failures or errors
    if ($failures > 0 || $errors > 0) {
        exit(1);
    }
}

// Mock WordPress functions and variables needed for the plugin to load without fatal errors.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
global $wpdb;
$wpdb = new stdClass();
$wpdb->prefix = 'wp_';

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) { return true; }
function add_filter($tag, $function_to_add, $priority = 10, $accepted_args = 1) { return true; }
function add_shortcode($tag, $callback) { return true; }
function register_activation_hook($file, $function) { return true; }
function add_rewrite_rule($regex, $query, $after) { return true; }
function load_plugin_textdomain($domain, $abs_rel_path = false, $plugin_rel_path = false) { return true; }
function get_option($option, $default = false) { return $default; }
function checked($checked, $current = true, $echo = true) { return " checked='checked'"; }
function wp_nonce_field($action = -1, $name = "_wpnonce", $referer = true , $echo = true) { return; }
function sanitize_text_field($str) { return $str; }
function sanitize_email($email) { return $email; }
function is_email($email) { return true; }
function wp_unslash($value) { return $value; }
function esc_url_raw($url, $protocols = null) { return $url; }
function esc_attr($text) { return $text; }
function esc_html($text) { return $text; }
function esc_textarea($text) { return $text; }
function home_url($path = '', $scheme = null) { return 'http://localhost'; }


// Include the plugin file to test
require_once __DIR__ . '/../reservations-personnalisees-pro.php';

// --- Test Cases ---

function test_escape_ics_text_handles_special_characters() {
    $plugin_instance = ReservationsPlugin::get_instance();

    $input = "Test, with, commas; and; semicolons\\ and backslashes.\nAnd a newline.";
    $expected_output = "Test\\, with\\, commas\\; and\\; semicolons\\\\ and backslashes.\\nAnd a newline.";

    $actual_output = $plugin_instance->escape_ics_text($input);

    if ($actual_output !== $expected_output) {
        return "Expected: '$expected_output', but got: '$actual_output'";
    }

    return true;
}

function test_escape_ics_text_handles_empty_string() {
    $plugin_instance = ReservationsPlugin::get_instance();
    $input = "";
    $expected_output = "";
    $actual_output = $plugin_instance->escape_ics_text($input);

    if ($actual_output !== $expected_output) {
        return "Expected: '$expected_output', but got: '$actual_output'";
    }

    return true;
}

function test_escape_ics_text_handles_no_special_characters() {
    $plugin_instance = ReservationsPlugin::get_instance();
    $input = "This is a simple string with no special characters.";
    $expected_output = "This is a simple string with no special characters.";
    $actual_output = $plugin_instance->escape_ics_text($input);

    if ($actual_output !== $expected_output) {
        return "Expected: '$expected_output', but got: '$actual_output'";
    }

    return true;
}


// --- Test Definitions ---

$tests = [
    'test_escape_ics_text_handles_special_characters' => 'test_escape_ics_text_handles_special_characters',
    'test_escape_ics_text_handles_empty_string' => 'test_escape_ics_text_handles_empty_string',
    'test_escape_ics_text_handles_no_special_characters' => 'test_escape_ics_text_handles_no_special_characters',
];

// Run the tests
run_tests($tests);