<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginOperationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PluginController extends Controller
{
    protected PluginManager $pluginManager;
    protected PluginConfigService $configService;

    public function __construct(
        PluginManager $pluginManager,
        PluginConfigService $configService
    ) {
        $this->pluginManager = $pluginManager;
        $this->configService = $configService;
    }

    /**
     * 获取所有插件类型
     */
    public function types()
    {
        return response()->json([
            'data' => [
                [
                    'label' => '功能插件',
                    'value' => 'feature',
                ],
                [
                    'label' => '支付插件',
                    'value' => 'payment',
                ]
            ]
        ]);
    }

    /**
     * 获取插件列表
     */
    public function index(Request $request)
    {
        $params = $request->validate(['summary' => 'sometimes|boolean']);
        $summary = !empty($params['summary']);
        $this->pluginManager->initializeEnabledPlugins();
        $initializationErrors = $this->pluginManager->getInitializationErrors();
        $databasePlugins = collect();
        if (Schema::hasTable((new Plugin())->getTable())) {
            $databasePlugins = Plugin::query()->get($summary ? ['code', 'name', 'version', 'type', 'is_enabled'] : ['*'])->keyBy('code');
        }

        $plugins = collect();
        foreach ($this->pluginManager->getPluginPaths() as $basePath) {
            if (!File::isDirectory($basePath)) {
                continue;
            }

            foreach (File::directories($basePath) as $directory) {
                $configPath = $directory . '/config.json';
                if (!File::isFile($configPath)) {
                    continue;
                }

                $manifest = json_decode(File::get($configPath), true);
                $code = is_array($manifest) ? ($manifest['code'] ?? null) : null;
                if (!is_string($code) || !preg_match(PluginManager::CODE_PATTERN, $code)) {
                    continue;
                }

                $installed = $databasePlugins->get($code);
                $isCore = $this->pluginManager->isCorePlugin($code);
                $readmePath = collect(['README.md', 'README.MD', 'readme.md'])
                    ->map(fn($name) => $directory . '/' . $name)
                    ->first(fn($path) => File::isFile($path));
                $currentVersion = (string) ($installed?->version ?? '');
                $availableVersion = (string) ($manifest['version'] ?? '');
                $entryFileExists = File::isFile($directory . '/Plugin.php');
                $runtimeError = $initializationErrors[$code] ?? null;
                $runtimeStatus = !$installed
                    ? 'available'
                    : (!($installed->is_enabled ?? false)
                        ? 'disabled'
                        : (($entryFileExists && !$runtimeError) ? 'running' : 'error'));

                $plugins->put($code, [
                    'code' => $code,
                    'name' => $manifest['name'] ?? $installed?->name ?? $code,
                    'version' => $availableVersion ?: ($currentVersion ?: null),
                    'installed_version' => $currentVersion ?: null,
                    'author' => $manifest['author'] ?? null,
                    'type' => $manifest['type'] ?? $installed?->type ?? Plugin::TYPE_FEATURE,
                    'description' => $manifest['description'] ?? null,
                    'is_installed' => (bool) $installed,
                    'is_enabled' => (bool) ($installed?->is_enabled ?? false),
                    'is_protected' => $isCore,
                    'can_be_deleted' => !$isCore && !($installed?->is_enabled ?? false),
                    'need_upgrade' => $installed
                        && $availableVersion !== ''
                        && version_compare($availableVersion, $currentVersion, '>'),
                    'runtime_status' => $runtimeStatus,
                    'runtime_error' => $runtimeError ?: ($entryFileExists ? null : '缺少 Plugin.php 入口文件'),
                    ...($summary ? ['config_count' => count($manifest['config'] ?? [])] : ['config' => $this->configService->formatConfig(
                        $manifest['config'] ?? [],
                        is_array($installed?->config) ? $installed->config : []
                    )]),
                    'readme' => $readmePath ? $this->readReadme($readmePath) : '',
                ]);
            }
        }

        foreach ($databasePlugins as $code => $installed) {
            if ($plugins->has($code)) {
                continue;
            }
            $plugins->put($code, [
                'code' => $code,
                'name' => $installed->name ?: $code,
                'version' => $installed->version,
                'installed_version' => $installed->version,
                'author' => null,
                'type' => $installed->type ?: Plugin::TYPE_FEATURE,
                'description' => '插件文件不存在，请重新上传插件包',
                'is_installed' => true,
                'is_enabled' => (bool) $installed->is_enabled,
                'is_protected' => false,
                'can_be_deleted' => !(bool) $installed->is_enabled,
                'need_upgrade' => false,
                'runtime_status' => 'error',
                'runtime_error' => $initializationErrors[$code] ?? '插件目录或配置文件不存在',
                ...($summary ? ['config_count' => 0] : ['config' => []]),
                'readme' => '',
            ]);
        }

        $type = trim((string) $request->input('type', ''));
        $status = trim((string) $request->input('status', ''));
        $plugins = $plugins->values()->filter(function (array $plugin) use ($type, $status) {
            if ($type !== '' && $plugin['type'] !== $type) {
                return false;
            }

            $pluginStatus = !$plugin['is_installed']
                ? 'not_installed'
                : ($plugin['runtime_status'] === 'error'
                    ? 'error'
                    : ($plugin['is_enabled'] ? 'enabled' : 'disabled'));
            return $status === '' || $pluginStatus === $status;
        })->values();

        return response()->json(['data' => $plugins])->header('Cache-Control', 'no-store');
    }

    private function readReadme(string $path): string
    {
        // Read at most one extra UTF-8 character so the byte limit never splits it.
        $content = @file_get_contents($path, false, null, 0, 200004);
        if ($content === false) {
            return '';
        }
        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        return mb_strcut($content, 0, 200000, 'UTF-8');
    }

    /**
     * 安装插件
     */
    public function install(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:' . PluginManager::CODE_PATTERN]
        ]);

        try {
            $this->pluginManager->install($request->input('code'));
            return response()->json([
                'message' => '插件安装成功'
            ]);
        } catch (\Throwable $e) {
            return $this->operationFailure($e, '插件安装失败');
        }
    }

    /**
     * 卸载插件
     */
    public function uninstall(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:' . PluginManager::CODE_PATTERN]
        ]);

        $code = $request->input('code');
        // $plugin = Plugin::where('code', $code)->first();
        // if ($plugin && $plugin->is_enabled) {
        //     return response()->json([
        //         'message' => '请先禁用插件后再卸载'
        //     ], 400);
        // }

        try {
            $this->pluginManager->uninstall($code);
            return response()->json([
                'message' => '插件卸载成功'
            ]);
        } catch (\Throwable $e) {
            return $this->operationFailure($e, '插件卸载失败');
        }
    }

    /**
     * 升级插件
     */
    public function upgrade(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:' . PluginManager::CODE_PATTERN],
        ]);
        try {
            $this->pluginManager->update($request->input('code'));
            return response()->json([
                'message' => '插件升级成功'
            ]);
        } catch (\Throwable $e) {
            return $this->operationFailure($e, '插件升级失败');
        }
    }

    /**
     * 启用插件
     */
    public function enable(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:' . PluginManager::CODE_PATTERN]
        ]);

        try {
            $this->pluginManager->enable($request->input('code'));
            return response()->json([
                'message' => '插件启用成功'
            ]);
        } catch (\Throwable $e) {
            return $this->operationFailure($e, '插件启用失败');
        }
    }

    /**
     * 禁用插件
     */
    public function disable(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:' . PluginManager::CODE_PATTERN]
        ]);

        try {
            $this->pluginManager->disable($request->input('code'));
            return response()->json([
                'message' => '插件禁用成功'
            ]);
        } catch (\Throwable $e) {
            return $this->operationFailure($e, '插件禁用失败');
        }

    }

    /**
     * 获取插件配置
     */
    public function getConfig(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:' . PluginManager::CODE_PATTERN]
        ]);

        try {
            if (!Schema::hasTable((new \App\Models\Plugin())->getTable())) {
                return response()->json([
                    'message' => '插件表未初始化'
                ], 400);
            }
            $config = $this->configService->getConfig($request->input('code'));
            return response()->json([
                'data' => $config
            ])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return $this->operationFailure($e, '获取配置失败');
        }
    }

    /**
     * 更新插件配置
     */
    public function updateConfig(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:' . PluginManager::CODE_PATTERN],
            'config' => 'required|array'
        ]);

        try {
            $this->configService->updateConfig(
                $request->input('code'),
                $request->input('config')
            );

            return response()->json([
                'message' => '配置更新成功'
            ]);
        } catch (\Throwable $e) {
            return $this->operationFailure($e, '配置更新失败');
        }
    }

    /**
     * 上传插件
     */
    public function upload(Request $request)
    {
        abort(403, '网页端不允许上传插件代码包，请通过独立可信发布流程更新');
    }

    /**
     * 删除插件
     */
    public function delete(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'regex:' . PluginManager::CODE_PATTERN]
        ]);

        $code = $request->input('code');

        // 检查是否为核心插件
        if ($this->pluginManager->isCorePlugin($code)) {
            return response()->json([
                'message' => '该插件为系统核心插件，不允许删除'
            ], 403);
        }

        try {
            $this->pluginManager->delete($code);
            return response()->json([
                'message' => '插件删除成功'
            ]);
        } catch (\Throwable $e) {
            return $this->operationFailure($e, '插件删除失败');
        }
    }

    private function operationFailure(\Throwable $error, string $message)
    {
        if ($error instanceof PluginOperationException) {
            return response()->json(['message' => $message . '：' . $error->getMessage()], 400);
        }
        Log::channel('daily')->error($message, ['exception' => $error]);
        return response()->json(['message' => $message . '，请检查服务器日志'], 500);
    }
}
