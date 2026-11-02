<?php

namespace Jelmoo\Planner;

/*
 * Schermen onder Extra > Planner. Alleen voor beheerders.
 */
class Admin
{
    private const CAPABILITY = 'manage_options';
    private const PAGE = 'jelmoo-planner';

    // Op deze builders is de wissel echt getest; de rest heet in het scherm "ongetest".
    private const TESTED_BUILDERS = ['Elementor', 'Bricks', 'Beaver Builder'];

    public static function register(): void
    {
        add_action('admin_menu', function () {
            add_management_page('Planner', 'Planner', self::CAPABILITY, self::PAGE, [self::class, 'render']);
        });

        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
        add_action('admin_post_jelmoo_planner_create', [self::class, 'handle_create']);
        add_action('admin_post_jelmoo_planner_run', [self::class, 'handle_run']);
        add_action('admin_post_jelmoo_planner_cancel', [self::class, 'handle_cancel']);
        add_action('admin_post_jelmoo_planner_undo', [self::class, 'handle_undo']);
        add_action('wp_ajax_jelmoo_planner_scan', [self::class, 'ajax_scan']);
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== 'tools_page_' . self::PAGE) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('jelmoo-planner', plugins_url('assets/admin.css', JELMOO_PLANNER_FILE), [], JELMOO_PLANNER_VERSION);
        wp_enqueue_script('jelmoo-planner', plugins_url('assets/admin.js', JELMOO_PLANNER_FILE), [], JELMOO_PLANNER_VERSION, true);
        wp_localize_script('jelmoo-planner', 'jelmooPlanner', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jelmoo_planner_scan'),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Geen toegang.');
        }

        $view = sanitize_key($_GET['view'] ?? 'list');

        echo '<div class="wrap jelmoo-planner">';
        self::render_notice();

        if ($view === 'new') {
            self::render_new();
        } elseif ($view === 'job') {
            self::render_job((int) ($_GET['id'] ?? 0));
        } else {
            self::render_list();
        }

        echo '</div>';
    }

    private static function render_list(): void
    {
        echo '<h1 class="wp-heading-inline">Planner</h1> ';
        echo '<a class="page-title-action" href="' . esc_url(self::url(['view' => 'new'])) . '">Nieuwe taak</a><hr class="wp-header-end">';

        self::render_health();

        $jobs = Store::list_jobs();
        if (!$jobs) {
            echo '<p>Er zijn nog geen taken.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Moment</th><th>Soort</th><th>Status</th><th>Oud / pagina</th><th>Nieuw / element</th><th></th></tr></thead><tbody>';
        foreach ($jobs as $job) {
            echo '<tr>';
            echo '<td>' . esc_html(self::local_time($job['run_at'])) . '</td>';
            echo '<td>' . esc_html(self::type_label($job['type'])) . '</td>';
            echo '<td>' . self::status_badge($job) . '</td>';
            echo '<td class="jp-url">' . esc_html($job['type'] === 'show' ? $job['page_url'] : $job['old_url']) . '</td>';
            echo '<td class="jp-url">' . esc_html($job['type'] === 'show' ? ShowElement::describe($job) : $job['new_url']) . '</td>';
            echo '<td><a href="' . esc_url(self::url(['view' => 'job', 'id' => $job['id']])) . '">Bekijken</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_health(): void
    {
        $last_tick = (int) get_option('jelmoo_planner_last_tick');
        $items = [];

        $items[] = 'Tijdzone van deze site: <strong>' . esc_html(wp_timezone_string()) . '</strong>.';

        if ($last_tick) {
            $items[] = 'Laatste controle: ' . esc_html(wp_date('j M Y H:i', $last_tick)) . '.';
        } else {
            $items[] = 'De planner heeft nog niet gedraaid.';
        }

        $items[] = 'Builder: ' . esc_html(implode(', ', self::detect_builders())) . '.';

        echo '<p class="jp-health">' . implode(' ', $items) . '</p>';

        $overdue = false;
        foreach (Store::due_job_ids() as $id) {
            $job = Store::get_job($id);
            if ($job && strtotime($job['run_at'] . ' UTC') < time() - 10 * MINUTE_IN_SECONDS) {
                $overdue = true;
            }
        }

        if ($overdue) {
            echo '<div class="notice notice-warning inline"><p><strong>Er staat een taak te laat.</strong> WP-cron draait alleen als een bezoek PHP aanroept, en pagina\'s uit de cache doen dat niet. Stel een servercron in die elke 5 minuten <code>wp-cron.php</code> aanroept als een wissel op tijd moet gebeuren.</p></div>';
        }

        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            echo '<div class="notice notice-info inline"><p>WP-cron staat uit op deze site (<code>DISABLE_WP_CRON</code>). De planner draait alleen als er een servercron op <code>wp-cron.php</code> is ingesteld.</p></div>';
        }
    }

    /** @return string[] */
    private static function detect_builders(): array
    {
        $known = [
            'ELEMENTOR_VERSION' => 'Elementor',
            'BRICKS_VERSION' => 'Bricks',
            'FL_BUILDER_VERSION' => 'Beaver Builder',
            'ET_BUILDER_VERSION' => 'Divi',
            'CT_VERSION' => 'Oxygen',
            '__BREAKDANCE_VERSION' => 'Breakdance',
            'WPB_VC_VERSION' => 'WPBakery',
        ];

        $found = [];
        foreach ($known as $constant => $name) {
            if (defined($constant)) {
                $found[] = $name . (in_array($name, self::TESTED_BUILDERS, true) ? '' : ' (ongetest)');
            }
        }

        return $found ?: ['geen paginabuilder herkend'];
    }

    private static function render_new(): void
    {
        $tomorrow = new \DateTimeImmutable('tomorrow', wp_timezone());
        $user = wp_get_current_user();

        echo '<h1>Nieuwe taak</h1>';
        echo '<p><a href="' . esc_url(self::url()) . '">&larr; Terug naar de lijst</a></p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="jp-form">';
        wp_nonce_field('jelmoo_planner_create');
        echo '<input type="hidden" name="action" value="jelmoo_planner_create">';

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">Soort</th><td><fieldset>';
        echo '<label><input type="radio" name="type" value="link" checked> <strong>Link wisselen</strong>: elke knop en menulink naar de oude URL gaat naar de nieuwe URL</label><br>';
        echo '<label><input type="radio" name="type" value="file"> <strong>Bestand vervangen</strong>: de URL blijft gelijk en krijgt nieuwe inhoud, voor een geprinte QR-code</label><br>';
        echo '<label><input type="radio" name="type" value="show"> <strong>Element tonen</strong>: een element dat in de builder op display: none staat wordt zichtbaar</label>';
        echo '</fieldset></td></tr>';

        echo '<tr data-for="link file"><th scope="row"><label for="jp-old" data-link="Oude URL" data-file="URL die gelijk blijft">Oude URL</label></th><td>';
        echo '<input type="url" class="large-text" name="old_url" id="jp-old" required> <button type="button" class="button jp-pick" data-target="jp-old">Mediabibliotheek</button>';
        echo '</td></tr>';

        echo '<tr data-for="link file"><th scope="row"><label for="jp-new" data-link="Nieuwe URL" data-file="Nieuw bestand">Nieuwe URL</label></th><td>';
        echo '<input type="url" class="large-text" name="new_url" id="jp-new" required> <button type="button" class="button jp-pick" data-target="jp-new">Mediabibliotheek</button>';
        echo '</td></tr>';

        echo '<tr data-for="show" hidden><th scope="row"><label for="jp-page">Pagina</label></th><td>';
        echo '<input type="url" class="large-text" name="page_url" id="jp-page" value="' . esc_attr(home_url('/')) . '">';
        echo '<p class="description">Een pagina van deze site waarop het element staat. Hierop controleert de plugin het resultaat.</p>';
        echo '</td></tr>';

        echo '<tr data-for="show" hidden><th scope="row"><label for="jp-selector">Element</label></th><td>';
        echo '<input type="text" class="regular-text" name="selector" id="jp-selector" placeholder="#brxe-abc123">';
        echo '<p class="description">Het ID (#brxe-abc123) of de class (.embleem) van het element zelf, dus niet van de hele sectie.</p>';
        echo '</td></tr>';

        echo '<tr data-for="show" hidden><th scope="row"><label for="jp-hide">Tegelijk verbergen</label></th><td>';
        echo '<input type="text" class="regular-text" name="hide_selector" id="jp-hide" placeholder="#brxe-xyz789">';
        echo '<p class="description">Optioneel. Dit element verdwijnt op hetzelfde moment, bijvoorbeeld het oude embleem dat door het nieuwe wordt vervangen.</p>';
        echo '</td></tr>';

        echo '<tr data-for="show" hidden><th scope="row"><label for="jp-display">Tonen als</label></th><td>';
        echo '<select name="display_value" id="jp-display">';
        foreach (ShowElement::VALUES as $value) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($value) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="jp-run">Moment</label></th><td>';
        echo '<input type="datetime-local" name="run_at" id="jp-run" required value="' . esc_attr($tomorrow->format('Y-m-d\TH:i')) . '">';
        echo '<p class="description">Tijdzone: ' . esc_html(wp_timezone_string()) . '. De wissel gebeurt binnen ongeveer 5 minuten na dit moment. Dat kan later zijn als de site geen bezoek krijgt dat PHP aanroept.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="jp-mail">Resultaat mailen naar</label></th><td>';
        echo '<input type="email" class="regular-text" name="mail_to" id="jp-mail" value="' . esc_attr($user->user_email) . '">';
        echo '</td></tr>';

        echo '</tbody></table>';

        echo '<p><button type="button" class="button button-secondary" id="jp-scan">Scannen</button> <span id="jp-scan-status" aria-live="polite"></span></p>';
        echo '<div id="jp-preview"></div>';

        echo '<p class="description jp-limits" data-for="link file">Niet in de scan: eigen tabellen van plugins (zoals sliders), themabestanden en Code Snippets. Verwijzingen via een bestands-ID en gecodeerde blokken (zoals raw HTML in WPBakery) worden ook niet gevonden; de paginacontrole na de wissel meldt het als zo\'n link zichtbaar blijft. Haal oude WP Crontrol-events voor dezelfde wissel weg, anders kunnen ze hem terugdraaien.</p>';

        echo '<p><button type="submit" class="button button-primary" id="jp-submit" disabled>Inplannen</button></p>';
        echo '</form>';
    }

    private static function render_job(int $id): void
    {
        $job = Store::get_job($id);
        if (!$job) {
            echo '<h1>Taak niet gevonden</h1><p><a href="' . esc_url(self::url()) . '">&larr; Terug naar de lijst</a></p>';
            return;
        }

        echo '<h1>' . esc_html(self::type_label($job['type'])) . ' ' . self::status_badge($job) . '</h1>';
        echo '<p><a href="' . esc_url(self::url()) . '">&larr; Terug naar de lijst</a></p>';

        echo '<table class="form-table" role="presentation"><tbody>';
        self::detail_row('Moment', self::local_time($job['run_at']));
        if ($job['type'] === 'show') {
            self::detail_row('Pagina', (string) $job['page_url']);
            self::detail_row('Element', ShowElement::describe($job));
        } else {
            self::detail_row($job['type'] === 'file' ? 'URL die gelijk blijft' : 'Oude URL', $job['old_url']);
            self::detail_row($job['type'] === 'file' ? 'Nieuw bestand' : 'Nieuwe URL', $job['new_url']);
        }
        if ($job['mail_to'] !== '') {
            self::detail_row('Resultaat naar', $job['mail_to']);
        }
        if (!empty($job['finished_at'])) {
            self::detail_row('Uitgevoerd', self::local_time($job['finished_at']));
        }
        if (!empty($job['next_check_at'])) {
            self::detail_row('Volgende nacontrole', self::local_time($job['next_check_at']));
        }
        echo '</tbody></table>';

        self::render_actions($job);

        foreach ($job['report']['messages'] ?? [] as $message) {
            echo '<div class="notice notice-info inline"><p>' . esc_html($message) . '</p></div>';
        }

        if (!empty($job['report']['pages'])) {
            echo '<h2>Paginacontrole</h2><table class="widefat striped"><tbody>';
            foreach ($job['report']['pages'] as $page) {
                echo '<tr><td>' . esc_html(Runner::page_label($page['result'])) . '</td><td><a href="' . esc_url($page['url']) . '">' . esc_html($page['url']) . '</a></td></tr>';
            }
            echo '</tbody></table>';
        }

        foreach ($job['report']['checks'] ?? [] as $check) {
            $old = count(array_filter($check['pages'], function ($page) {
                return $page['result'] === 'old';
            }));

            if ($job['type'] === 'show') {
                echo '<p>Nacontrole ' . esc_html(self::local_time($check['at'])) . ': ' . esc_html(Runner::page_label($check['pages'][0]['result'] ?? 'error')) . '.</p>';
                continue;
            }

            echo '<p>Nacontrole ' . esc_html(self::local_time($check['at'])) . ': ' . (int) count($check['unexpected']) . ' nieuwe plek(ken) in de database, ' . (int) $old . ' pagina(\'s) met de oude link.</p>';
        }

        $rows = Store::row_summaries($id);
        if ($rows) {
            echo '<h2>Plekken</h2><table class="widefat striped"><thead><tr><th>Plek</th><th>Veld</th><th>Status</th><th>Treffers</th><th>Toelichting</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $where = Scanner::describe($row['target'], $row['col'], (int) $row['owner_id'], $row['field']);
                $label = $where['edit_url'] !== '' ? '<a href="' . esc_url($where['edit_url']) . '">' . esc_html($where['label']) . '</a>' : esc_html($where['label']);
                echo '<tr><td>' . $label . '</td><td><code>' . esc_html($where['detail']) . '</code></td><td>' . esc_html(Runner::state_label($row['state'])) . '</td><td>' . (int) $row['matches'] . '</td><td>' . esc_html($row['reason']) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    private static function render_actions(array $job): void
    {
        echo '<div class="jp-actions">';

        if ($job['status'] === 'scheduled') {
            self::action_button('jelmoo_planner_run', $job['id'], 'Nu uitvoeren', 'button-primary', 'De wissel gaat nu meteen live. Doorgaan?');
            self::action_button('jelmoo_planner_cancel', $job['id'], 'Annuleren', 'button-secondary', 'Deze taak annuleren?');
        }

        if (Runner::can_undo($job)) {
            $confirm = $job['type'] === 'show' ? 'Terugdraaien? Staat het element in de builder nog op display: none, dan verdwijnt het weer.' : 'De wissel terugdraaien?';
            self::action_button('jelmoo_planner_undo', $job['id'], 'Ongedaan maken', 'button-secondary', $confirm);
        }

        echo '</div>';
    }

    private static function action_button(string $action, int $id, string $label, string $class, string $confirm): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="jp-inline" data-confirm="' . esc_attr($confirm) . '">';
        wp_nonce_field($action . '_' . $id);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="id" value="' . (int) $id . '">';
        echo '<button type="submit" class="button ' . esc_attr($class) . '">' . esc_html($label) . '</button>';
        echo '</form> ';
    }

    private static function detail_row(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td class="jp-url">' . esc_html($value) . '</td></tr>';
    }

    public static function handle_create(): void
    {
        self::guard('jelmoo_planner_create');

        $type = self::posted_type();
        $old_url = self::clean_url($_POST['old_url'] ?? '');
        $new_url = self::clean_url($_POST['new_url'] ?? '');
        $show = self::posted_show();
        $mail_to = sanitize_email(wp_unslash($_POST['mail_to'] ?? ''));
        $run_at = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', sanitize_text_field(wp_unslash($_POST['run_at'] ?? '')), wp_timezone());

        $error = '';
        if ($type === 'show') {
            $error = self::show_error($show);
        } elseif ($old_url === '' || $new_url === '') {
            $error = 'Vul een volledige oude en nieuwe URL in (met https://).';
        } elseif (self::same_url($old_url, $new_url)) {
            $error = 'De oude en de nieuwe URL zijn hetzelfde.';
        } elseif ($type === 'file') {
            try {
                FileSwap::validate($old_url, $new_url);
            } catch (\RuntimeException $problem) {
                $error = $problem->getMessage();
            }
        }

        if ($error === '' && !$run_at) {
            $error = 'Het moment is niet geldig.';
        } elseif ($error === '' && $run_at->getTimestamp() <= time()) {
            $error = 'Het moment ligt in het verleden. Kies een moment in de toekomst.';
        }

        if ($error !== '') {
            self::redirect(['view' => 'new'], $error, 'error');
        }

        $id = Store::create_job([
            'type' => $type,
            'run_at' => gmdate('Y-m-d H:i:s', $run_at->getTimestamp()),
            'old_url' => $type === 'show' ? '' : $old_url,
            'new_url' => $type === 'show' ? '' : $new_url,
            'selector' => $type === 'show' ? $show['selector'] : '',
            'display_value' => $type === 'show' ? $show['display_value'] : '',
            'hide_selector' => $type === 'show' ? $show['hide_selector'] : '',
            'page_url' => $type === 'show' ? $show['page_url'] : null,
            'mail_to' => $mail_to,
        ]);

        self::redirect(['view' => 'job', 'id' => $id], 'De taak is ingepland.', 'success');
    }

    public static function handle_run(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        self::guard('jelmoo_planner_run_' . $id);

        $job = Store::get_job($id);
        if ($job && $job['status'] === 'scheduled') {
            Store::update_job($id, ['run_at' => Store::now()]);
            Runner::tick();
        }

        self::redirect(['view' => 'job', 'id' => $id], 'De taak is gestart.', 'success');
    }

    public static function handle_cancel(): void
    {
        global $wpdb;

        $id = (int) ($_POST['id'] ?? 0);
        self::guard('jelmoo_planner_cancel_' . $id);

        // Alleen zolang de tick hem nog niet heeft geclaimd.
        $done = $wpdb->query($wpdb->prepare('UPDATE ' . Store::jobs_table() . " SET status = 'cancelled' WHERE id = %d AND status = 'scheduled'", $id));

        self::redirect(['view' => 'job', 'id' => $id], $done === 1 ? 'De taak is geannuleerd.' : 'De taak was al gestart en is niet geannuleerd.', $done === 1 ? 'success' : 'warning');
    }

    public static function handle_undo(): void
    {
        $id = (int) ($_POST['id'] ?? 0);
        self::guard('jelmoo_planner_undo_' . $id);

        try {
            $message = Runner::undo($id);
            $type = 'success';
        } catch (\RuntimeException $problem) {
            $message = $problem->getMessage();
            $type = 'error';
        }

        self::redirect(['view' => 'job', 'id' => $id], $message, $type);
    }

    /** Eén stuk van de preview. De browser roept dit aan tot de cursor klaar is. */
    public static function ajax_scan(): void
    {
        check_ajax_referer('jelmoo_planner_scan');

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => 'Geen toegang.'], 403);
        }

        $type = self::posted_type();

        if ($type === 'show') {
            self::scan_show();
        }

        $old_url = self::clean_url($_POST['old_url'] ?? '');
        $new_url = self::clean_url($_POST['new_url'] ?? '');

        if ($old_url === '' || $new_url === '') {
            wp_send_json_error(['message' => 'Vul een volledige oude en nieuwe URL in (met https://).']);
        }

        if ($type === 'file') {
            self::scan_file($old_url, $new_url);
        }

        $cursor = json_decode(wp_unslash($_POST['cursor'] ?? '[]'), true) ?: [];
        $warnings = [];

        // Alleen bij het eerste stuk: de beslissing valt pas bij het uitvoeren.
        if (!$cursor) {
            $check = Runner::check_new_url(['new_url' => $new_url]);
            if ($check['message'] !== '') {
                $warnings[] = $check['message'];
            }
        }

        $variants = new Variants($old_url, $new_url, home_url('/'));
        $rewriter = new Rewriter($variants);
        $rows = [];

        $cursor = Scanner::chunk($variants, $cursor, microtime(true) + 8, function ($location, $raw) use ($rewriter, &$rows) {
            $result = $rewriter->rewrite($raw);
            if ($result['status'] === Rewriter::UNCHANGED) {
                return;
            }

            $where = Scanner::describe($location['target'], $location['col'], $location['owner'], $location['field']);
            $rows[] = [
                'label' => $where['label'],
                'detail' => $where['detail'],
                'editUrl' => $where['edit_url'],
                'status' => $result['status'],
                'count' => $result['count'],
                'reason' => $result['reason'],
            ];
        });

        wp_send_json_success(['rows' => $rows, 'cursor' => $cursor, 'done' => !empty($cursor['done']), 'warnings' => $warnings]);
    }

    private static function posted_type(): string
    {
        $type = $_POST['type'] ?? '';

        return in_array($type, ['file', 'show'], true) ? $type : 'link';
    }

    /** @return array{page_url:string,selector:string,display_value:string,hide_selector:string,hide_invalid:bool} */
    private static function posted_show(): array
    {
        $hide = trim(wp_unslash((string) ($_POST['hide_selector'] ?? '')));
        $hide_selector = ShowElement::normalize_selector($hide);

        return [
            'hide_selector' => $hide_selector,
            'hide_invalid' => $hide !== '' && $hide_selector === '',
            'page_url' => self::clean_url($_POST['page_url'] ?? ''),
            'selector' => ShowElement::normalize_selector(wp_unslash((string) ($_POST['selector'] ?? ''))),
            'display_value' => sanitize_key(wp_unslash((string) ($_POST['display_value'] ?? ''))),
        ];
    }

    private static function show_error(array $show): string
    {
        if ($show['page_url'] === '' || !Runner::same_site($show['page_url'])) {
            return 'Vul een volledige URL in van een pagina op deze site.';
        }

        if ($show['selector'] === '') {
            return 'Vul één ID (#brxe-abc123) of class (.embleem) in, zonder spaties of andere tekens.';
        }

        if ($show['hide_invalid']) {
            return 'Vul bij Tegelijk verbergen één ID (#brxe-abc123) of class (.embleem) in, of laat het veld leeg.';
        }

        if ($show['hide_selector'] === $show['selector']) {
            return 'Het element kan niet tegelijk getoond en verborgen worden.';
        }

        if (!ShowElement::is_valid_value($show['display_value'])) {
            return 'Kies hoe het element getoond moet worden.';
        }

        return '';
    }

    /** Kijkt alleen: staat het element op de pagina en is het nu verborgen? */
    private static function scan_show(): void
    {
        $show = self::posted_show();
        $error = self::show_error($show);
        if ($error !== '') {
            wp_send_json_error(['message' => $error]);
        }

        $response = wp_remote_get($show['page_url'], ['timeout' => 15, 'redirection' => 3, 'sslverify' => apply_filters('https_local_ssl_verify', false)]);
        $warnings = [];
        $text = 'De pagina kon niet worden opgehaald, dus het element is niet gecontroleerd. Inplannen kan wel.';

        if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 400) {
            $html = wp_remote_retrieve_body($response);
            $seen = ShowElement::inspect($html, $show['selector']);
            $text = $show['selector'] . ' staat op de pagina en wordt op het gekozen moment zichtbaar.';

            if ($show['hide_selector'] !== '') {
                if (ShowElement::inspect($html, $show['hide_selector'])['found']) {
                    $text .= ' ' . $show['hide_selector'] . ' verdwijnt op hetzelfde moment.';
                } else {
                    $warnings[] = $show['hide_selector'] . ' is niet gevonden op deze pagina, dus dat element kan niet verborgen worden. Controleer de selector.';
                }
            }

            if (!$seen['found']) {
                $text = '';
                $warnings[] = $show['selector'] . ' is niet gevonden op deze pagina. Controleer de selector en de pagina. Heb je het element net toegevoegd, leeg dan eerst de cache en scan opnieuw.';
            } elseif (!$seen['hidden']) {
                $warnings[] = 'Er is geen display: none gevonden voor ' . $show['selector'] . ' in de HTML van deze pagina. Staat de CSS in een los bestand, dan is dat normaal. Controleer anders of het element in de builder verborgen is, want dan is het nu al zichtbaar.';
            }
        }

        wp_send_json_success(['rows' => [], 'cursor' => ['done' => true], 'done' => true, 'warnings' => $warnings, 'file' => $text]);
    }

    private static function scan_file(string $old_url, string $new_url): void
    {
        try {
            $files = FileSwap::validate($old_url, $new_url);
        } catch (\RuntimeException $problem) {
            wp_send_json_error(['message' => $problem->getMessage()]);
        }

        $warnings = [];
        $variants = FileSwap::stale_variants($files['target']);
        if ($variants) {
            $warnings[] = FileSwap::stale_variants_warning($variants);
        }

        $cache_warning = FileSwap::browser_cache_warning($old_url);
        if ($cache_warning !== '') {
            $warnings[] = $cache_warning;
        }

        wp_send_json_success([
            'rows' => [],
            'cursor' => ['done' => true],
            'done' => true,
            'warnings' => $warnings,
            'file' => sprintf('%s (%s) wordt vervangen door %s (%s). Er wordt eerst een backup gemaakt.', basename($files['target']), size_format(filesize($files['target'])), basename($files['source']), size_format(filesize($files['source']))),
        ]);
    }

    private static function guard(string $nonce_action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Geen toegang.');
        }

        check_admin_referer($nonce_action);
    }

    private static function clean_url($value): string
    {
        $url = esc_url_raw(trim(wp_unslash((string) $value)), ['http', 'https']);
        $parts = parse_url($url);

        return !empty($parts['host']) ? $url : '';
    }

    private static function same_url(string $a, string $b): bool
    {
        $strip = function ($url) {
            return rtrim(preg_replace('#^https?://(www\.)?#i', '', $url), '/');
        };

        return $strip($a) === $strip($b);
    }

    private static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::PAGE], $args), admin_url('tools.php'));
    }

    /** Sluit af met een redirect; de melding overleeft die via een korte transient. */
    private static function redirect(array $args, string $message, string $type): void
    {
        set_transient('jelmoo_planner_notice_' . get_current_user_id(), ['message' => $message, 'type' => $type], MINUTE_IN_SECONDS);
        wp_safe_redirect(self::url($args));
        exit;
    }

    private static function render_notice(): void
    {
        $key = 'jelmoo_planner_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!$notice) {
            return;
        }

        delete_transient($key);
        echo '<div class="notice notice-' . esc_attr($notice['type']) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    private static function local_time(string $utc): string
    {
        return wp_date('j M Y H:i', strtotime($utc . ' UTC'));
    }

    private static function type_label(string $type): string
    {
        $labels = ['file' => 'Bestand vervangen', 'show' => 'Element tonen'];

        return $labels[$type] ?? 'Link wisselen';
    }

    private static function status_badge(array $job): string
    {
        $labels = [
            'scheduled' => 'Ingepland',
            'preparing' => 'Bezig',
            'applying' => 'Bezig',
            'finishing' => 'Bezig',
            'done' => 'Geslaagd',
            'warning' => 'Gedaan, controleer dit',
            'failed' => 'Mislukt',
            'cancelled' => 'Geannuleerd',
            'undone' => 'Ongedaan gemaakt',
        ];

        $status = $job['status'];

        return '<span class="jp-status jp-status-' . esc_attr($status) . '">' . esc_html($labels[$status] ?? $status) . '</span>';
    }
}
