<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ThemeService;
use App\Services\XboardThemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class ThemeController extends Controller
{
    private $themes;
    private $path;

    public function __construct()
    {
        $this->path = $path = public_path('theme/');
        $this->themes = array_map(function ($item) use ($path) {
            return str_replace($path, '', $item);
        }, glob($path . '*'));
    }

    public function getThemes()
    {
        $themeConfigs = [];
        foreach ($this->themes as $theme) {
            $themeConfigFile = $this->path . "{$theme}/config.json";
            if (!File::exists($themeConfigFile)) continue;
            $themeConfig = json_decode(File::get($themeConfigFile), true);
            if (!isset($themeConfig['configs']) || !is_array($themeConfig)) continue;
            $themeConfigs[$theme] = $themeConfig;
            if (config("theme.{$theme}")) continue;
            $themeService = new ThemeService($theme);
            $themeService->init();
        }
        return response([
            'data' => [
                'themes' => $themeConfigs,
                'active' => config('v2board.frontend_theme', 'v2board')
            ]
        ]);
    }

    public function getThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required|in:' . join(',', $this->themes)
        ]);
        return response([
            'data' => app(XboardThemeService::class)->getConfig($payload['name'])
        ]);
    }

    public function saveThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => 'required|in:' . join(',', $this->themes),
            'config' => 'required|string'
        ]);
        $decoded = base64_decode($payload['config'], true);
        $payload['config'] = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($payload['config'])) abort(500, '参数有误');
        $themeConfigFile = public_path("theme/{$payload['name']}/config.json");
        if (!File::exists($themeConfigFile)) abort(500, '主题不存在');
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!isset($themeConfig['configs']) || !is_array($themeConfig)) abort(500, '主题配置文件有误');
        $validateFields = array_column($themeConfig['configs'], 'field_name');
        $config = [];
        foreach ($validateFields as $validateField) {
            $config[$validateField] = isset($payload['config'][$validateField]) ? $payload['config'][$validateField] : '';
        }

        app(XboardThemeService::class)->updateConfig($payload['name'], $config);

        return response([
            'data' => app(XboardThemeService::class)->getConfig($payload['name'])
        ]);
    }
}
