<?php

namespace App\Services;

class OcrEvaluationService
{
    public function evaluate(array $truth, array $predictions): array
    {
        $report = [];
        foreach ($truth as $key => $expected) {
            $actual = $predictions[$key] ?? null;
            $a = (string) ($actual ?? '');
            $b = (string) ($expected ?? '');
            $words = preg_split('/\s+/u', trim($b), -1, PREG_SPLIT_NO_EMPTY);
            $actualWords = preg_split('/\s+/u', trim($a), -1, PREG_SPLIT_NO_EMPTY);
            $report[$key] = ['exact_match' => $actual === $expected, 'character_error_rate' => levenshtein($a, $b) / max(1, strlen($b)), 'word_error_rate' => $this->distance($actualWords, $words) / max(1, count($words))];
        }

        return ['field_count' => count($report), 'exact_match_rate' => count(array_filter($report, fn ($f) => $f['exact_match'])) / max(1, count($report)), 'fields' => $report];
    }

    private function distance(array $a, array $b): int
    {
        $previous = range(0, count($b));
        foreach ($a as $i => $word) {
            $current = [$i + 1];
            foreach ($b as $j => $target) {
                $current[$j + 1] = min($current[$j] + 1, $previous[$j + 1] + 1, $previous[$j] + ($word === $target ? 0 : 1));
            }$previous = $current;
        }

return end($previous);
    }
}
