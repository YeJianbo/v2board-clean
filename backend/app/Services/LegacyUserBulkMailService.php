<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class LegacyUserBulkMailService
{
    public function enqueue(Builder $selection, array $params): void
    {
        $connection = $selection->getConnection();
        $name = 'um_'.bin2hex(random_bytes(6));
        $table = $connection->getQueryGrammar()->wrapTable($name);
        $created = false; $completed = false; $queued = 0;
        try {
            $columns = $connection->getDriverName() === 'mysql'
                ? 'seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email TEXT NOT NULL'
                : 'seq INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL';
            $charset = $connection->getDriverName() === 'mysql' ? ' DEFAULT CHARACTER SET utf8mb4' : '';
            $connection->statement("CREATE TEMPORARY TABLE {$table} ({$columns}){$charset}"); $created = true;
            // Sequence numbers retain the legacy sort order without a buffered full-user cursor.
            $connection->table($name)->insertUsing(['email'], (clone $selection)->select('email')->toBase());
            $connection->table($name)->select(['seq', 'email'])->orderBy('seq')->chunkById(1000, function ($rows) use ($params, &$queued): void {
                foreach ($rows as $row) {
                    Bus::dispatch(new SendEmailJob(['email' => $row->email] + $params, 'send_email_mass'));
                    $queued++;
                }
            }, 'seq');
            $completed = true;
        } catch (\Throwable $exception) {
            Log::warning('Legacy bulk mail enqueue incomplete', ['exception_type' => get_class($exception), 'queued_count' => $queued]);
            abort(500, $queued > 0
                ? "至少 {$queued} 封邮件已加入队列，后续入队未完成。请检查队列，不要直接重发整批。"
                : '邮件入队未完成，请检查队列后再决定是否重试。');
        } finally {
            if ($created) {
                $temporary = $connection->getDriverName() === 'mysql' ? 'TEMPORARY ' : '';
                try { $connection->statement("DROP {$temporary}TABLE {$table}"); }
                catch (\Throwable $exception) {
                    // A cleanup error must not encourage re-sending jobs already accepted by the queue.
                    Log::warning('Legacy mail recipient cleanup failed', ['exception_type' => get_class($exception), 'enqueue_completed' => $completed]);
                }
            }
        }
    }
}
