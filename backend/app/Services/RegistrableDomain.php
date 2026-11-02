<?php

namespace App\Services;

class RegistrableDomain
{
    private array $rules = [];
    private array $wildcards = [];
    private array $exceptions = [];

    public function __construct(?string $listPath = null)
    {
        $contents = file_get_contents($listPath ?? resource_path('data/public_suffix_list.dat'));
        if ($contents === false) throw new \RuntimeException('公共后缀表不可读取');
        foreach (explode("\n", $contents) as $line) {
            $line = strtolower(trim($line));
            if ($line === '' || str_starts_with($line, '//')) continue;
            if (function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($line[0] === '!' ? substr($line, 1) : (str_starts_with($line, '*.') ? substr($line, 2) : $line));
                if ($ascii !== false) $line = ($line[0] === '!' ? '!' : (str_starts_with($line, '*.') ? '*.' : '')) . $ascii;
            }
            if ($line[0] === '!') $this->exceptions[substr($line, 1)] = true;
            elseif (str_starts_with($line, '*.')) $this->wildcards[substr($line, 2)] = true;
            else $this->rules[$line] = true;
        }
    }

    public function root(string $domain): string
    {
        $domain = strtolower(rtrim($domain, '.'));
        $labels = explode('.', $domain);
        $count = count($labels);
        if ($count < 2) return $domain;
        $suffixLabels = 1;
        for ($i = 0; $i < $count; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            $length = $count - $i;
            if (isset($this->exceptions[$candidate])) {
                $suffixLabels = $length - 1;
                break;
            }
            if (isset($this->rules[$candidate])) $suffixLabels = max($suffixLabels, $length);
            if ($i > 0 && isset($this->wildcards[$candidate])) $suffixLabels = max($suffixLabels, $length + 1);
        }
        return implode('.', array_slice($labels, -min($count, $suffixLabels + 1)));
    }
}
