<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Http\UploadedFile;
use Exception;
use ZipArchive;

class XboardThemeService
{
    private const SYSTEM_THEME_DIR = 'theme/';
    private const USER_THEME_DIR = '/storage/theme/';
    private const CONFIG_FILE = 'config.json';
    private const SETTING_PREFIX = 'theme_';
    private const SYSTEM_THEMES = ['xboard', 'v2board', 'default'];

    public function __construct()
    {
        $this->registerThemeViewPaths();
    }

    /**
     * Register theme view paths
     */
    private function registerThemeViewPaths(): void
    {
        $systemPath = base_path(self::SYSTEM_THEME_DIR);
        if (File::exists($systemPath)) {
            View::addNamespace('theme', $systemPath);
        }

        $userPath = base_path(self::USER_THEME_DIR);
        if (File::exists($userPath)) {
            View::prependNamespace('theme', $userPath);
        }
    }

    /**
     * Get theme view path
     */
    public function getThemeViewPath(string $theme): ?string
    {
        $themePath = $this->getThemePath($theme);
        if (!$themePath) {
            return null;
        }
        return $themePath . '/dashboard.blade.php';
    }

    /**
     * Get all available themes
     */
    public function getList(): array
    {
        $publicPath = public_path('theme');
        $themes = File::isDirectory($publicPath) ? $this->getThemesFromPath($publicPath, false) : [];

        // 获取系统主题
        $systemPath = base_path(self::SYSTEM_THEME_DIR);
        if (File::exists($systemPath)) {
            $themes = array_merge($themes, $this->getThemesFromPath($systemPath, false));
        }

        // 获取用户主题
        $userPath = base_path(self::USER_THEME_DIR);
        if (File::exists($userPath)) {
            $themes = array_merge($themes, $this->getThemesFromPath($userPath, true));
        }

        return $themes;
    }

    /**
     * Get themes from specified path
     */
    private function getThemesFromPath(string $path, bool $canDelete): array
    {
        return collect(File::directories($path))
            ->mapWithKeys(function ($dir) use ($canDelete) {
                $name = basename($dir);
                if (
                    !File::exists($dir . '/' . self::CONFIG_FILE) ||
                    !File::exists($dir . '/dashboard.blade.php')
                ) {
                    return [];
                }
                $config = $this->readConfigFile($name);
                if (!$config) {
                    return [];
                }

                $config['can_delete'] = $canDelete && $name !== $this->activeTheme() && $name !== admin_setting('current_theme');
                $config['is_system'] = !$canDelete;
                return [$name => $config];
            })->toArray();
    }

    /**
     * Upload new theme
     */
    public function upload(UploadedFile $file): bool
    {
        $zip = new ZipArchive;
        $tmpPath = storage_path('tmp/theme-' . bin2hex(random_bytes(12)));
        $opened = false;
        try {
            if ($zip->open($file->path()) !== true) {
                throw new Exception('Invalid theme package');
            }
            $opened = true;
            $root = $this->packageRoot($zip);
            if (!$zip->extractTo($tmpPath)) throw new Exception('Failed to extract theme package');
            $zip->close();
            $opened = false;
            $sourcePath = $tmpPath . ($root === '' ? '' : '/' . $root);
            $config = json_decode(File::get($sourcePath . '/' . self::CONFIG_FILE), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($config) || !is_string($config['name'] ?? null)) throw new \InvalidArgumentException('Theme name not configured');
            $theme = $config['name'];
            $this->assertThemeName($theme);
            if ($this->isSystemTheme($theme)) throw new \InvalidArgumentException('Cannot upload theme with same name as system theme');
            $defaults = $this->configurationDefaults($config);
            $newVersion = $config['version'] ?? '0.0.0';
            if (!is_string($newVersion)) throw new \InvalidArgumentException('Invalid theme version');
            app(AdminSettingsWriter::class)->update(function (array $settings) use ($theme, $sourcePath, $newVersion, $defaults) {
                $targetPath = base_path(self::USER_THEME_DIR . $theme);
                if (is_dir($targetPath)) {
                    $oldFile = $targetPath . '/' . self::CONFIG_FILE;
                    $oldConfig = is_file($oldFile) ? json_decode(File::get($oldFile), true) : null;
                    if (!is_array($oldConfig)) throw new \InvalidArgumentException('Existing theme missing valid config file');
                    if (!version_compare($newVersion, (string) ($oldConfig['version'] ?? '0.0.0'), '>')) {
                        throw new \InvalidArgumentException('Theme exists and not a newer version');
                    }
                }
                $files = new AdminSettingsFiles();
                $files->replaceDirectory($sourcePath, $targetPath);
                $publicPath = public_path('theme/' . $theme);
                if (is_dir($publicPath) || ($settings['frontend_theme'] ?? 'default') === $theme || ($settings['current_theme'] ?? null) === $theme) {
                    $files->replaceDirectory($sourcePath, $publicPath);
                }
                $saved = $settings[self::SETTING_PREFIX . $theme] ?? [];
                return [[self::SETTING_PREFIX . $theme => array_replace($defaults, is_array($saved) ? $saved : [])], $files];
            });
            return true;
        } finally {
            if ($opened) $zip->close();
            if (File::exists($tmpPath)) {
                File::deleteDirectory($tmpPath);
            }
        }
    }

    /**
     * Switch theme
     */
    public function switch(string|null $theme): bool
    {
        if ($theme === null) {
            return true;
        }

        try {
            $files = new AdminSettingsFiles();
            $this->addSwitchFiles($theme, $files);
            app(AdminSettingsWriter::class)->save(['current_theme' => $theme, 'frontend_theme' => $theme], $files);
            return true;

        } catch (Exception $e) {
            Log::error('Theme switch failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function addSwitchFiles(string $theme, AdminSettingsFiles $files): void
    {
        $this->assertThemeName($theme);
        $themePath = $this->getThemePath($theme);
        if (!$themePath) throw new \InvalidArgumentException('Theme not found');
        if (!File::isFile($this->getThemeViewPath($theme))) throw new \InvalidArgumentException('Theme view file not found');
        // Retain old assets; only files supplied by the selected theme are replaced.
        $targetPath = public_path('theme/' . $theme);
        if (realpath($themePath) !== realpath($targetPath)) $files->copyDirectory($themePath, $targetPath);
    }

    /**
     * Delete theme
     */
    public function delete(string $theme): bool
    {
        try {
            $this->assertThemeName($theme);
            if ($this->isSystemTheme($theme)) throw new \InvalidArgumentException('System theme cannot be deleted');
            app(AdminSettingsWriter::class)->update(function (array $settings) use ($theme) {
                if ($theme === ($settings['frontend_theme'] ?? 'default') || $theme === ($settings['current_theme'] ?? null)) {
                    throw new \InvalidArgumentException('Current theme cannot be deleted');
                }
                $themePath = base_path(self::USER_THEME_DIR . $theme);
                if (!is_dir($themePath)) throw new \InvalidArgumentException('Theme not found');
                $files = new AdminSettingsFiles();
                $files->removeDirectory($themePath);
                $files->removeDirectory(public_path('theme/' . $theme));
                $files->remove((new ThemeService($theme))->configurationPath());
                return [[self::SETTING_PREFIX . $theme => null], $files];
            });
            return true;

        } catch (Exception $e) {
            Log::error('Theme deletion failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Check if theme exists
     */
    public function exists(string $theme): bool
    {
        return $this->getThemePath($theme) !== null;
    }

    /**
     * Get theme path
     */
    public function getThemePath(string $theme): ?string
    {
        $this->assertThemeName($theme);
        $systemPath = base_path(self::SYSTEM_THEME_DIR . $theme);
        if (File::isDirectory($systemPath)) {
            return $systemPath;
        }

        $userPath = base_path(self::USER_THEME_DIR . $theme);
        if (File::isDirectory($userPath)) {
            return $userPath;
        }

        $publicPath = public_path('theme/' . $theme);
        if (File::isDirectory($publicPath)) return $publicPath;

        return null;
    }

    public function activeTheme(): string
    {
        return (string) admin_setting('frontend_theme', 'default');
    }

    /**
     * Get theme config
     */
    public function getConfig(string $theme): ?array
    {
        $this->assertThemeName($theme);
        $schema = $this->readConfigFile($theme);
        if ($schema === null) return null;
        $this->configurationDefaults($schema);
        return (new ThemeService($theme))->configuration($schema);
    }

    /**
     * Update theme config
     */
    public function updateConfig(string $theme, array $config): bool
    {
        try {
            $this->assertThemeName($theme);
            app(AdminSettingsWriter::class)->update(function (array $settings) use ($theme, $config) {
                $files = new AdminSettingsFiles();
                $updated = $this->addConfigurationFiles($theme, $config, $settings, $files);
                return [[self::SETTING_PREFIX . $theme => $updated], $files];
            });
            return true;

        } catch (Exception $e) {
            Log::error('Config update failed', ['theme' => $theme, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Read theme config file
     */
    private function readConfigFile(string $theme): ?array
    {
        $themePath = $this->getThemePath($theme);
        if (!$themePath) {
            return null;
        }

        $file = $themePath . '/' . self::CONFIG_FILE;
        $config = File::exists($file) ? json_decode(File::get($file), true) : null;
        return is_array($config) ? $config : null;
    }

    /**
     * Clean up theme files including public directory
     */
    public function cleanupThemeFiles(string $theme): void
    {
        $this->assertThemeName($theme);
        try {
            $publicThemePath = public_path('theme/' . $theme);
            if (File::exists($publicThemePath)) {
                File::deleteDirectory($publicThemePath);
                Log::info('Cleaned up public theme files', ['theme' => $theme, 'path' => $publicThemePath]);
            }

            $cacheKey = "theme_{$theme}_assets";
            if (cache()->has($cacheKey)) {
                cache()->forget($cacheKey);
                Log::info('Cleaned up theme cache', ['theme' => $theme, 'cache_key' => $cacheKey]);
            }

        } catch (Exception $e) {
            Log::warning('Failed to cleanup theme files', [
                'theme' => $theme,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Force refresh current theme public files
     */
    public function refreshCurrentTheme(): bool
    {
        try {
            $currentTheme = $this->activeTheme();
            if (!$currentTheme) {
                return false;
            }

            $files = new AdminSettingsFiles();
            $this->addSwitchFiles($currentTheme, $files);
            app(AdminSettingsWriter::class)->save([], $files);

            Log::info('Refreshed current theme files', ['theme' => $currentTheme]);
            return true;

        } catch (Exception $e) {
            Log::error('Failed to refresh current theme', [
                'theme' => $currentTheme,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function assertThemeName(string $theme): void
    {
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/', $theme)) throw new \InvalidArgumentException('Invalid theme name');
    }

    public function addConfigurationFiles(string $theme, array $config, array $settings, AdminSettingsFiles $files): array
    {
        $schema = $this->readConfigFile($theme);
        if ($schema === null) throw new \InvalidArgumentException('Invalid theme config file');
        $defaults = $this->configurationDefaults($schema);
        $validConfig = array_intersect_key($config, $defaults);
        foreach ($schema['configs'] ?? [] as $field) {
            $name = $field['field_name'];
            $value = $validConfig[$name] ?? null;
            if (($field['field_type'] ?? '') === 'select' && $value !== null && $value !== ''
                && (!is_scalar($value) || !array_key_exists((string) $value, (array) ($field['select_options'] ?? [])))) {
                throw \Illuminate\Validation\ValidationException::withMessages([$name => '所选主题不支持这个选项']);
            }
        }
        $themeService = new ThemeService($theme);
        $updated = array_replace($themeService->configuration($schema, $settings), $validConfig);
        $files->put($themeService->configurationPath(), "<?php\nreturn " . var_export($updated, true) . ";\n");
        return $updated;
    }

    private function isSystemTheme(string $theme): bool
    {
        return in_array(strtolower($theme), self::SYSTEM_THEMES, true) || is_dir(base_path(self::SYSTEM_THEME_DIR . $theme));
    }

    private function configurationDefaults(array $config): array
    {
        $fields = $config['configs'] ?? [];
        if (!is_array($fields)) throw new \InvalidArgumentException('Invalid theme config fields');
        $defaults = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !is_string($field['field_name'] ?? null) || $field['field_name'] === '') throw new \InvalidArgumentException('Invalid theme config field');
            $defaults[$field['field_name']] = $field['default_value'] ?? '';
        }
        return $defaults;
    }

    private function packageRoot(ZipArchive $zip): string
    {
        if ($zip->numFiles < 1 || $zip->numFiles > 10000) throw new \InvalidArgumentException('Invalid theme package file count');
        $roots = [];
        $size = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            if ($entry === false) throw new \InvalidArgumentException('Invalid theme package entry');
            $name = $entry['name'];
            if ($name === '' || $name[0] === '/' || preg_match('/[\x00-\x1f\\\\:]/', $name)
                || array_intersect(explode('/', rtrim($name, '/')), ['.', '..', ''])) throw new \InvalidArgumentException('Invalid theme package path');
            $size += $entry['size'];
            if ($size > 100 * 1024 * 1024) throw new \InvalidArgumentException('Theme package expands beyond 100 MiB');
            if ($zip->getExternalAttributesIndex($i, $system, $attributes) && (($attributes >> 16) & 0170000) === 0120000) {
                throw new \InvalidArgumentException('Linked theme package entries are not supported');
            }
            if (basename($name) !== self::CONFIG_FILE) continue;
            $root = dirname($name) === '.' ? '' : dirname($name);
            if ($zip->locateName(($root === '' ? '' : $root . '/') . 'dashboard.blade.php') === false) continue;
            if ($entry['size'] > 1024 * 1024) throw new \InvalidArgumentException('Theme configuration exceeds 1 MiB');
            $roots[] = $root;
        }
        if (count($roots) !== 1) throw new \InvalidArgumentException('Theme package must contain one theme config and view');
        return $roots[0];
    }
}
