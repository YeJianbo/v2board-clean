<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class V2boardInstall extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'v2board:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'v2board 安装';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $environmentPath = base_path('.env');
        $createdEnvironment = null;
        $schemaStarted = false;
        try {
            $this->info("__     ______  ____                      _  ");
            $this->info("\ \   / /___ \| __ )  ___   __ _ _ __ __| | ");
            $this->info(" \ \ / /  __) |  _ \ / _ \ / _` | '__/ _` | ");
            $this->info("  \ V /  / __/| |_) | (_) | (_| | | | (_| | ");
            $this->info("   \_/  |_____|____/ \___/ \__,_|_|  \__,_| ");
            if (\File::exists(base_path() . '/.env')) {
                $securePath = config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key'))));
                $this->info("访问 http(s)://你的站点/{$securePath} 进入管理面板，你可以在用户中心修改你的密码。");
                abort(500, '如需重新安装请删除目录下.env文件');
            }

            $values = [
                'APP_KEY' => 'base64:' . base64_encode(Encrypter::generateKey('AES-256-CBC')),
                'DB_HOST' => $this->ask('请输入数据库地址（默认:localhost）', 'localhost'),
                'DB_DATABASE' => $this->ask('请输入数据库名'),
                'DB_USERNAME' => $this->ask('请输入数据库用户名'),
                'DB_PASSWORD' => $this->ask('请输入数据库密码')
            ];
            $createdEnvironment = $this->saveEnvironment($environmentPath, $values);
            if (\Artisan::call('config:clear') !== 0) throw new \RuntimeException('清除配置缓存失败');
            config([
                'app.key' => $values['APP_KEY'], 'database.default' => 'mysql',
                'database.connections.mysql.host' => $values['DB_HOST'],
                'database.connections.mysql.database' => $values['DB_DATABASE'],
                'database.connections.mysql.username' => $values['DB_USERNAME'],
                'database.connections.mysql.password' => $values['DB_PASSWORD'],
            ]);
            DB::purge('mysql');
            try {
                $connection = DB::connection('mysql');
                $connection->getPdo();
            } catch (\Throwable $e) {
                throw new \RuntimeException('数据库连接失败，请检查地址、账号和权限。', 0, $e);
            }
            $schema = $connection->getSchemaBuilder();
            if ($schema->getAllTables() || $schema->getAllViews()) {
                throw new \RuntimeException('目标数据库非空，安装已停止。请使用独立空数据库；已有站点请走备份迁移流程。');
            }
            $file = \File::get(base_path() . '/database/install.sql');
            if (!$file) {
                abort(500, '数据库文件不存在');
            }
            $this->info('正在导入数据库请稍等...');
            $schemaStarted = true;
            try {
                // The bundled script contains comments and quoted SQL; do not split it on semicolons.
                if (!$connection->unprepared($file)) throw new \RuntimeException('数据库未接受安装脚本');
            } catch (\Throwable $e) {
                throw new \RuntimeException('数据库导入失败。可能已有部分表创建，请检查数据库错误日志并处理后重试。', 0, $e);
            }
            $this->info('数据库导入完成');
            if (\Artisan::call('config:cache') !== 0) throw new \RuntimeException('生成配置缓存失败');
            $email = '';
            while (!$email) {
                $email = $this->ask('请输入管理员邮箱?');
            }
            $password = Helper::guid(false);
            if (!$this->registerAdmin($email, $password)) {
                abort(500, '管理员账号注册失败，请重试');
            }

            $this->info('一切就绪');
            $this->info("管理员邮箱：{$email}");
            $this->info("管理员密码：{$password}");

            $defaultSecurePath = hash('crc32b', config('app.key'));
            $this->info("访问 http(s)://你的站点/{$defaultSecurePath} 进入管理面板，你可以在用户中心修改你的密码。");
            return 0;
        } catch (\Throwable $e) {
            // Before any DDL, only remove the untouched environment file created by this attempt.
            if (!$schemaStarted && $createdEnvironment !== null
                && is_file($environmentPath) && file_get_contents($environmentPath) === $createdEnvironment) {
                unlink($environmentPath);
            }
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function registerAdmin($email, $password)
    {
        $user = new User();
        $user->email = $email;
        if (strlen($password) < 8) {
            abort(500, '管理员密码长度最小为8位字符');
        }
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->is_admin = 1;
        return $user->save();
    }

    private function saveEnvironment(string $path, array $values): string
    {
        $contents = \File::get(base_path('.env.example'));
        foreach ($values as $key => $value) {
            $value = (string) $value;
            // Laravel coerces reserved strings and strips surrounding quotes after dotenv parsing.
            if (in_array(strtolower($value), ['true', '(true)', 'false', '(false)', 'null', '(null)', 'empty', '(empty)'], true)
                || preg_match('/\A([\'\"])(.*)\1\z/s', $value)) $value = '"' . $value . '"';
            $encoded = '"' . str_replace(['\\', '"', '$', "\r", "\n"], ['\\\\', '\\"', '\\$', '\\r', '\\n'], $value) . '"';
            $line = $key . '=' . $encoded;
            $contents = preg_replace_callback('/^' . preg_quote($key, '/') . '=[^\r\n]*/m',
                static fn () => $line, $contents, -1, $replacements);
            if ($replacements === 0) $contents .= "\n" . $line . "\n";
        }
        $file = @fopen($path, 'x');
        if ($file === false) throw new \RuntimeException('无法创建环境文件，文件可能已存在。');
        try {
            if (!chmod($path, 0600) || fwrite($file, $contents) !== strlen($contents) || !fflush($file)) {
                throw new \RuntimeException('写入环境文件失败，请检查目录权限。');
            }
        } catch (\Throwable $error) {
            fclose($file);
            unlink($path);
            throw $error;
        }
        fclose($file);
        return $contents;
    }
}
