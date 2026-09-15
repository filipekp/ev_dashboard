<?php

declare(strict_types=1);

namespace App;

/**
 * Pomocné metody pro bezpečné formátování hodnot v šablonách.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   15.09.2026
 */
final class View
{
    public static function h(?string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /** @param int|float|string|null $number */
    public static function cz($number, int $decimals = 1): string
    {
        return number_format((float)$number, $decimals, ',', ' ');
    }

    public static function shortAddress(string $value): string
    {
        return preg_replace('/,\s*Czechia$/u', '', $value) ?? $value;
    }

    public static function routeKey(string $from, string $to): string
    {
        return self::shortAddress($from) . ' → ' . self::shortAddress($to);
    }

    public static function displayRoute(?string $from, ?string $to): string
    {
        $from = trim((string)$from);
        $to = trim((string)$to);
        if ($from === '' && $to === '') {
            return 'Bez údajů o trase';
        }

        return self::routeKey($from !== '' ? $from : '—', $to !== '' ? $to : '—');
    }

    /** @return array{0:?float,1:?float} */
    public static function parseCoord(?string $value): array
    {
        if (!$value || strpos($value, ',') === false) {
            return [null, null];
        }

        [$a, $b] = array_map('trim', explode(',', $value, 2));

        return [is_numeric($a) ? (float)$a : null, is_numeric($b) ? (float)$b : null];
    }

    /**
     * Vykreslí bezpečnou podmnožinu Markdownu používanou v GitHub release notes.
     * HTML ze vstupu je vždy escapované; povolují se pouze odkazy http/https vytvořené parserem.
     */
    public static function markdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));
        if ($markdown === '') {
            return '<p class="muted">Bez popisu změn.</p>';
        }

        $lines = explode("\n", $markdown);
        $html = [];
        $paragraph = [];
        $listType = null;
        $inCode = false;
        $code = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if (!$paragraph) {
                return;
            }
            $html[] = '<p>' . self::markdownInline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
        };

        $closeList = static function () use (&$listType, &$html): void {
            if ($listType === null) {
                return;
            }
            $html[] = '</' . $listType . '>';
            $listType = null;
        };

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                $flushParagraph();
                $closeList();
                if ($inCode) {
                    $html[] = '<pre><code>' . self::h(implode("\n", $code)) . '</code></pre>';
                    $code = [];
                    $inCode = false;
                } else {
                    $inCode = true;
                }
                continue;
            }

            if ($inCode) {
                $code[] = $line;
                continue;
            }

            if (trim($line) === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $match)) {
                $flushParagraph();
                $closeList();
                $sourceLevel = strlen($match[1]);
                $level = min(5, max(3, $sourceLevel + 2));
                $html[] = '<h' . $level . '>' . self::markdownInline(trim($match[2])) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $match)) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $closeList();
                    $listType = 'ul';
                    $html[] = '<ul>';
                }
                $html[] = '<li>' . self::markdownInline(trim($match[1])) . '</li>';
                continue;
            }

            if (preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $match)) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $closeList();
                    $listType = 'ol';
                    $html[] = '<ol>';
                }
                $html[] = '<li>' . self::markdownInline(trim($match[1])) . '</li>';
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $line, $match)) {
                $flushParagraph();
                $closeList();
                $html[] = '<blockquote>' . self::markdownInline(trim($match[1])) . '</blockquote>';
                continue;
            }

            $paragraph[] = trim($line);
        }

        if ($inCode) {
            $html[] = '<pre><code>' . self::h(implode("\n", $code)) . '</code></pre>';
        }
        $flushParagraph();
        $closeList();

        return implode("\n", $html);
    }

    private static function markdownInline(string $text): string
    {
        $escaped = self::h($text);

        $escaped = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/i',
            static function (array $match): string {
                $label = $match[1];
                $url = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');

                return '<a href="' . self::h($url) . '" target="_blank" rel="noopener noreferrer">'
                    . $label . '</a>';
            },
            $escaped
        ) ?? $escaped;

        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $escaped) ?? $escaped;

        return $escaped;
    }
}
