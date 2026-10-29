<?php

namespace App\Support;

class JsonExtractor
{
    /** Pull a JSON object/array out of an LLM reply (handles ```json fences and chatter). */
    public static function extract(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $candidates = [$text];

        if (preg_match_all('/```(?:json)?\s*(.*?)```/s', $text, $m)) {
            array_push($candidates, ...$m[1]);
        }

        $start = strcspn($text, '{[');
        if ($start < strlen($text)) {
            $close = $text[$start] === '{' ? '}' : ']';
            $end = strrpos($text, $close);
            if ($end !== false && $end > $start) {
                $candidates[] = substr($text, $start, $end - $start + 1);
            }
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode(trim($candidate), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
