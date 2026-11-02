<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class ThemeService
{
    private $path;
    private $theme;

    public function __construct($theme)
    {
        if (!is_string($theme) || $theme === '' || $theme === '.' || $theme === '..' || strpbrk($theme, "/\\\0") !== false) {
            throw new \InvalidArgumentException('Invalid theme name');
        }
        $this->theme = $theme;
        $this->path = public_path('theme/');
    }

    public function init()
    {
        $themeConfigFile = $this->path . "{$this->theme}/config.json";
        if (!File::exists($themeConfigFile)) abort(500, "{$this->theme}主题不存在");
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!is_array($themeConfig) || !isset($themeConfig['configs']) || !is_array($themeConfig['configs'])) abort(500, "{$this->theme}主题配置文件有误");
        config(["theme.{$this->theme}" => $this->configuration($themeConfig)]);
    }

    public function configuration(array $themeConfig, ?array $settings = null): array
    {
        $settings ??= config('v2board', []);
        $configs = $themeConfig['configs'] ?? [];
        if (!is_array($configs)) throw new \InvalidArgumentException('Invalid theme config fields');
        $data = [];
        foreach ($configs as $config) {
            if (!is_array($config) || !isset($config['field_name']) || !is_string($config['field_name']) || $config['field_name'] === '') {
                abort(500, "{$this->theme}主题配置文件有误");
            }
            $data[$config['field_name']] = isset($config['default_value']) ? $config['default_value'] : '';
        }

        $saved = config("theme.{$this->theme}", []);
        if (!is_array($saved) || $saved === []) $saved = $settings['theme_' . $this->theme] ?? [];
        $path = $this->configurationPath();
        if (is_file($path)) {
            if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
            $saved = require $path;
            if (!is_array($saved)) throw new \InvalidArgumentException('Invalid saved theme configuration');
        }
        return array_replace($data, is_array($saved) ? $saved : []);
    }

    public function configurationPath(): string
    {
        return config_path('theme/' . $this->theme . '.php');
    }
}
