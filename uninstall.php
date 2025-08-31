<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

global $wpdb;
$table = $wpdb->prefix . 'rtodo_tasks';
$wpdb->query("DROP TABLE IF EXISTS $table");
