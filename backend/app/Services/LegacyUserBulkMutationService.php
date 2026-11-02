<?php

namespace App\Services;

use App\Models\InviteCode;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class LegacyUserBulkMutationService
{
    public function ban(Builder $selection): void { $this->mutate($selection, false); }

    public function delete(Builder $selection): void { $this->mutate($selection, true); }

    private function mutate(Builder $selection, bool $delete): void
    {
        $connection = $selection->getConnection();
        $name = 'ub_'.bin2hex(random_bytes(6));
        $table = $connection->getQueryGrammar()->wrapTable($name);
        $created = false;
        $committed = false;
        try {
            // An immutable SQL snapshot prevents cleanup from changing the targets and keeps PHP memory bounded.
            $connection->statement("CREATE TEMPORARY TABLE {$table} (id BIGINT NOT NULL PRIMARY KEY)");
            $created = true;
            $connection->table($name)->insertUsing(['id'], (clone $selection)->reorder()->select('id')->toBase());
            $connection->transaction(function () use ($connection, $name, $delete): void {
                $connection->table($name)->select('id')->orderBy('id')->chunkById(200, function ($rows) use ($connection, $delete): void {
                    $ids = $rows->pluck('id')->map(static fn ($id) => (int) $id)->all();
                    AuthService::revokeUsers($ids, function ($revoke) use ($connection, $ids, $delete): bool {
                        $database = $connection->getName();
                        $active = User::on($database)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id')->all();
                        if (!$active) return $revoke();
                        if ($delete) {
                            Order::on($database)->whereIn('user_id', $active)->delete();
                            InviteCode::on($database)->whereIn('user_id', $active)->delete();
                            TicketMessage::on($database)->whereIn('ticket_id', Ticket::on($database)->whereIn('user_id', $active)->select('id'))->delete();
                            Ticket::on($database)->whereIn('user_id', $active)->delete();
                            $deleted = User::on($database)->whereIn('id', $active)->delete();
                            if ($deleted !== count($active) || User::on($database)->whereIn('id', $active)->exists()) {
                                throw new \RuntimeException('Legacy bulk user deletion rejected');
                            }
                        } else {
                            User::on($database)->whereIn('id', $active)->update(['banned' => 1]);
                            if (User::on($database)->whereIn('id', $active)->where('banned', 1)->count() !== count($active)) {
                                throw new \RuntimeException('Legacy bulk user ban rejected');
                            }
                        }
                        // Database row locks remain held until the whole operation commits.
                        return $revoke();
                    });
                });
                if ($delete) {
                    // Do not lock users in a later auth batch while cleaning their invitation references.
                    User::on($connection->getName())->whereIn('invite_user_id', $connection->table($name)->select('id'))
                        ->update(['invite_user_id' => null]);
                }
            }, 3);
            $committed = true;
        } finally {
            if ($created) {
                $temporary = $connection->getDriverName() === 'mysql' ? 'TEMPORARY ' : '';
                try { $connection->statement("DROP {$temporary}TABLE {$table}"); }
                catch (\Throwable $exception) {
                    if (!$committed) throw $exception;
                    // Temporary tables disappear with the connection; committed user changes remain successful.
                    \Log::warning('Legacy user selection cleanup failed', ['exception_type' => get_class($exception)]);
                }
            }
        }
    }
}
