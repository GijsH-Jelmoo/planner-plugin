<?php

namespace Jelmoo\Planner;

/*
 * Maakt een element dat in de builder op display: none staat op een vast moment
 * zichtbaar. De plugin zet een CSS-regel in de pagina en blijft van de data van
 * de builder af, zodat dit op elke builder werkt.
 */
class ShowElement
{
    private const OPTION = 'jelmoo_planner_active_css';

    public const VALUES = ['flex', 'block', 'inline-flex', 'inline-block', 'grid', 'inline'];

    /** Alleen één simpele #id of .class, zodat er geen losse CSS in de site kan komen. Geeft '' als het niet klopt. */
    public static function normalize_selector(string $selector): string
    {
        $selector = trim($selector);

        // Bricks toont het ID zonder hekje.
        if ($selector !== '' && $selector[0] !== '#' && $selector[0] !== '.') {
            $selector = '#' . $selector;
        }

        return preg_match('/^[#.][A-Za-z_][A-Za-z0-9_-]*$/', $selector) ? $selector : '';
    }

    public static function is_valid_value(string $value): bool
    {
        return in_array($value, self::VALUES, true);
    }

    public static function rule(string $selector, string $value): string
    {
        return $selector . '{display:' . $value . '!important}';
    }

    /** @return array{found:bool,hidden:bool} staat het element in de HTML, en is de regel display:none zichtbaar */
    public static function inspect(string $html, string $selector): array
    {
        $name = preg_quote(substr($selector, 1), '~');

        $found = $selector[0] === '#'
            ? preg_match('~\sid=["\']?' . $name . '(?:["\'\s>/])~', $html) === 1
            : preg_match('~\sclass=(?:"[^"]*(?<![\w-])' . $name . '(?![\w-])[^"]*"|\'[^\']*(?<![\w-])' . $name . '(?![\w-])[^\']*\')~', $html) === 1;

        $hidden = preg_match('~' . preg_quote($selector, '~') . '(?![\w-])[^{}]*\{[^{}]*display\s*:\s*none~i', $html) === 1;

        return ['found' => $found, 'hidden' => $hidden];
    }

    public static function rule_is_active(string $html, string $selector, string $value): bool
    {
        // Ruim genoeg voor een minifier die spaties aanpast.
        return preg_match('~' . preg_quote($selector, '~') . '\s*\{\s*display\s*:\s*' . preg_quote($value, '~') . '\s*!\s*important~i', $html) === 1;
    }

    public static function activate(array $job): void
    {
        $rules = (array) get_option(self::OPTION, []);
        $rules[$job['id']] = self::rule($job['selector'], $job['display_value']);
        update_option(self::OPTION, $rules, true);
    }

    public static function deactivate(array $job): void
    {
        $rules = (array) get_option(self::OPTION, []);
        unset($rules[$job['id']]);
        update_option(self::OPTION, $rules, true);
    }

    /** De regels worden al bij het inplannen gecontroleerd, maar uit de database blijft alles verdacht. */
    public static function print_css(): void
    {
        $rules = array_filter((array) get_option(self::OPTION, []), function ($rule) {
            return is_string($rule) && preg_match('/^[#.][A-Za-z0-9_-]+\{display:[a-z-]+!important\}$/', $rule);
        });

        if ($rules) {
            echo '<style id="jelmoo-planner-css">' . implode('', $rules) . "</style>\n";
        }
    }

    /** @return string shown, stale, missing of error */
    public static function check_page(array $job): string
    {
        $response = wp_remote_get($job['page_url'], [
            'timeout' => 15,
            'redirection' => 3,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            return 'error';
        }

        $html = wp_remote_retrieve_body($response);

        if (!self::inspect($html, $job['selector'])['found']) {
            return 'missing';
        }

        return self::rule_is_active($html, $job['selector'], $job['display_value']) ? 'shown' : 'stale';
    }
}
