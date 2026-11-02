<?php
/*
 * Plant een taak die meteen aan de beurt is.
 * Draaien met: wp eval-file plan-job.php
 */

use Jelmoo\Planner\Store;

$ids = json_decode(file_get_contents(WP_CONTENT_DIR . '/uploads/planner-fixtures.json'), true);

$id = Store::create_job([
    'type' => 'link',
    'run_at' => gmdate('Y-m-d H:i:s', time() - 60),
    'old_url' => $ids['old'],
    'new_url' => $ids['new'],
    'mail_to' => 'test@example.com',
]);

WP_CLI::success('Taak ' . $id);
