<?php
// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }
global $wpdb;
$table = $wpdb->prefix . 'rtodo_tasks';
// Delete the table (comment out if you prefer to keep data on uninstall)
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
