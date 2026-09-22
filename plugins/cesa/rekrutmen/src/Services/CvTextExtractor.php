<?php

namespace Cesa\Rekrutmen\Services;

class CvTextExtractor
{
    private function isSensibleText(string $text): bool
    {
        $len = strlen($text);
        if ($len < 30) {
            return false;
        }
        if (str_contains($text, '%PDF-') || str_contains($text, 'endobj') || str_contains($text, 'xref')) {
            return false;
        }

        $alphaCount = preg_match_all('/[a-zA-Z0-9]/', $text);
        $totalNonSpace = preg_match_all('/\S/', $text);
        if ($totalNonSpace > 0 && ($alphaCount / $totalNonSpace) < 0.5) {
            return false;
        }

        $words = preg_split('/\s+/', trim($text));
        $totalWords = count($words);
        if ($totalWords > 20) {
            $singleLetterCount = 0;
            foreach ($words as $w) {
                if (mb_strlen($w) <= 1) {
                    $singleLetterCount++;
                }
            }
            if (($singleLetterCount / $totalWords) > 0.55) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract clean textual content from candidate CV file (supports PDF & uncompressed text).
     */
    public function extract(string $content): string
    {
        if ($content === '') {
            return '';
        }

        if (strlen($content) > 15 * 1024 * 1024) {
            return '';
        }

        if (! str_contains($content, 'stream') && ! str_starts_with($content, '%PDF-')) {
            return mb_check_encoding($content, 'UTF-8') && $this->isSensibleText($content)
                ? trim($content)
                : '';
        }

        $extractedText = '';

        if (preg_match_all('/(?:<<([\s\S]*?)>>\s*)?stream[\r\n]+([\s\S]*?)[\r\n]+endstream/is', $content, $matches)) {
            $cmaps = [];
            $uncompressedStreams = [];
            $uncompressedBytes = 0;

            foreach ($matches[2] as $idx => $stream) {
                $streamLen = strlen($stream);
                $dict = $matches[1][$idx] ?? '';
                if ($streamLen > 500000 || preg_match('/\/Subtype\s*\/Image/i', $dict)) {
                    continue;
                }

                $uncompressed = @gzuncompress($stream, 8 * 1024 * 1024);
                if ($uncompressed === false) {
                    $uncompressed = @gzinflate($stream, 8 * 1024 * 1024);
                }

                if ($uncompressed === false && ! preg_match('/\/Filter\b/', $dict)) {
                    $uncompressed = $stream;
                }

                if ($uncompressed !== false) {
                    $uncompressedBytes += strlen($uncompressed);
                    if ($uncompressedBytes > 32 * 1024 * 1024) {
                        return '';
                    }

                    $uncompressedStreams[] = $uncompressed;
                    $this->collectToUnicodeMaps($uncompressed, $cmaps);
                }
            }

            unset($matches);

            foreach ($uncompressedStreams as $uncompressed) {
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $uncompressed, $textMatches)) {
                    $parts = [];
                    foreach ($textMatches[1] as $literal) {
                        $parts[] = $this->decodeCodedText($this->unescapePdfLiteral($literal), $cmaps);
                    }
                    $extractedText .= ' '.$this->joinTextRuns($parts);
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $uncompressed, $arrayMatches)) {
                    foreach ($arrayMatches[1] as $arr) {
                        $parts = [];
                        if (preg_match_all('/\((.*?)\)|<([0-9a-fA-F]{2,})>/s', $arr, $pieces, PREG_SET_ORDER)) {
                            foreach ($pieces as $piece) {
                                if (($piece[1] ?? '') !== '') {
                                    $parts[] = $this->decodeCodedText($this->unescapePdfLiteral($piece[1]), $cmaps);
                                } elseif (($piece[2] ?? '') !== '') {
                                    $parts[] = $this->decodeHexText($piece[2], $cmaps);
                                }
                            }
                        }
                        $extractedText .= ' '.implode('', $parts);
                    }
                }
                if (preg_match_all('/<([0-9a-fA-F]{2,})>\s*Tj/s', $uncompressed, $hexMatches)) {
                    $parts = [];
                    foreach ($hexMatches[1] as $hex) {
                        $parts[] = $this->decodeHexText($hex, $cmaps);
                    }
                    $extractedText .= ' '.$this->joinTextRuns($parts);
                }
            }

            unset($uncompressedStreams, $cmaps);
        }

        $extractedText = mb_convert_encoding($extractedText, 'UTF-8', 'UTF-8');
        $cleaned = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $extractedText);
        $cleaned = preg_replace('/[^\p{L}\p{N}\s\.\,\-\@\:\/\(\)\+\#]/u', ' ', $cleaned) ?? '';
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');
        $cleaned = $this->collapseSpacedLetters($cleaned);

        if (! $this->isSensibleText($cleaned)) {
            return '';
        }

        return $cleaned;
    }

    /**
     * @param  array<string, string>  $cmaps
     */
    private function collectToUnicodeMaps(string $uncompressed, array &$cmaps): void
    {
        if (preg_match_all('/beginbfchar\s*([\s\S]*?)endbfchar/i', $uncompressed, $bfBlocks)) {
            foreach ($bfBlocks[1] as $block) {
                if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $block, $bf) === 0) {
                    continue;
                }
                foreach ($bf[1] as $index => $src) {
                    $this->rememberCmapEntry($cmaps, $src, $bf[2][$index]);
                }
            }
        }

        if (preg_match_all('/beginbfrange\s*([\s\S]*?)endbfrange/i', $uncompressed, $rangeBlocks)) {
            foreach ($rangeBlocks[1] as $block) {
                if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $block, $ranges) === 0) {
                    continue;
                }
                foreach ($ranges[1] as $index => $start) {
                    $startCode = hexdec($start);
                    $endCode = hexdec($ranges[2][$index]);
                    $targetStart = hexdec($ranges[3][$index]);
                    if ($endCode < $startCode || ($endCode - $startCode) > 256) {
                        continue;
                    }
                    for ($code = $startCode; $code <= $endCode; $code++) {
                        $src = sprintf('%0'.strlen($start).'x', $code);
                        $tgt = sprintf('%04x', $targetStart + ($code - $startCode));
                        $this->rememberCmapEntry($cmaps, $src, $tgt);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, string>  $cmaps
     */
    private function rememberCmapEntry(array &$cmaps, string $src, string $targetHex): void
    {
        if (count($cmaps) >= 2000) {
            return;
        }

        $decodedChar = @hex2bin($targetHex);
        if ($decodedChar === false) {
            return;
        }

        $mapped = @mb_convert_encoding($decodedChar, 'UTF-8', 'UTF-16BE');
        if (! is_string($mapped) || $mapped === '') {
            return;
        }

        $src = strtolower($src);
        $cmaps[$src] = $mapped;
        $cmaps[str_pad($src, 4, '0', STR_PAD_LEFT)] = $mapped;
    }

    /**
     * @param  list<string>  $parts
     */
    private function joinTextRuns(array $parts): string
    {
        $parts = array_values(array_filter($parts, fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return '';
        }

        $shortRuns = 0;
        foreach ($parts as $part) {
            if (mb_strlen(trim($part)) <= 2) {
                $shortRuns++;
            }
        }

        return implode(($shortRuns / count($parts)) >= 0.7 ? '' : ' ', $parts);
    }

    private function collapseSpacedLetters(string $text): string
    {
        $collapsed = preg_replace_callback(
            '/(?:(?<=^)|(?<=\s))(?:[\p{L}\p{N}]\s){2,}[\p{L}\p{N}](?=\s|$)/u',
            fn (array $match): string => str_replace(' ', '', $match[0]),
            $text
        );

        return is_string($collapsed) ? $collapsed : $text;
    }

    private function unescapePdfLiteral(string $raw): string
    {
        $out = '';
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            if ($raw[$i] !== '\\' || $i + 1 >= $length) {
                $out .= $raw[$i];

                continue;
            }

            $next = $raw[$i + 1];
            $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c", '(' => '(', ')' => ')', '\\' => '\\'];
            if (isset($simple[$next])) {
                $out .= $simple[$next];
                $i++;

                continue;
            }

            if (preg_match('/^[0-7]{1,3}/', substr($raw, $i + 1, 3), $octal) === 1) {
                $out .= chr(octdec($octal[0]));
                $i += strlen($octal[0]);

                continue;
            }

            $out .= $next;
            $i++;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $cmaps
     */
    private function decodeCodedText(string $bytes, array $cmaps): string
    {
        if ($bytes === '') {
            return '';
        }

        if ($this->looksLikeUtf16Be($bytes)) {
            $out = '';
            $length = strlen($bytes);
            for ($i = 0; $i + 1 < $length; $i += 2) {
                $out .= $this->mappedCode(bin2hex(substr($bytes, $i, 2)), $cmaps, substr($bytes, $i, 2));
            }

            return $out;
        }

        if ($this->isMostlyPlainText($bytes)) {
            return $bytes;
        }

        $out = '';
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $out .= $this->mappedCode(sprintf('%02x', ord($bytes[$i])), $cmaps, $bytes[$i]);
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $cmaps
     */
    private function decodeHexText(string $hex, array $cmaps): string
    {
        $chunk = '';
        $length = strlen($hex);
        for ($k = 0; $k < $length; $k += 2) {
            $c4 = $k + 4 <= $length ? substr($hex, $k, 4) : '';
            $c2 = substr($hex, $k, 2);
            if ($c4 !== '' && isset($cmaps[strtolower($c4)])) {
                $chunk .= $cmaps[strtolower($c4)];
                $k += 2;

                continue;
            }

            $bin = @hex2bin($c2);
            $chunk .= $this->mappedCode($c2, $cmaps, $bin === false ? '' : $bin);
        }

        return $chunk;
    }

    /**
     * @param  array<string, string>  $cmaps
     */
    private function mappedCode(string $hex, array $cmaps, string $fallbackBytes): string
    {
        $hex = strtolower($hex);
        $padded = strlen($hex) === 2 ? '00'.$hex : $hex;
        if (isset($cmaps[$padded])) {
            return $cmaps[$padded];
        }
        if (isset($cmaps[$hex])) {
            return $cmaps[$hex];
        }

        if ($fallbackBytes === '') {
            return '';
        }

        if (strlen($fallbackBytes) === 2) {
            $decoded = @mb_convert_encoding($fallbackBytes, 'UTF-8', 'UTF-16BE');
            if (is_string($decoded) && preg_match('/[\p{L}\p{N}\s\.\,\-\@\:\/\(\)\+\#]/u', $decoded) === 1) {
                return $decoded;
            }
        }

        return ctype_print($fallbackBytes) ? $fallbackBytes : '';
    }

    private function isMostlyPlainText(string $bytes): bool
    {
        $alphaCount = preg_match_all('/[a-zA-Z0-9]/', $bytes);
        $totalNonSpace = preg_match_all('/\S/', $bytes);

        return $totalNonSpace > 0 && ($alphaCount / $totalNonSpace) >= 0.6;
    }

    private function looksLikeUtf16Be(string $bytes): bool
    {
        $length = strlen($bytes);
        if ($length < 2 || ($length % 2) !== 0) {
            return false;
        }

        $nulls = 0;
        $pairs = (int) ($length / 2);
        for ($i = 0; $i < $length; $i += 2) {
            if ($bytes[$i] === "\x00") {
                $nulls++;
            }
        }

        return ($nulls / $pairs) >= 0.4;
    }
}
