<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\XboardThemeService;
use Illuminate\Http\Request;

class ThemeController extends Controller
{
    private $themeService;

    public function __construct(XboardThemeService $themeService)
    {
        $this->themeService = $themeService;
    }

    /**
     * 上传新主题
     */
    public function upload(Request $request)
    {
        abort(403, '网页端不允许上传主题代码包，请通过独立可信发布流程更新');
    }

    /**
     * 删除主题
     */
    public function delete(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/']
        ]);
        $this->themeService->delete($payload['name']);
        return $this->success(true);
    }

    /**
     * 获取所有主题和其配置列
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getThemes()
    {
        $data = [
            'themes' => $this->themeService->getList(),
            'active' => $this->themeService->activeTheme()
        ];
        return $this->success($data);
    }

    /**
     * 切换主题
     */
    public function switchTheme(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/']
        ]);
        $this->themeService->switch($payload['name']);
        return $this->success(true);
    }

    /**
     * 获取主题配置
     */
    public function getThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/']
        ]);
        $data = $this->themeService->getConfig($payload['name']);
        return $this->success($data);
    }

    /**
     * 保存主题配置
     */
    public function saveThemeConfig(Request $request)
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/'],
            'config' => 'required|array'
        ]);
        $this->themeService->updateConfig($payload['name'], $payload['config']);
        $config = $this->themeService->getConfig($payload['name']);
        return $this->success($config);
    }
}
