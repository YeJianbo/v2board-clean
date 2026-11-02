<?php

namespace App\Models;

use App\Services\AdminSettingsFiles;
use App\Services\AdminSettingsWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

class SubscribeTemplate
{
    private const DEFINITIONS = [
        'singbox' => [
            'format' => 'json',
            'fallbacks' => ['custom.sing-box.json', 'default.sing-box.json'],
        ],
        'clash' => [
            'format' => 'yaml',
            'fallbacks' => ['custom.clash.yaml', 'default.clash.yaml'],
        ],
        'clashmeta' => [
            'format' => 'yaml',
            'fallbacks' => ['custom2.clash.yaml', 'default.clash.yaml'],
        ],
        'clashverge' => [
            'format' => 'yaml',
            'template_fallback' => 'clashmeta',
            'fallbacks' => ['custom2.clash.yaml', 'default.clash.yaml'],
        ],
        'stash' => [
            'format' => 'yaml',
            'fallbacks' => ['custom.stash.yaml', 'default.stash.yaml'],
        ],
        'surge' => [
            'format' => 'text',
            'fallbacks' => ['custom.surge.conf', 'default.surge.conf'],
        ],
        'surfboard' => [
            'format' => 'text',
            'fallbacks' => ['custom.surfboard.conf', 'default.surfboard.conf'],
        ],
    ];

    public static function setContent(string $name, ?string $content): void
    {
        if (!isset(self::DEFINITIONS[$name])) {
            return;
        }

        app(AdminSettingsWriter::class)->save([], self::filesForSave([$name => $content], []));
    }

    public static function getContent(string $name): string
    {
        if (!isset(self::DEFINITIONS[$name])) {
            return '';
        }

        $path = self::path($name);
        if (File::exists($path)) {
            $content = File::get($path);
            if (trim($content) !== '') {
                return $content;
            }
        }

        $templateFallback = self::DEFINITIONS[$name]['template_fallback'] ?? null;
        if ($templateFallback) {
            $fallbackTemplatePath = self::path($templateFallback);
            if (File::exists($fallbackTemplatePath)) {
                $content = File::get($fallbackTemplatePath);
                if (trim($content) !== '') {
                    return $content;
                }
            }
        }

        foreach (self::DEFINITIONS[$name]['fallbacks'] as $filename) {
            $fallbackPath = resource_path('rules/' . $filename);
            if (File::exists($fallbackPath)) {
                return File::get($fallbackPath);
            }
        }

        return '';
    }

    public static function parseYaml(string $name): array
    {
        $content = self::getContent($name);
        $parsed = Cache::remember(self::parsedCacheKey('yaml', $name, $content), 3600, static function () use ($name, $content) {
            $config = Yaml::parse($content);

            if (!is_array($config)) {
                throw new InvalidArgumentException("{$name} 订阅模板必须是有效的 YAML 对象");
            }

            return $config;
        });

        $baseUrl = rtrim((string) config('v2board.app_url'), '/');
        $providers = $parsed['rule-providers'] ?? [];
        $usesPanelRules = false;
        foreach ($providers as $key => $provider) {
            $url = $provider['url'] ?? '';
            if (is_string($url) && strpos($url, 'https://panel.invalid/') === 0) {
                if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    throw new InvalidArgumentException('Configure the panel URL before generating this subscription');
                }
                $parsed['rule-providers'][$key]['url'] = $baseUrl . substr($url, strlen('https://panel.invalid'));
                $usesPanelRules = true;
            }
        }
        $host = $usesPanelRules ? parse_url($baseUrl, PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            if (isset($parsed['dns']['fake-ip-filter']) && is_array($parsed['dns']['fake-ip-filter'])) {
                $parsed['dns']['fake-ip-filter'][] = $host;
            }
            $rule = 'RULE-SET,PanelCoreSite,🐻 PanelCore';
            $position = array_search($rule, $parsed['rules'] ?? [], true);
            if ($position !== false) {
                array_splice($parsed['rules'], $position, 0, ['DOMAIN,' . $host . ',🐻 PanelCore']);
            }
        }
        return $parsed;
    }

    public static function parseJson(string $name): array
    {
        $content = self::getContent($name);
        return Cache::remember(self::parsedCacheKey('json', $name, $content), 3600, static function () use ($name, $content) {
            $config = json_decode($content, true);
            if (!is_array($config) || json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException("{$name} 订阅模板必须是有效的 JSON 对象");
            }

            return $config;
        });
    }

    public static function validateContent(string $name, string $content): void
    {
        if (!isset(self::DEFINITIONS[$name]) || trim($content) === '') {
            return;
        }

        $format = self::DEFINITIONS[$name]['format'];
        if ($format === 'json') {
            $parsed = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new InvalidArgumentException("{$name} JSON 格式错误：" . json_last_error_msg());
            }
            if (!is_array($parsed)) throw new InvalidArgumentException("{$name} 订阅模板必须是有效的 JSON 对象");
            return;
        }

        if ($format === 'yaml') {
            try {
                $parsed = Yaml::parse($content);
            } catch (\Throwable $e) {
                throw new InvalidArgumentException("{$name} YAML 格式错误：" . $e->getMessage(), 0, $e);
            }
            if (!is_array($parsed)) {
                throw new InvalidArgumentException("{$name} 订阅模板必须是 YAML 对象");
            }
        }
    }

    public static function getRuleFiles(?array $names = null): array
    {
        if ($names === null) {
            $paths = File::glob(public_path('rules/clash/*.yaml'));
        } else {
            $paths = [];
            foreach ($names as $name) {
                if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9_-]+\z/', $name)) {
                    throw new InvalidArgumentException('不支持的子规则文件名');
                }
                $paths[$name] = self::ruleFilePath($name);
            }
        }
        $files = [];
        foreach ($paths as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) && !is_link($path) && File::isFile($path)) {
                $files[$name] = File::get($path);
            }
        }
        return $files;
    }

    public static function setRuleFiles(array $files): void
    {
        app(AdminSettingsWriter::class)->save([], self::filesForSave([], $files));
    }

    public static function filesForSave(array $templates, array $rules): AdminSettingsFiles
    {
        $files = new AdminSettingsFiles();
        foreach ($templates as $name => $content) {
            if (!isset(self::DEFINITIONS[$name])) continue;
            self::validateContent($name, (string) $content);
            $files->put(self::path($name), (string) $content);
        }
        foreach ($rules as $name => $content) {
            self::validateRuleFile((string) $name, (string) $content);
            $files->put(self::ruleFilePath((string) $name), (string) $content);
        }
        return $files;
    }

    public static function validateRuleFile(string $name, string $content): void
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]+\z/', $name) || is_link(self::ruleFilePath($name))) {
            throw new InvalidArgumentException("不支持的子规则文件：{$name}");
        }
        try {
            $parsed = Yaml::parse($content);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("{$name}.yaml 格式错误：" . $e->getMessage(), 0, $e);
        }
        if (!is_array($parsed) || !isset($parsed['payload']) || !is_array($parsed['payload'])) {
            throw new InvalidArgumentException("{$name}.yaml 必须包含 payload 数组");
        }
    }

    private static function path(string $name): string
    {
        return resource_path("rules/templates/{$name}.tpl");
    }

    private static function ruleFilePath(string $name): string
    {
        return public_path("rules/clash/{$name}.yaml");
    }

    private static function parsedCacheKey(string $format, string $name, string $content): string
    {
        return "subscribe_template:parsed:{$format}:{$name}:" . sha1($content);
    }
}
