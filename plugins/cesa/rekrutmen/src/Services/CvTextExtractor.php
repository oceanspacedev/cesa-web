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

                    if (str_contains($uncompressed, 'beginbfchar')) {
                        if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $uncompressed, $bf)) {
                            foreach ($bf[1] as $bIdx => $src) {
                                if (count($cmaps) >= 1000) {
                                    break;
                                }
                                $decodedChar = @hex2bin($bf[2][$bIdx]);
                                if ($decodedChar !== false) {
                                    $cmaps[strtolower($src)] = @mb_convert_encoding($decodedChar, 'UTF-8', 'UTF-16BE');
                                }
                            }
                        }
                    }

                    if (str_contains($uncompressed, 'beginbfrange')) {
                        if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $uncompressed, $bfr)) {
                            foreach ($bfr[1] as $bIdx => $start) {
                                if (count($cmaps) >= 1000) {
                                    break;
                                }
                                $startCode = hexdec($start);
                                $endCode = hexdec($bfr[2][$bIdx]);
                                $targetStart = hexdec($bfr[3][$bIdx]);
                                if ($endCode < $startCode || ($endCode - $startCode) > 256) {
                                    continue;
                                }
                                for ($c = $startCode; $c <= $endCode; $c++) {
                                    if (count($cmaps) >= 1000) {
                                        break;
                                    }
                                    $src = sprintf('%0'.strlen($start).'x', $c);
                                    $tgt = sprintf('%04x', $targetStart + ($c - $startCode));
                                    $decodedChar = @hex2bin($tgt);
                                    if ($decodedChar !== false) {
                                        $cmaps[strtolower($src)] = @mb_convert_encoding($decodedChar, 'UTF-8', 'UTF-16BE');
                                    }
                                }
                            }
                        }
                    }
                }
            }

            unset($matches);

            foreach ($uncompressedStreams as $uncompressed) {
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $uncompressed, $textMatches)) {
                    $extractedText .= ' '.implode('', $textMatches[1]);
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $uncompressed, $arrayMatches)) {
                    foreach ($arrayMatches[1] as $arr) {
                        if (preg_match_all('/\((.*?)\)/s', $arr, $subMatches)) {
                            $extractedText .= ' '.implode('', $subMatches[1]);
                        }
                    }
                }
                if (preg_match_all('/<([0-9a-fA-F]{2,})>\s*Tj/s', $uncompressed, $hexMatches)) {
                    foreach ($hexMatches[1] as $hex) {
                        $chunk = '';
                        $len = strlen($hex);
                        for ($k = 0; $k < $len; $k += 2) {
                            $c4 = $k + 4 <= $len ? strtolower(substr($hex, $k, 4)) : '';
                            $c2 = strtolower(substr($hex, $k, 2));
                            if ($c4 && isset($cmaps[$c4])) {
                                $chunk .= $cmaps[$c4];
                                $k += 2;
                            } elseif (isset($cmaps[$c2])) {
                                $chunk .= $cmaps[$c2];
                            } else {
                                $bin = @hex2bin($c2);
                                if ($bin !== false && ctype_print($bin)) {
                                    $chunk .= $bin;
                                }
                            }
                        }
                        $extractedText .= ' '.$chunk;
                    }
                }
            }

            unset($uncompressedStreams, $cmaps);
        }

        $cleaned = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $extractedText);
        $cleaned = preg_replace('/[^\p{L}\p{N}\s\.\,\-\@\:\/\(\)\+\#]/u', ' ', $cleaned);
        $cleaned = trim(preg_replace('/\s+/', ' ', (string) $cleaned));

        if (! $this->isSensibleText($cleaned)) {
            return '';
        }

        return $cleaned;
    }
}
