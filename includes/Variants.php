<?php

namespace Jelmoo\Planner;

/*
 * Vertaalt één URL-paar naar alle vormen waarin builders een link opslaan, elk
 * met de nieuwe URL in dezelfde codering. Geen WordPress nodig, zodat dit los
 * te testen is.
 */
class Variants
{
    // Na de treffer mag het pad niet doorlopen. "/menu" raakt zo "/menu-2" en
    // "/menu/sub" niet, maar een query (?v=2) of sluitend aanhalingsteken wel.
    private const RIGHT_BOUNDARY = '(?![A-Za-z0-9_~\-]|\.[A-Za-z0-9]|/|\\\\+/|%(?!22|27|26|23|2[Cc]|3[CEFcef]|5[Dd]|7[Dd])[0-9A-Fa-f]{2})';

    // Een site-relatieve link mag niet midden in een andere URL staan.
    private const RELATIVE_LEFT_BOUNDARY = '(?<![A-Za-z0-9_~\-.:%/\\\\])';

    /** @var array<string,string> variant van de oude URL => nieuwe URL in dezelfde codering */
    private $map = [];

    /** @var string */
    private $regex = '';

    /** @var string[] */
    private $search_terms = [];

    /** @var bool */
    private $suspect_eligible = false;

    /** @var Variants[] kleinere versies en andere vormen van hetzelfde bestand */
    private $also = [];

    /** @var AttachmentRefs|null */
    private $attachment;

    /**
     * @param array<string,string> $also extra URL-paren: oud => nieuw
     */
    public function __construct(string $old_url, string $new_url, string $site_url, array $also = [], ?AttachmentRefs $attachment = null)
    {
        $this->attachment = $attachment;

        // Eén grote regex per bestand zou bij veel formaten de limiet van PCRE raken.
        foreach ($also as $extra_old => $extra_new) {
            $this->also[] = new self((string) $extra_old, (string) $extra_new, $site_url);
        }

        $old_url = trim($old_url);
        $new_url = trim($new_url);

        $old = parse_url($old_url);
        if (!is_array($old) || empty($old['host'])) {
            throw new \InvalidArgumentException('Oude URL is geen volledige URL: ' . $old_url);
        }

        $old_host = strtolower($old['host']) . (isset($old['port']) ? ':' . $old['port'] : '');
        $old_tail = ($old['path'] ?? '') . (isset($old['query']) ? '?' . $old['query'] : '');
        $site_host = strtolower((string) parse_url($site_url, PHP_URL_HOST));

        $hosts = [$old_host];
        if (strpos($old_host, 'www.') === 0) {
            $hosts[] = substr($old_host, 4);
        } else {
            $hosts[] = 'www.' . $old_host;
        }

        $absolute = [];
        $protocol_relative = [];
        foreach ($this->tail_forms($old_tail) as $tail) {
            foreach ($hosts as $host) {
                $absolute['https://' . $host . $tail] = $new_url;
                $absolute['http://' . $host . $tail] = $new_url;
                $protocol_relative['//' . $host . $tail] = preg_replace('#^https?:#i', '', $new_url);
            }
        }

        $patterns = [];
        foreach ($absolute as $needle => $replacement) {
            $this->add_encoded($patterns, $needle, $replacement, '', true);
        }
        foreach ($protocol_relative as $needle => $replacement) {
            $this->add_encoded($patterns, $needle, $replacement, '(?<![A-Za-z0-9:/\\\\])', false);
        }

        // Alleen voor URL's op deze site, en met een echt pad: "/" zou overal raken.
        $same_site = $site_host !== '' && $this->same_host($old_host, $site_host);
        if ($same_site && strlen(trim($old['path'] ?? '', '/')) > 0) {
            $new_relative = $this->relative_form($new_url, $site_host);
            foreach ($this->tail_forms($old_tail) as $tail) {
                $this->add_encoded($patterns, $tail, $new_relative, self::RELATIVE_LEFT_BOUNDARY, false);
            }
        }

        // Langste variant eerst, in één pass: een vervanging wordt nooit opnieuw geraakt.
        uksort($patterns, function ($a, $b) {
            return strlen($b) - strlen($a) ?: strcmp($a, $b);
        });

        $alternatives = [];
        foreach ($patterns as $needle => $pattern) {
            $this->map[$needle] = $pattern['replacement'];
            $alternatives[] = $pattern['left'] . preg_quote($needle, '#');
        }

        $this->regex = '#(?:' . implode('|', $alternatives) . ')' . self::RIGHT_BOUNDARY . '#';
        $this->build_search_terms($old);
    }

    /** Vervangt alle varianten. Geeft [nieuwe tekst, aantal treffers]. */
    public function replace(string $text): array
    {
        $count = 0;
        $result = preg_replace_callback($this->regex, function ($match) {
            return $this->map[$match[0]];
        }, $text, -1, $count);

        if ($result === null) {
            throw new \RuntimeException('Regex-fout bij vervangen: ' . preg_last_error());
        }

        foreach ($this->also as $variant) {
            list($result, $extra) = $variant->replace($result);
            $count += $extra;
        }

        return [$result, $count];
    }

    public function matches(string $text): bool
    {
        if (preg_match($this->regex, $text) === 1) {
            return true;
        }

        foreach ($this->also as $variant) {
            if ($variant->matches($text)) {
                return true;
            }
        }

        return false;
    }

    /** Termen om in SQL kandidaat-rijen mee te vinden; PHP bepaalt daarna de echte treffer. */
    public function search_terms(): array
    {
        $terms = $this->search_terms;

        foreach ($this->also as $variant) {
            $terms = array_merge($terms, $variant->search_terms());
        }

        return array_values(array_unique($terms));
    }

    public function attachment(): ?AttachmentRefs
    {
        return $this->attachment;
    }

    /**
     * Het bestandsdeel staat in de tekst, maar geen variant past: mogelijk een
     * vorm die we niet kennen (andere host, eigen codering). Niet wijzigen, wel
     * tonen. Alleen voor bestandsnamen, want "menu" staat overal.
     */
    public function is_suspect(string $text): bool
    {
        if (!$this->suspect_eligible || $this->matches($text)) {
            return false;
        }

        foreach ($this->search_terms() as $term) {
            if (strpos($text, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Vormen van pad+query: exact, gedecodeerd en met/zonder afsluitende slash. */
    private function tail_forms(string $tail): array
    {
        $forms = [$tail];

        if (strpos($tail, '%') !== false) {
            $forms[] = rawurldecode($tail);
        }

        // Een bestand blijft exact; een pagina is met en zonder slash dezelfde.
        $last_segment = basename((string) parse_url($tail, PHP_URL_PATH));
        if (strpos($tail, '?') === false && strpos($last_segment, '.') === false && trim($tail, '/') !== '') {
            $forms[] = rtrim($tail, '/');
            $forms[] = rtrim($tail, '/') . '/';
        }

        return array_values(array_unique($forms));
    }

    private function add_encoded(array &$patterns, string $needle, string $replacement, string $left, bool $with_url_encoding): void
    {
        foreach (self::encoders() as $name => $encode) {
            if (strpos($name, 'url') === 0 && !$with_url_encoding) {
                continue;
            }

            $encoded = $encode($needle);
            if (!isset($patterns[$encoded])) {
                $patterns[$encoded] = ['replacement' => $encode($replacement), 'left' => $left];
            }
        }
    }

    /** @return array<string,callable> */
    private static function encoders(): array
    {
        $block = function ($value) {
            return str_replace(['--', '&'], ['\\u002d\\u002d', '\\u0026'], $value);
        };

        return [
            'plain' => function ($value) {
                return $value;
            },
            'json' => function ($value) {
                return str_replace('/', '\\/', $value);
            },
            // JSON in een JSON-string
            'json_nested' => function ($value) {
                return str_replace('/', '\\\\\\/', $value);
            },
            'block' => $block,
            'block_json' => function ($value) use ($block) {
                return str_replace('/', '\\/', $block($value));
            },
            'amp' => function ($value) {
                return str_replace('&', '&amp;', $value);
            },
            'amp_numeric' => function ($value) {
                return str_replace('&', '&#038;', $value);
            },
            'url' => function ($value) {
                return rawurlencode($value);
            },
            'url_lower' => function ($value) {
                return preg_replace_callback('/%[0-9A-F]{2}/', function ($hex) {
                    return strtolower($hex[0]);
                }, rawurlencode($value));
            },
        ];
    }

    private function same_host(string $a, string $b): bool
    {
        return preg_replace('/^www\./', '', $a) === preg_replace('/^www\./', '', $b);
    }

    private function relative_form(string $new_url, string $site_host): string
    {
        $new = parse_url($new_url);
        if (is_array($new) && !empty($new['host']) && $this->same_host(strtolower($new['host']), $site_host)) {
            return ($new['path'] ?? '/') . (isset($new['query']) ? '?' . $new['query'] : '');
        }

        return $new_url;
    }

    private function build_search_terms(array $old): void
    {
        $segment = basename($old['path'] ?? '');
        if ($segment === '' || $segment === '/') {
            $this->search_terms = [strtolower($old['host'])];
            return;
        }

        $this->suspect_eligible = strpos($segment, '.') !== false;

        $decoded = rawurldecode($segment);
        $this->search_terms = array_values(array_unique([
            $segment,
            $decoded,
            rawurlencode($decoded),
            str_replace('--', '\\u002d\\u002d', $segment),
        ]));
    }
}
