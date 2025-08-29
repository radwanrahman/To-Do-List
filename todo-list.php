<?php
/**
 * Plugin Name: RToDo — To-Do List for WordPress
 * Description: A simple, per-user to-do list inside the WordPress Dashboard with priorities, statuses, and email reminders.
 * Version: 1.0.0
 * Author: Radwan Rahman (Project: 231-134-009)
 * Text Domain: rtodo
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { 
    exit; 
}

define( 'RTODO_VERSION', '1.0.0' );
define( 'RTODO_PLUGIN_FILE', __FILE__ );
define( 'RTODO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RTODO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

global $wpdb;
define( 'RTODO_TABLE', $wpdb->prefix . 'rtodo_tasks' );
