<?php

namespace Jelmoo\Planner;

/*
 * Eigen tabellen voor taken en geraakte rijen. Geen options of CPT: de waarden
 * van voor en na een wissel kunnen megabytes zijn en mogen niet in de eigen
 * zoekscope belanden.
 */
class Store
{
    private const DB_VERSION = '3';

    public const LEASE_SECONDS = 240;

    public static function jobs_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'jelmoo_planner_jobs';
    }

    public static function rows_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'jelmoo_planner_rows';
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $collate = $wpdb->get_charset_collate();
        $jobs = self::jobs_table();
        $rows = self::rows_table();

        dbDelta("CREATE TABLE {$jobs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(10) NOT NULL,
            status varchar(20) NOT NULL,
            run_at datetime NOT NULL,
            old_url text NOT NULL,
            new_url text NOT NULL,
            selector varchar(191) NOT NULL DEFAULT '',
            display_value varchar(20) NOT NULL DEFAULT '',
            hide_selector varchar(191) NOT NULL DEFAULT '',
            page_url text NULL,
            mail_to varchar(191) NOT NULL DEFAULT '',
            claimed_at datetime NULL,
            state longtext NULL,
            report longtext NULL,
            next_check_at datetime NULL,
            checks_done tinyint(3) unsigned NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            finished_at datetime NULL,
            PRIMARY KEY  (id),
            KEY status_run (status,run_at)
        ) {$collate};");

        dbDelta("CREATE TABLE {$rows} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            target varchar(20) NOT NULL,
            pk bigint(20) unsigned NOT NULL,
            col varchar(32) NOT NULL,
            owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
            field varchar(191) NOT NULL DEFAULT '',
            state varchar(12) NOT NULL,
            matches int(10) unsigned NOT NULL DEFAULT 0,
            reason varchar(255) NOT NULL DEFAULT '',
            before_value longtext NULL,
            after_value longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_location (job_id,target,pk,col),
            KEY job_state (job_id,state)
        ) {$collate};");

        update_option('jelmoo_planner_db_version', self::DB_VERSION, false);
    }

    public static function maybe_upgrade(): void
    {
        if (get_option('jelmoo_planner_db_version') !== self::DB_VERSION) {
            self::install();
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function create_job(array $fields): int
    {
        global $wpdb;

        $wpdb->insert(self::jobs_table(), array_merge($fields, [
            'status' => 'scheduled',
            'created_by' => get_current_user_id(),
            'created_at' => self::now(),
        ]));

        return (int) $wpdb->insert_id;
    }

    public static function get_job(int $id): ?array
    {
        global $wpdb;

        $job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE id = %d', $id), ARRAY_A);

        return $job ? self::decode($job) : null;
    }

    public static function list_jobs(int $limit = 100): array
    {
        global $wpdb;

        $jobs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' ORDER BY run_at DESC, id DESC LIMIT %d', $limit), ARRAY_A);

        return array_map([self::class, 'decode'], $jobs ?: []);
    }

    public static function update_job(int $id, array $fields): void
    {
        global $wpdb;

        foreach (['state', 'report'] as $json) {
            if (array_key_exists($json, $fields)) {
                $fields[$json] = wp_json_encode($fields[$json]);
            }
        }

        $wpdb->update(self::jobs_table(), $fields, ['id' => $id]);
    }

    private static function decode(array $job): array
    {
        $job['id'] = (int) $job['id'];
        $job['state'] = json_decode((string) $job['state'], true) ?: [];
        $job['report'] = json_decode((string) $job['report'], true) ?: [];

        return $job;
    }

    /** Ingeplande taak oppakken. De voorwaarde op status maakt dit ook het slot tegen dubbel draaien. */
    public static function claim_due(int $id): bool
    {
        global $wpdb;

        $now = self::now();
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::jobs_table() . " SET status = 'preparing', claimed_at = %s WHERE id = %d AND status = 'scheduled' AND run_at <= %s",
            $now,
            $id,
            $now
        ));

        return $done === 1;
    }

    /** Lopende taak of nacontrole oppakken, als niemand anders ermee bezig is. */
    public static function claim_lease(int $id): bool
    {
        global $wpdb;

        $now = self::now();
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::LEASE_SECONDS);
        $done = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::jobs_table() . ' SET claimed_at = %s WHERE id = %d AND (claimed_at IS NULL OR claimed_at < %s)',
            $now,
            $id,
            $cutoff
        ));

        return $done === 1;
    }

    public static function release(int $id): void
    {
        self::update_job($id, ['claimed_at' => null]);
    }

    public static function due_job_ids(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . self::jobs_table() . " WHERE status = 'scheduled' AND run_at <= %s ORDER BY run_at ASC",
            self::now()
        )));
    }

    public static function running_job_ids(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col(
            'SELECT id FROM ' . self::jobs_table() . " WHERE status IN ('preparing','applying','finishing') ORDER BY run_at ASC"
        ));
    }

    public static function check_due_job_ids(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . self::jobs_table() . " WHERE status IN ('done','warning') AND next_check_at IS NOT NULL AND next_check_at <= %s ORDER BY next_check_at ASC",
            self::now()
        )));
    }

    public static function upsert_row(int $job_id, array $location, string $state, int $matches, string $reason, ?string $before, ?string $after): void
    {
        global $wpdb;

        // Hervatten na een timeout kan dezelfde plek opnieuw langs laten komen.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::rows_table() . ' (job_id, target, pk, col, owner_id, field, state, matches, reason, before_value, after_value)
             VALUES (%d, %s, %d, %s, %d, %s, %s, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE state = VALUES(state), matches = VALUES(matches), reason = VALUES(reason), before_value = VALUES(before_value), after_value = VALUES(after_value)',
            $job_id,
            $location['target'],
            $location['pk'],
            $location['col'],
            $location['owner'],
            substr($location['field'], 0, 191),
            $state,
            $matches,
            substr($reason, 0, 255),
            (string) $before,
            (string) $after
        ));
    }

    public static function rows_with_state(int $job_id, string $state, int $limit): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::rows_table() . ' WHERE job_id = %d AND state = %s ORDER BY id ASC LIMIT %d',
            $job_id,
            $state,
            $limit
        ), ARRAY_A) ?: [];
    }

    public static function update_row(int $id, array $fields): void
    {
        global $wpdb;

        $wpdb->update(self::rows_table(), $fields, ['id' => $id]);
    }

    /** Rijen zonder de (mogelijk grote) waarden, voor rapport en scherm. */
    public static function row_summaries(int $job_id): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT target, pk, col, owner_id, field, state, matches, reason FROM ' . self::rows_table() . ' WHERE job_id = %d ORDER BY id ASC LIMIT 500',
            $job_id
        ), ARRAY_A) ?: [];
    }

    public static function count_by_state(int $job_id): array
    {
        global $wpdb;

        $counts = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT state, COUNT(*) AS rows_count, SUM(matches) AS matches_count FROM ' . self::rows_table() . ' WHERE job_id = %d GROUP BY state',
            $job_id
        ), ARRAY_A) ?: [];

        foreach ($rows as $row) {
            $counts[$row['state']] = ['rows' => (int) $row['rows_count'], 'matches' => (int) $row['matches_count']];
        }

        return $counts;
    }

    /** Ruimt oude terugzet-data op; daarna kan een wissel niet meer ongedaan gemaakt worden. */
    public static function cleanup(int $days): void
    {
        global $wpdb;

        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::rows_table() . ' r INNER JOIN ' . self::jobs_table() . " j ON j.id = r.job_id
             SET r.before_value = NULL, r.after_value = NULL
             WHERE j.finished_at IS NOT NULL AND j.finished_at < %s AND r.before_value IS NOT NULL",
            $cutoff
        ));
    }
}
