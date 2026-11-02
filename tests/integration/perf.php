<?php
/*
 * Meet een wissel op een grote postmeta-tabel en telt hoeveel ticks het kost.
 * Eerst rijen toevoegen (zie README), dan in de webcontainer draaien.
 */

use Jelmoo\Planner\Runner;
use Jelmoo\Planner\Store;

$_SERVER['HTTP_HOST'] = 'localhost:8002';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
ini_set('display_errors', '0');

global $wpdb;

$ids = json_decode(file_get_contents(WP_CONTENT_DIR . '/uploads/planner-fixtures.json'), true);
$rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}");
$id = Store::create_job([
    'type' => 'link',
    'run_at' => gmdate('Y-m-d H:i:s', time() - 60),
    'old_url' => $ids['old'],
    'new_url' => $ids['new'],
    'mail_to' => '',
]);

$start = microtime(true);
$ticks = 0;
do {
    Store::update_job($id, ['claimed_at' => null]);
    Runner::tick();
    $ticks++;
    $job = Store::get_job($id);
    printf("tick %d: %.1fs status=%s\n", $ticks, microtime(true) - $start, $job['status']);
} while (in_array($job['status'], ['scheduled', 'preparing', 'applying', 'finishing'], true) && $ticks < 50);

printf("%d postmeta-rijen: %.1f seconden, %d tick(s), status %s, %.0f MB piekgeheugen\n", $rows, microtime(true) - $start, $ticks, $job['status'], memory_get_peak_usage(true) / 1048576);
echo json_encode($job['report']['counts'] ?? []), "\n";
