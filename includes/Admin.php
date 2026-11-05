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
        add_filter('admin_body_class', [self::class, 'body_class']);
        add_action('admin_post_jelmoo_planner_create', [self::class, 'handle_create']);
        add_action('admin_post_jelmoo_planner_run', [self::class, 'handle_run']);
        add_action('admin_post_jelmoo_planner_cancel', [self::class, 'handle_cancel']);
        add_action('admin_post_jelmoo_planner_undo', [self::class, 'handle_undo']);
        add_action('wp_ajax_jelmoo_planner_scan', [self::class, 'ajax_scan']);
    }

    public static function body_class(string $classes): string
    {
        return ($_GET['page'] ?? '') === self::PAGE ? $classes . ' jp-admin' : $classes;
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
            'zone' => Time::zone_name(),
            'zoneLabel' => Time::label(),
            'now' => time(),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Geen toegang.');
        }

        $view = sanitize_key($_GET['view'] ?? 'list');

        // Meldingen van andere plugins plaatst WordPress achter deze lijn, dus boven de Planner.
        echo '<div class="wrap jp"><hr class="wp-header-end jp-hidden">';
        self::render_top();
        self::render_notice();

        if ($view === 'new') {
            self::render_new();
        } elseif ($view === 'job') {
            self::render_job((int) ($_GET['id'] ?? 0));
        } else {
            self::render_list();
        }

        echo '<dialog class="jp-dialog" id="jp-dialog"><p id="jp-dialog-text"></p><div class="jp-dialog-actions"><button type="button" class="jp-btn jp-btn-ghost" id="jp-dialog-no">Terug</button><button type="button" class="jp-btn jp-btn-ink" id="jp-dialog-yes">Doorgaan</button></div></dialog>';
        echo '</div>';
    }

    private static function render_top(): void
    {
        $now = time();

        echo '<header class="jp-top">';
        echo '<a class="jp-brand" href="' . esc_url(self::url()) . '"><span class="jp-mark">' . self::icon('clock') . '</span><span class="jp-brand-name">Planner</span></a>';
        echo '<div class="jp-clock" title="' . esc_attr('Alle tijden in de Planner zijn ' . Time::label() . ' (' . Time::offset($now) . ')') . '"><span class="jp-clock-now" data-clock>' . esc_html(Time::clock($now)) . '</span><span>' . esc_html(Time::label()) . '</span></div>';
        echo '<a class="jp-btn jp-btn-ink" href="' . esc_url(self::url(['view' => 'new'])) . '">' . self::icon('plus') . 'Nieuwe taak</a>';
        echo '</header>';
    }

    private static function render_list(): void
    {
        echo '<h1 class="jp-hidden">Planner</h1>';

        $jobs = Store::list_jobs();
        $upcoming = array_values(array_filter($jobs, function ($job) {
            return in_array($job['status'], ['scheduled', 'preparing', 'applying', 'finishing'], true);
        }));
        $past = array_values(array_filter($jobs, function ($job) {
            return !in_array($job['status'], ['scheduled', 'preparing', 'applying', 'finishing'], true);
        }));

        // De lijst staat nieuwste eerst; wat ingepland staat lees je van vroeg naar laat.
        $upcoming = array_reverse($upcoming);

        self::render_hero($upcoming[0] ?? null);
        self::render_health();

        if ($upcoming) {
            self::render_rows('Ingepland', $upcoming);
        }

        if ($past) {
            self::render_rows('Afgerond', $past);
        }
    }

    private static function render_hero(?array $next): void
    {
        echo '<section class="jp-hero">';

        if (!$next) {
            echo '<div class="jp-moment"><span class="jp-eyebrow">Volgende wissel</span><div class="jp-hero-empty">Er staat niets ingepland.</div></div>';
            echo '<div class="jp-hero-what"><p>Plan een link, bestand of element in. Je ziet vooraf wat er verandert en krijgt achteraf bewijs dat het live staat.</p><a class="jp-btn jp-btn-lime" href="' . esc_url(self::url(['view' => 'new'])) . '">' . self::icon('plus') . 'Nieuwe taak</a></div>';
            echo '</section>';
            return;
        }

        self::moment('Volgende wissel', Time::from_utc($next['run_at']), true);

        echo '<div class="jp-hero-what">';
        echo '<span class="jp-eyebrow">' . esc_html(self::type_label($next['type'])) . '</span>';
        echo '<p class="jp-hero-title">' . esc_html(self::job_title($next)) . '</p>';
        echo '<a class="jp-btn jp-btn-outline" href="' . esc_url(self::url(['view' => 'job', 'id' => $next['id']])) . '">Bekijken ' . self::icon('chevron') . '</a>';
        echo '</div></section>';
    }

    /** Het tijdstip als het belangrijkste op het scherm, altijd met de tijdzone erbij. */
    private static function moment(string $label, int $timestamp, bool $is_scheduled): void
    {
        echo '<div class="jp-moment">';
        echo '<span class="jp-eyebrow">' . esc_html($label) . '</span>';
        echo '<div class="jp-moment-time">' . esc_html(Time::clock($timestamp)) . '</div>';
        echo '<div class="jp-moment-date">' . esc_html(Time::date($timestamp)) . '</div>';
        echo '<div class="jp-moment-zone"><span class="jp-pulse"></span>' . esc_html(Time::label() . ' · ' . Time::offset($timestamp)) . '</div>';
        echo '<div class="jp-moment-count" data-countdown="' . (int) $timestamp . '"' . ($is_scheduled ? ' data-late' : '') . '>' . esc_html(self::relative($timestamp, $is_scheduled)) . '</div>';
        echo '</div>';
    }

    private static function relative(int $timestamp, bool $is_scheduled): string
    {
        $seconds = $timestamp - time();

        if ($seconds > 0) {
            return 'over ' . Time::span($seconds);
        }

        return Time::span($seconds) . ($is_scheduled ? ' te laat' : ' geleden');
    }

    private static function render_rows(string $title, array $jobs): void
    {
        echo '<section class="jp-section"><h2 class="jp-section-title">' . esc_html($title) . ' <span>' . count($jobs) . '</span></h2><div class="jp-rows">';

        foreach ($jobs as $job) {
            $moment = Time::from_utc($job['run_at']);

            echo '<a class="jp-row" href="' . esc_url(self::url(['view' => 'job', 'id' => $job['id']])) . '">';
            echo '<span class="jp-row-time"><b>' . esc_html(Time::clock($moment)) . '</b><small>' . esc_html(Time::short_date($moment)) . '</small></span>';
            echo '<span class="jp-row-icon">' . self::icon(self::type_icon($job['type'])) . '</span>';
            echo '<span class="jp-row-main"><strong>' . esc_html(self::job_title($job)) . '</strong><small>' . esc_html(self::type_label($job['type'])) . '</small></span>';
            echo self::status_badge($job);
            echo '<span class="jp-row-go">' . self::icon('chevron') . '</span>';
            echo '</a>';
        }

        echo '</div></section>';
    }

    private static function render_health(): void
    {
        $last_tick = (int) get_option('jelmoo_planner_last_tick');

        $overdue = false;
        foreach (Store::due_job_ids() as $id) {
            $job = Store::get_job($id);
            if ($job && Time::from_utc($job['run_at']) < time() - 10 * MINUTE_IN_SECONDS) {
                $overdue = true;
            }
        }

        if ($overdue) {
            self::callout('warn', '<strong>Er staat een taak te laat.</strong> WP-Cron draait alleen als een bezoek PHP aanroept, en pagina\'s uit de cache doen dat niet. Stel een servercron in die elke minuut <code>wp-cron.php</code> aanroept als een wissel op tijd moet gebeuren.');
        }

        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            self::callout('info', 'WP-Cron staat uit op deze site (<code>DISABLE_WP_CRON</code>). De Planner draait alleen als er een servercron op <code>wp-cron.php</code> is ingesteld.');
        }

        echo '<dl class="jp-facts">';
        echo '<div><dt>Tijden in de Planner</dt><dd>' . esc_html(Time::label() . ' (' . Time::offset(time()) . ')') . '</dd></div>';
        echo '<div><dt>Laatste controle</dt><dd>' . ($last_tick ? '<span data-countdown="' . $last_tick . '">' . esc_html(self::relative($last_tick, false)) . '</span>' : 'Nog niet gedraaid') . '</dd></div>';
        echo '<div><dt>Builder</dt><dd>' . esc_html(implode(', ', self::detect_builders())) . '</dd></div>';
        echo '</dl>';

        if (wp_timezone()->getName() !== Time::zone_name()) {
            echo '<p class="jp-footnote">WordPress staat op deze site op ' . esc_html(wp_timezone_string()) . '. Dat maakt voor de Planner niet uit: die rekent altijd met ' . esc_html(Time::label()) . '.</p>';
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
        $tomorrow = (new \DateTimeImmutable('tomorrow 09:00', Time::zone()))->getTimestamp();
        $user = wp_get_current_user();

        echo '<a class="jp-back" href="' . esc_url(self::url()) . '">' . self::icon('back') . 'Alle taken</a>';
        echo '<h1 class="jp-title">Nieuwe taak</h1>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="jp-form" class="jp-layout">';
        wp_nonce_field('jelmoo_planner_create');
        echo '<input type="hidden" name="action" value="jelmoo_planner_create">';

        echo '<div class="jp-main">';

        self::step_open(1, 'Wat moet er gebeuren?');
        echo '<div class="jp-types">';
        self::type_card('link', 'link', 'Link wisselen', 'Elke knop, menulink en verwijzing gaat naar een nieuw bestand of nieuwe URL. Ook voor afbeeldingen.', true);
        self::type_card('file', 'file', 'Bestand vervangen', 'De URL blijft gelijk en krijgt nieuwe inhoud, bijvoorbeeld voor een geprinte QR-code.', false);
        self::type_card('show', 'eye', 'Element tonen', 'Een element dat in de builder op display: none staat wordt zichtbaar.', false);
        echo '</div>';
        self::step_close();

        self::step_open(2, 'Waar gaat het om?');
        echo '<div class="jp-field" data-for="link file"><label for="jp-old" data-link="Oude URL" data-file="URL die gelijk blijft">Oude URL</label>';
        echo '<div class="jp-input-row"><input type="url" name="old_url" id="jp-old" placeholder="https://" required><button type="button" class="jp-btn jp-btn-ghost jp-pick" data-target="jp-old">Mediabibliotheek</button></div></div>';

        echo '<div class="jp-field" data-for="link file"><label for="jp-new" data-link="Nieuwe URL" data-file="Nieuw bestand">Nieuwe URL</label>';
        echo '<div class="jp-input-row"><input type="url" name="new_url" id="jp-new" placeholder="https://" required><button type="button" class="jp-btn jp-btn-ghost jp-pick" data-target="jp-new">Mediabibliotheek</button></div>';
        echo '<p class="jp-help" data-for="link">Kies een bestand uit de mediabibliotheek, dan gaan ook kleinere versies en verwijzingen via het bestands-ID mee.</p></div>';

        echo '<div class="jp-field" data-for="show" hidden><label for="jp-page">Pagina</label><input type="url" name="page_url" id="jp-page" value="' . esc_attr(home_url('/')) . '"><p class="jp-help">Een pagina van deze site waarop het element staat. Hierop controleert de plugin het resultaat.</p></div>';

        echo '<div class="jp-field-pair" data-for="show" hidden>';
        echo '<div class="jp-field"><label for="jp-selector">Element tonen</label><input type="text" name="selector" id="jp-selector" class="jp-mono" placeholder="#brxe-abc123"><p class="jp-help">Het ID of de class van het element zelf, niet van de hele sectie.</p></div>';
        echo '<div class="jp-field"><label for="jp-hide">Tegelijk verbergen <em>optioneel</em></label><input type="text" name="hide_selector" id="jp-hide" class="jp-mono" placeholder="#brxe-xyz789"><p class="jp-help">Verdwijnt op hetzelfde moment, bijvoorbeeld het oude embleem.</p></div>';
        echo '</div>';

        echo '<div class="jp-field jp-field-narrow" data-for="show" hidden><label for="jp-display">Tonen als</label><select name="display_value" id="jp-display">';
        foreach (ShowElement::VALUES as $value) {
            echo '<option value="' . esc_attr($value) . '">' . esc_html($value) . '</option>';
        }
        echo '</select></div>';
        self::step_close();

        self::step_open(3, 'Wanneer?');
        echo '<div class="jp-field-pair">';
        echo '<div class="jp-field"><label for="jp-run">Datum en tijd</label><div class="jp-with-zone"><input type="datetime-local" name="run_at" id="jp-run" required value="' . esc_attr(Time::input_value($tomorrow)) . '"><span>' . esc_html(Time::label()) . '</span></div></div>';
        echo '<div class="jp-field"><label for="jp-mail">Resultaat mailen naar</label><input type="email" name="mail_to" id="jp-mail" value="' . esc_attr($user->user_email) . '"></div>';
        echo '</div>';
        self::step_close();

        self::step_open(4, 'Controleren');
        echo '<p class="jp-help jp-help-lead">De scan laat zien wat er verandert. Er wordt nog niets gewijzigd.</p>';
        echo '<div class="jp-scan-bar"><button type="button" class="jp-btn jp-btn-ink" id="jp-scan">' . self::icon('search') . 'Scannen</button><span id="jp-scan-status" aria-live="polite"></span></div>';
        echo '<div id="jp-preview"></div>';
        echo '<details class="jp-limits" data-for="link file"><summary>Wat de scan niet ziet</summary><p>Eigen tabellen van plugins (zoals sliders), themabestanden, Code Snippets en gecodeerde blokken (zoals raw HTML in WPBakery). Een afbeelding die alleen met een bestands-ID is opgeslagen, zonder URL of bestandsnaam ernaast, wordt ook niet gewijzigd. De paginacontrole na de wissel meldt het als er nog iets ouds zichtbaar is. Haal oude WP Crontrol-events voor dezelfde wissel weg, anders kunnen ze hem terugdraaien.</p></details>';
        self::step_close();

        echo '</div>';

        echo '<aside class="jp-side"><div class="jp-night jp-sticky">';
        echo '<span class="jp-eyebrow">Gaat live op</span>';
        echo '<div class="jp-moment-time" id="jp-live-time">' . esc_html(Time::clock($tomorrow)) . '</div>';
        echo '<div class="jp-moment-date" id="jp-live-date">' . esc_html(Time::date($tomorrow)) . '</div>';
        echo '<div class="jp-moment-zone"><span class="jp-pulse"></span>' . esc_html(Time::label()) . '</div>';
        echo '<div class="jp-moment-count" id="jp-live-count">' . esc_html(self::relative($tomorrow, false)) . '</div>';
        echo '<button type="submit" class="jp-btn jp-btn-lime jp-btn-wide" id="jp-submit" disabled>Inplannen</button>';
        echo '<p class="jp-side-hint" id="jp-submit-hint">Scan eerst wat er verandert.</p>';
        echo '</div></aside>';

        echo '</form>';
    }

    private static function step_open(int $number, string $title): void
    {
        echo '<section class="jp-card jp-step"><h2 class="jp-step-title"><span>' . (int) $number . '</span>' . esc_html($title) . '</h2>';
    }

    private static function step_close(): void
    {
        echo '</section>';
    }

    private static function type_card(string $value, string $icon, string $title, string $text, bool $checked): void
    {
        echo '<label class="jp-type"><input type="radio" name="type" value="' . esc_attr($value) . '"' . ($checked ? ' checked' : '') . '><span class="jp-type-box"><span class="jp-type-icon">' . self::icon($icon) . '</span><strong>' . esc_html($title) . '</strong><small>' . esc_html($text) . '</small></span></label>';
    }

    private static function render_job(int $id): void
    {
        $job = Store::get_job($id);
        if (!$job) {
            echo '<a class="jp-back" href="' . esc_url(self::url()) . '">' . self::icon('back') . 'Alle taken</a><h1 class="jp-title">Taak niet gevonden</h1>';
            return;
        }

        $planned = Time::from_utc($job['run_at']);
        $finished = !empty($job['finished_at']) ? Time::from_utc($job['finished_at']) : 0;

        echo '<a class="jp-back" href="' . esc_url(self::url()) . '">' . self::icon('back') . 'Alle taken</a>';
        echo '<div class="jp-title-row"><h1 class="jp-title">' . esc_html(self::job_title($job)) . '</h1>' . self::status_badge($job) . '</div>';
        echo '<p class="jp-subtitle">' . esc_html(self::type_label($job['type'])) . '</p>';

        echo '<div class="jp-layout"><div class="jp-main">';

        if ($finished && $finished - $planned > 10 * MINUTE_IN_SECONDS) {
            self::callout('warn', '<strong>Deze taak liep ' . esc_html(Time::span($finished - $planned)) . ' achter.</strong> Gepland was ' . esc_html(Time::full($planned)) . '. WP-Cron draait alleen als een bezoek PHP aanroept. Stel een servercron in die elke minuut <code>wp-cron.php</code> aanroept als een wissel op tijd moet gebeuren.');
        }

        $tones = ['failed' => 'bad', 'warning' => 'warn', 'done' => 'good'];
        foreach ($job['report']['messages'] ?? [] as $message) {
            self::callout($tones[$job['status']] ?? 'info', esc_html($message));
        }

        echo '<section class="jp-card"><h2 class="jp-card-title">Details</h2><dl class="jp-details">';
        if ($job['type'] === 'show') {
            self::detail_row('Pagina', (string) $job['page_url']);
            self::detail_row('Element', ShowElement::describe($job));
        } else {
            self::detail_row($job['type'] === 'file' ? 'URL die gelijk blijft' : 'Oude URL', $job['old_url']);
            self::detail_row($job['type'] === 'file' ? 'Nieuw bestand' : 'Nieuwe URL', $job['new_url']);
        }
        self::detail_row('Gepland', Time::full($planned));
        if ($finished) {
            self::detail_row('Uitgevoerd', Time::full($finished));
        }
        if (!empty($job['next_check_at'])) {
            self::detail_row('Volgende nacontrole', Time::full(Time::from_utc($job['next_check_at'])));
        }
        if ($job['mail_to'] !== '') {
            self::detail_row('Resultaat naar', $job['mail_to']);
        }
        echo '</dl></section>';

        if (!empty($job['report']['pages'])) {
            echo '<section class="jp-card"><h2 class="jp-card-title">Paginacontrole</h2><ul class="jp-checks">';
            foreach ($job['report']['pages'] as $page) {
                $good = in_array($page['result'], ['ok', 'shown'], true);
                echo '<li class="' . ($good ? 'is-good' : 'is-bad') . '">' . self::icon($good ? 'check' : 'alert') . '<span>' . esc_html(Runner::page_label($page['result'])) . '</span><a href="' . esc_url($page['url']) . '" target="_blank" rel="noopener">' . esc_html($page['url']) . '</a></li>';
            }
            echo '</ul>';

            foreach ($job['report']['checks'] ?? [] as $check) {
                $old = count(array_filter($check['pages'], function ($page) {
                    return $page['result'] === 'old';
                }));
                $when = Time::full(Time::from_utc($check['at']));

                if ($job['type'] === 'show') {
                    echo '<p class="jp-check-note">Nacontrole ' . esc_html($when) . ': ' . esc_html(Runner::page_label($check['pages'][0]['result'] ?? 'error')) . '.</p>';
                    continue;
                }

                echo '<p class="jp-check-note">Nacontrole ' . esc_html($when) . ': ' . (int) count($check['unexpected']) . ' nieuwe plek(ken) in de database, ' . (int) $old . ' pagina(\'s) met de oude link.</p>';
            }

            echo '</section>';
        }

        $rows = Store::row_summaries($id);
        if ($rows) {
            echo '<section class="jp-card jp-card-table"><h2 class="jp-card-title">Plekken</h2><div class="jp-table-wrap"><table class="jp-table"><thead><tr><th>Plek</th><th>Veld</th><th>Status</th><th>Treffers</th><th>Toelichting</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $where = Scanner::describe($row['target'], $row['col'], (int) $row['owner_id'], $row['field']);
                $label = $where['edit_url'] !== '' ? '<a href="' . esc_url($where['edit_url']) . '">' . esc_html($where['label']) . '</a>' : esc_html($where['label']);
                echo '<tr><td>' . $label . '</td><td><code>' . esc_html($where['detail']) . '</code></td><td>' . esc_html(Runner::state_label($row['state'])) . '</td><td>' . (int) $row['matches'] . '</td><td>' . esc_html($row['reason']) . '</td></tr>';
            }
            echo '</tbody></table></div></section>';
        }

        echo '</div><aside class="jp-side"><div class="jp-night jp-sticky">';

        if ($finished) {
            self::moment('Uitgevoerd op', $finished, false);
        } elseif ($job['status'] === 'cancelled') {
            self::moment('Was gepland op', $planned, false);
        } else {
            self::moment('Gaat live op', $planned, true);
        }

        self::render_actions($job);
        echo '</div></aside></div>';
    }

    private static function render_actions(array $job): void
    {
        echo '<div class="jp-actions">';

        if ($job['status'] === 'scheduled') {
            self::action_button('jelmoo_planner_run', $job['id'], 'Nu uitvoeren', 'jp-btn-lime', 'De wissel gaat nu meteen live. Doorgaan?');
            self::action_button('jelmoo_planner_cancel', $job['id'], 'Annuleren', 'jp-btn-outline', 'Deze taak annuleren?');
        }

        if (Runner::can_undo($job)) {
            $confirm = $job['type'] === 'show' ? 'Terugdraaien? Staat het element in de builder nog op display: none, dan verdwijnt het weer.' : 'De wissel terugdraaien?';
            self::action_button('jelmoo_planner_undo', $job['id'], 'Ongedaan maken', 'jp-btn-outline', $confirm);
        }

        echo '</div>';
    }

    private static function action_button(string $action, int $id, string $label, string $class, string $confirm): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-confirm="' . esc_attr($confirm) . '">';
        wp_nonce_field($action . '_' . $id);
        echo '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="id" value="' . (int) $id . '">';
        echo '<button type="submit" class="jp-btn jp-btn-wide ' . esc_attr($class) . '">' . esc_html($label) . '</button>';
        echo '</form>';
    }

    private static function detail_row(string $label, string $value): void
    {
        echo '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
    }

    /** $html is al ge-escapet door de aanroeper. */
    private static function callout(string $tone, string $html): void
    {
        $icons = ['good' => 'check', 'warn' => 'alert', 'bad' => 'alert', 'info' => 'info'];

        echo '<div class="jp-callout jp-callout-' . esc_attr($tone) . '">' . self::icon($icons[$tone] ?? 'info') . '<p>' . $html . '</p></div>';
    }

    public static function handle_create(): void
    {
        self::guard('jelmoo_planner_create');

        $type = self::posted_type();
        $old_url = self::clean_url($_POST['old_url'] ?? '');
        $new_url = self::clean_url($_POST['new_url'] ?? '');
        $show = self::posted_show();
        $mail_to = sanitize_email(wp_unslash($_POST['mail_to'] ?? ''));
        $run_at = Time::parse(sanitize_text_field(wp_unslash($_POST['run_at'] ?? '')));

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

        if ($error === '' && $run_at === null) {
            $error = 'Dit moment bestaat niet. Kies een andere datum of tijd; bij de overgang naar zomertijd wordt 02:00 tot 03:00 overgeslagen.';
        } elseif ($error === '' && $run_at <= time()) {
            $error = 'Het moment ligt in het verleden. Kies een moment in de toekomst.';
        }

        if ($error !== '') {
            self::redirect(['view' => 'new'], $error, 'error');
        }

        $id = Store::create_job([
            'type' => $type,
            'run_at' => gmdate('Y-m-d H:i:s', $run_at),
            'old_url' => $type === 'show' ? '' : $old_url,
            'new_url' => $type === 'show' ? '' : $new_url,
            'selector' => $type === 'show' ? $show['selector'] : '',
            'display_value' => $type === 'show' ? $show['display_value'] : '',
            'hide_selector' => $type === 'show' ? $show['hide_selector'] : '',
            'page_url' => $type === 'show' ? $show['page_url'] : null,
            'mail_to' => $mail_to,
        ]);

        Runner::schedule_job($id, $run_at);

        self::redirect(['view' => 'job', 'id' => $id], 'Ingepland: ' . Time::full($run_at) . '.', 'success');
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

        $status = (string) (Store::get_job($id)['status'] ?? '');
        $outcomes = [
            'done' => ['Uitgevoerd en op de pagina\'s gecontroleerd.', 'success'],
            'warning' => ['Uitgevoerd, maar er is iets om te controleren. Lees de meldingen hieronder.', 'warning'],
            'failed' => ['Niet gelukt. Lees de melding hieronder.', 'error'],
        ];
        $outcome = $outcomes[$status] ?? ['De taak is gestart en is nog bezig. Ververs deze pagina straks.', 'warning'];

        self::redirect(['view' => 'job', 'id' => $id], $outcome[0], $outcome[1]);
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
        $notes = [];
        $plan = Attachments::plan($old_url, $new_url);

        // Alleen bij het eerste stuk: de beslissing valt pas bij het uitvoeren.
        if (!$cursor) {
            $check = Runner::check_new_url(['new_url' => $new_url]);
            if ($check['message'] !== '') {
                $warnings[] = $check['message'];
            }

            $warnings = array_merge($warnings, self::attachment_warnings($plan));

            if ($plan['refs'] !== null) {
                $notes[] = 'Beide bestanden staan in de mediabibliotheek. De bestands-ID\'s en kleinere versies gaan mee, dus ook de builder toont het nieuwe bestand.';
            }
        }

        $variants = new Variants($old_url, $new_url, home_url('/'), $plan['also'], $plan['refs']);
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

        wp_send_json_success(['rows' => $rows, 'cursor' => $cursor, 'done' => !empty($cursor['done']), 'warnings' => $warnings, 'notes' => $notes]);
    }

    /** @return string[] */
    private static function attachment_warnings(array $plan): array
    {
        global $wpdb;

        if (!$plan['old_id']) {
            return [];
        }

        if (!$plan['new_id']) {
            return ['De oude URL is een bestand uit de mediabibliotheek, de nieuwe niet. Builders die via het bestands-ID werken, blijven dan het oude bestand tonen. Kies het nieuwe bestand uit de mediabibliotheek.'];
        }

        $featured = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $plan['old_id']
        ));

        return $featured ? [sprintf('%d bericht(en) of pagina(\'s) gebruiken dit bestand als uitgelichte afbeelding. Die worden niet gewijzigd.', $featured)] : [];
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

        $tones = ['success' => 'good', 'warning' => 'warn', 'error' => 'bad'];
        self::callout($tones[$notice['type']] ?? 'info', esc_html($notice['message']));
    }

    /** "oud.pdf → nieuw.pdf": wat het om gaat, zonder de hele URL. */
    private static function job_title(array $job): string
    {
        if ($job['type'] === 'show') {
            return $job['selector'] . (!empty($job['hide_selector']) ? ' in plaats van ' . $job['hide_selector'] : ' tonen');
        }

        if ($job['type'] === 'file') {
            return self::short_url($job['old_url']) . ' krijgt nieuwe inhoud';
        }

        return self::short_url($job['old_url']) . ' → ' . self::short_url($job['new_url']);
    }

    private static function short_url(string $url): string
    {
        $name = rawurldecode(wp_basename((string) wp_parse_url($url, PHP_URL_PATH)));

        return $name !== '' ? $name : (string) wp_parse_url($url, PHP_URL_HOST);
    }

    private static function type_label(string $type): string
    {
        $labels = ['file' => 'Bestand vervangen', 'show' => 'Element tonen'];

        return $labels[$type] ?? 'Link wisselen';
    }

    private static function type_icon(string $type): string
    {
        $icons = ['file' => 'file', 'show' => 'eye'];

        return $icons[$type] ?? 'link';
    }

    private static function status_badge(array $job): string
    {
        $labels = [
            'scheduled' => 'Ingepland',
            'preparing' => 'Bezig',
            'applying' => 'Bezig',
            'finishing' => 'Bezig',
            'done' => 'Geslaagd',
            'warning' => 'Controleer dit',
            'failed' => 'Mislukt',
            'cancelled' => 'Geannuleerd',
            'undone' => 'Ongedaan gemaakt',
        ];

        $status = $job['status'];

        return '<span class="jp-status jp-status-' . esc_attr($status) . '">' . esc_html($labels[$status] ?? $status) . '</span>';
    }

    private static function icon(string $name): string
    {
        $paths = [
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'back' => '<path d="M15 6l-6 6 6 6"/>',
            'chevron' => '<path d="M9 6l6 6-6 6"/>',
            'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
            'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
            'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.500 12 21.500 12 18 18.500 12 18.500 2.500 12 2.500 12z"/><circle cx="12" cy="12" r="2.800"/>',
            'search' => '<circle cx="11" cy="11" r="6.500"/><path d="M16 16l4.500 4.500"/>',
            'check' => '<path d="M5 12.500l4.500 4.500L19 7.500"/>',
            'alert' => '<path d="M12 8.500v4.500M12 16.500v.01"/><path d="M10.300 4l-8 14a2 2 0 0 0 1.700 3h16a2 2 0 0 0 1.700-3l-8-14a2 2 0 0 0-3.400 0z"/>',
            'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8v.01"/>',
        ];

        return '<svg class="jp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
    }
}
