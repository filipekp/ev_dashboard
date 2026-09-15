<?php

declare(strict_types=1);

namespace App\Document\Pdf;

use RuntimeException;

/**
 * Lehký extraktor textu z textových PDF bez externích binárek.
 *
 * Podporuje běžné FlateDecode content streamy a fonty Identity-H s ToUnicode CMap.
 * Není náhradou plnohodnotné PDF knihovny; je určený pro deterministické importy
 * faktur, které obsahují skutečný text (nikoli pouze naskenovaný obrázek).
 */
final class PdfTextExtractor
{
    /**
     * @var array<int,string>
     */
    private $objects = [];

    /**
     * @var array<string,int>
     */
    private $fontObjects = [];

    /**
     * @var array<string,array<string,string>>
     */
    private $fontMaps = [];

    public function extract(string $path): string
    {
        $pdf = file_get_contents($path);
        if ($pdf === false || $pdf === '') {
            throw new RuntimeException('PDF dokument nelze načíst.');
        }

        if (strncmp($pdf, '%PDF-', 5) !== 0) {
            throw new RuntimeException('Soubor není platný PDF dokument.');
        }

        $this->objects = $this->parseObjects($pdf);
        $this->fontObjects = $this->parseFontResources($pdf);
        $this->fontMaps = $this->buildFontMaps();

        $contentObjectIds = $this->findContentObjectIds($pdf);
        if ($contentObjectIds === []) {
            throw new RuntimeException('PDF neobsahuje čitelný textový obsah.');
        }

        $parts = [];
        foreach ($contentObjectIds as $objectId) {
            if (!isset($this->objects[$objectId])) {
                continue;
            }

            $stream = $this->decodeStream($this->objects[$objectId]);
            if ($stream === null) {
                continue;
            }

            $text = $this->extractTextFromContentStream($stream);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        $text = trim(implode("\n", $parts));
        if ($text === '') {
            throw new RuntimeException('Z PDF se nepodařilo získat text.');
        }

        return $this->normalizeText($text);
    }

    /**
     * @return array<int,string>
     */
    private function parseObjects(string $pdf): array
    {
        $objects = [];
        if (!preg_match_all('/(?:^|\R)(\d+)\s+\d+\s+obj\b(.*?)\bendobj\b/s', $pdf, $matches, PREG_SET_ORDER)) {
            return $objects;
        }

        foreach ($matches as $match) {
            $objects[(int)$match[1]] = $match[2];
        }

        return $objects;
    }

    /**
     * @return array<string,int>
     */
    private function parseFontResources(string $pdf): array
    {
        $fonts = [];
        if (!preg_match_all('/\/Font\s*<<(.+?)>>/s', $pdf, $matches)) {
            return $fonts;
        }

        foreach ($matches[1] as $fontBlock) {
            if (!preg_match_all('/\/([A-Za-z0-9._-]+)\s+(\d+)\s+\d+\s+R/', $fontBlock, $fontMatches, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($fontMatches as $fontMatch) {
                $fonts[$fontMatch[1]] = (int)$fontMatch[2];
            }
        }

        return $fonts;
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function buildFontMaps(): array
    {
        $maps = [];

        foreach ($this->fontObjects as $resourceName => $fontObjectId) {
            $fontObject = $this->objects[$fontObjectId] ?? '';
            if (!preg_match('/\/ToUnicode\s+(\d+)\s+\d+\s+R/', $fontObject, $match)) {
                continue;
            }

            $toUnicodeObject = $this->objects[(int)$match[1]] ?? '';
            $stream = $this->decodeStream($toUnicodeObject);
            if ($stream === null) {
                continue;
            }

            $maps[$resourceName] = $this->parseCMap($stream);
        }

        return $maps;
    }

    /**
     * @return array<string,string>
     */
    private function parseCMap(string $cmap): array
    {
        $map = [];

        if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (!preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $pairs, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($pairs as $pair) {
                    $map[strtoupper($pair[1])] = $this->unicodeHexToUtf8($pair[2]);
                }
            }
        }

        if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (!preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $block, $ranges, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($ranges as $range) {
                    $from = hexdec($range[1]);
                    $to = hexdec($range[2]);
                    $unicodeStart = hexdec($range[3]);
                    $width = strlen($range[1]);

                    for ($code = $from; $code <= $to; $code++) {
                        $sourceHex = strtoupper(str_pad(dechex($code), $width, '0', STR_PAD_LEFT));
                        $unicodeHex = str_pad(dechex($unicodeStart + ($code - $from)), 4, '0', STR_PAD_LEFT);
                        $map[$sourceHex] = $this->unicodeHexToUtf8($unicodeHex);
                    }
                }
            }
        }

        return $map;
    }

    private function unicodeHexToUtf8(string $hex): string
    {
        $binary = @hex2bin(strlen($hex) % 2 === 0 ? $hex : '0' . $hex);
        if ($binary === false) {
            return '';
        }

        if (function_exists('mb_convert_encoding')) {
            return (string)mb_convert_encoding($binary, 'UTF-8', 'UTF-16BE');
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-16BE', 'UTF-8//IGNORE', $binary);
            return $converted === false ? '' : $converted;
        }

        return '';
    }

    /**
     * @return array<int,int>
     */
    private function findContentObjectIds(string $pdf): array
    {
        $ids = [];

        if (preg_match_all('/\/Contents\s*\[(.*?)\]/s', $pdf, $arrayMatches)) {
            foreach ($arrayMatches[1] as $block) {
                if (!preg_match_all('/(\d+)\s+\d+\s+R/', $block, $refs)) {
                    continue;
                }

                foreach ($refs[1] as $id) {
                    $ids[(int)$id] = (int)$id;
                }
            }
        }

        if (preg_match_all('/\/Contents\s+(\d+)\s+\d+\s+R/', $pdf, $singleMatches)) {
            foreach ($singleMatches[1] as $id) {
                $ids[(int)$id] = (int)$id;
            }
        }

        return array_values($ids);
    }

    private function decodeStream(string $object): ?string
    {
        if (!preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $object, $match)) {
            return null;
        }

        $stream = $match[1];
        if (strpos($object, '/FlateDecode') !== false) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate($stream);
            }
            if ($decoded === false) {
                return null;
            }
            $stream = $decoded;
        }

        return $stream;
    }

    private function extractTextFromContentStream(string $stream): string
    {
        $font = null;
        $output = [];

        $tokens = preg_split('/\R/', $stream) ?: [];
        foreach ($tokens as $line) {
            if (preg_match('/\/([A-Za-z0-9._-]+)\s+[\d.]+\s+Tf/', $line, $fontMatch)) {
                $font = $fontMatch[1];
            }

            if ($font === null) {
                continue;
            }

            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $line, $arrayMatches)) {
                foreach ($arrayMatches[1] as $arrayBody) {
                    $text = $this->decodeTextArray($arrayBody, $font);
                    if ($text !== '') {
                        $output[] = $text;
                    }
                }
            }

            if (preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/', $line, $hexMatches)) {
                foreach ($hexMatches[1] as $hex) {
                    $text = $this->decodeHexString($hex, $font);
                    if ($text !== '') {
                        $output[] = $text;
                    }
                }
            }

            if (preg_match_all('/\(((?:\\.|[^\\()])*)\)\s*Tj/s', $line, $literalMatches)) {
                foreach ($literalMatches[1] as $literal) {
                    $text = $this->decodeLiteralString($literal, $font);
                    if ($text !== '') {
                        $output[] = $text;
                    }
                }
            }
        }

        return implode("\n", $output);
    }

    private function decodeTextArray(string $body, string $font): string
    {
        $parts = [];
        if (preg_match_all('/<([0-9A-Fa-f]+)>/', $body, $matches)) {
            foreach ($matches[1] as $hex) {
                $parts[] = $this->decodeHexString($hex, $font);
            }
        }
        if (preg_match_all('/\(((?:\\.|[^\\()])*)\)/s', $body, $literalMatches)) {
            foreach ($literalMatches[1] as $literal) {
                $parts[] = $this->decodeLiteralString($literal, $font);
            }
        }

        return trim(implode('', $parts));
    }

    private function decodeLiteralString(string $literal, string $font): string
    {
        $bytes = preg_replace_callback('~\\\\([0-7]{1,3}|[nrtbf()\\\\])~', static function (array $match): string {
            $value = $match[1];
            if (preg_match('/^[0-7]{1,3}$/', $value)) {
                return chr(octdec($value));
            }
            $escapes = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C", '(' => '(', ')' => ')', '\\' => '\\'];
            return $escapes[$value] ?? $value;
        }, $literal) ?? $literal;

        $map = $this->fontMaps[$font] ?? [];
        if ($map !== []) {
            $text = '';
            for ($offset = 0, $length = strlen($bytes); $offset + 1 < $length; $offset += 2) {
                $code = strtoupper(bin2hex(substr($bytes, $offset, 2)));
                $text .= $map[$code] ?? '';
            }
            if ($text !== '') {
                return $text;
            }
        }

        if (function_exists('mb_convert_encoding')) {
            return (string)mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        }
        if (function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $bytes);
            return $converted === false ? $bytes : $converted;
        }
        return $bytes;
    }

    private function decodeHexString(string $hex, string $font): string
    {
        $map = $this->fontMaps[$font] ?? [];
        if ($map === []) {
            return '';
        }

        $text = '';
        for ($offset = 0, $length = strlen($hex); $offset + 4 <= $length; $offset += 4) {
            $code = strtoupper(substr($hex, $offset, 4));
            $text .= $map[$code] ?? '';
        }

        return $text;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
