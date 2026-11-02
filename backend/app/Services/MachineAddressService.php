<?php

namespace App\Services;

use App\Models\Machine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineAddressService
{
    private const SERVER_TYPES = ['v2node', 'vless', 'vmess', 'trojan', 'hysteria', 'tuic', 'shadowsocks', 'anytls'];

    public static function publicAddress($value, int $family): string
    {
        $value = trim((string) $value, " \t\r\n[]");
        $flags = ($family === 4 ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (!filter_var($value, FILTER_VALIDATE_IP, $flags)) return '';
        if ($family === 4 && (ip2long($value) & 0xffc00000) === ip2long('100.64.0.0')) return '';
        if ($family === 4 && (ip2long($value) & 0xfffe0000) === ip2long('198.18.0.0')) return '';
        return inet_ntop(inet_pton($value));
    }

    public static function snapshot(array $report): array
    {
        $result = [];
        foreach ([4, 6] as $family) {
            foreach (['public_ipv' . $family, 'primary_ipv' . $family, 'ipv' . $family, 'ip'] as $key) {
                $ip = self::publicAddress($report[$key] ?? '', $family);
                if ($ip !== '') { $result[$family] = $ip; break; }
            }
        }
        return $result;
    }

    public function handleSnapshot(int $machineId, array $report, ?int $now = null): array
    {
        $addresses = self::snapshot($report);
        if (!$addresses) return [];
        $now = $now ?? time();
        $result = DB::transaction(function () use ($machineId, $addresses, $now) {
            $machine = Machine::whereKey($machineId)->lockForUpdate()->firstOrFail();
            $tracking = $machine->ip_tracking ?: [];
            $before = $tracking;
            $events = [];
            $updatedNodes = [];
            foreach ($addresses as $family => $ip) {
                $state = $tracking[$family] ?? [];
                $current = (string) ($state['current'] ?? '');
                if ($current !== $ip) {
                    if (($state['candidate'] ?? '') !== $ip) {
                        $tracking[$family] = array_merge($state, ['candidate' => $ip, 'first_seen' => $now]);
                        continue;
                    }
                    // Confirm across separate reports, not repeated requests in a burst.
                    if ($now - (int) ($state['first_seen'] ?? $now) < 30) continue;
                    $tracking[$family] = ['current' => $ip, 'confirmed_at' => $now];
                    if ($current !== '') $events[] = ['family' => $family, 'old' => $current, 'new' => $ip];
                } elseif (isset($state['candidate'])) {
                    $tracking[$family] = ['current' => $current, 'confirmed_at' => (int) ($state['confirmed_at'] ?? $now)];
                }
                if ((bool) $machine->{'auto_update_ipv' . $family}) {
                    $old = $current ?: self::publicAddress($machine->host, $family);
                    if ($old !== '' && $old !== $ip) {
                        $updatedNodes = array_merge($updatedNodes, $this->syncBoundAddresses($machine, $old, $ip));
                        if (self::publicAddress($machine->host, $family) === $old) $machine->host = $ip;
                    }
                }
            }
            if ($tracking !== $before) $machine->ip_tracking = $tracking;
            if ($machine->isDirty()) $machine->save();
            return ['machine' => $machine, 'changes' => $events, 'nodes' => $updatedNodes];
        }, 3);
        if ($result['nodes']) {
            foreach (self::SERVER_TYPES as $type) Cache::forget('servers_' . $type);
            Machine::forgetAdminFetchCache();
            Machine::forgetPublicStatusCache();
        }
        if ($result['changes'] && $result['machine']->ip_change_notify && !$result['machine']->isInMaintenance($now)) {
            app(TelegramNotificationService::class)->notifyMachineIpChanges($result['machine'], $result['changes'], count($result['nodes']));
        }
        return ['changes' => $result['changes'], 'nodes' => $result['nodes']];
    }

    private function syncBoundAddresses(Machine $machine, string $old, string $new): array
    {
        $tables = [];
        foreach (self::SERVER_TYPES as $type) {
            $table = 'v2_server_' . $type;
            if (Schema::hasTable($table)) $tables[$type] = Schema::getColumnListing($table);
        }
        $updated = $this->syncSubscriptionEntries($machine, $old, $new, $tables);
        $uniqueEntry = !Machine::where('host', $old)->where('id', '!=', $machine->id)->exists();
        foreach ($tables as $type => $columns) {
            $table = 'v2_server_' . $type;
            // Legacy AnyTLS only has subscription-entry bindings, not a runtime machine binding.
            if ($type === 'anytls' && !in_array('machine_id', $columns) && !in_array('relay_machine_id', $columns)) continue;
            $query = DB::table($table)->where('host', $old)->where(function ($query) use ($columns, $machine, $uniqueEntry) {
                if (in_array('machine_id', $columns)) $query->where('machine_id', $machine->id);
                else $query->whereRaw('1 = 0');
                if (in_array('relay_machine_id', $columns)) $query->orWhere('relay_machine_id', $machine->id);
                // Historical relay subscriptions have no relay_machine_id; an exact, unique
                // entry IP identifies them without touching domains or other entry addresses.
                if ($uniqueEntry && in_array('parent_id', $columns)) {
                    $query->orWhere(function ($q) use ($columns) {
                        $q->whereNotNull('parent_id')->where('parent_id', '>', 0);
                        if (in_array('relay_machine_id', $columns)) $q->whereNull('relay_machine_id');
                    });
                }
            });
            $ids = $query->orderBy('id')->lockForUpdate()->pluck('id')->all();
            if (!$ids) continue;
            $updatedAt = time();
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::table($table)->whereIn('id', $chunk)->update(['host' => $new, 'updated_at' => $updatedAt]);
            }
            foreach ($ids as $id) $updated[] = $type . ':' . $id;
        }
        // Update other machines' forwarding destinations, never this machine's listen ports.
        if ($uniqueEntry) {
            foreach (Machine::where('id', '!=', $machine->id)->whereNotNull('relay_rules')
                ->select(['id', 'relay_rules'])->lazyById(100) as $candidate) {
                if ($this->replaceRelayTargets($candidate->relay_rules, $old, $new) === null) continue;
                // Lock and re-read: the initial scan must not overwrite a newer rule edit.
                $relay = Machine::whereKey($candidate->id)->lockForUpdate()->first(['id', 'relay_rules']);
                if (!$relay) continue;
                $rules = $this->replaceRelayTargets($relay->relay_rules, $old, $new);
                if ($rules !== null) { $relay->relay_rules = $rules; $relay->save(); }
            }
        }
        return array_values(array_unique($updated));
    }

    private function syncSubscriptionEntries(Machine $machine, string $old, string $new, array $tables): array
    {
        if (!Schema::hasTable('v2_server_metadata')) return [];
        $columns = Schema::getColumnListing('v2_server_metadata');
        if (!in_array('entry_machine_id', $columns) || !in_array('entry_host', $columns)) return [];
        $family = str_contains($old, ':') ? 6 : 4;
        $updated = [];
        foreach ($tables as $type => $serverColumns) {
            if (!in_array('host', $serverColumns)) continue;
            $ids = [];
            $entries = DB::table('v2_server_metadata as metadata')
                ->join('v2_server_' . $type . ' as server', 'server.id', '=', 'metadata.server_id')
                ->where('metadata.server_type', $type)->where('metadata.entry_machine_id', $machine->id)
                ->select(['metadata.id', 'metadata.server_id', 'metadata.entry_host', 'server.host'])
                ->lockForUpdate()->lazyById(100, 'metadata.id', 'id');
            foreach ($entries as $entry) {
                $effective = $entry->entry_host ?: $entry->host;
                if (self::publicAddress($effective, $family) !== $old) continue;
                $ids[] = $entry->id;
                $updated[] = $type . ':' . $entry->server_id;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                // Entry overrides never rewrite the landing node's host or ports.
                DB::table('v2_server_metadata')->whereIn('id', $chunk)
                    ->update(['entry_host' => $new, 'updated_at' => time()]);
            }
        }
        return $updated;
    }

    private function replaceRelayTargets(array $rules, string $old, string $new): ?array
    {
        $changed = false;
        foreach ($rules as &$rule) {
            if (!is_array($rule)) continue;
            if (trim((string) ($rule['target_host'] ?? ''), '[]') === $old) {
                $rule['target_host'] = $new;
                $changed = true;
            }
        }
        unset($rule);
        return $changed ? $rules : null;
    }
}
