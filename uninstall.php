<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Backups in uploads/jelmoo-planner-backups blijven staan: dat kan de enige kopie van een oud bestand zijn.
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}jelmoo_planner_rows");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}jelmoo_planner_jobs");

delete_option('jelmoo_planner_db_version');
delete_option('jelmoo_planner_last_tick');

wp_clear_scheduled_hook('jelmoo_planner_tick');
wp_clear_scheduled_hook('jelmoo_planner_continue');
