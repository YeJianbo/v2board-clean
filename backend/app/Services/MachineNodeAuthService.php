<?php

namespace App\Services;

use App\Models\Machine;
use Illuminate\Http\Request;

class MachineNodeAuthService
{
    public static function token(Request $request): string
    {
        $authorization = (string) $request->header('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) return trim(substr($authorization, 7));
        $token = $request->input('token');
        return is_string($token) ? $token : '';
    }

    public static function credential(Machine $machine): string
    {
        if (trim((string) $machine->api_token) === '') abort(401, 'Machine credential missing');
        $mac = hash_hmac('sha256', 'machine-node-api:v1:' . $machine->id . ':' . $machine->api_token, config('app.key'));
        return 'machine-v1.' . $machine->id . '.' . $mac;
    }

    public static function issue(Machine $machine): string
    {
        return self::credential($machine);
    }

    public static function authorize(Request $request, $node, string|callable $legacyToken): void
    {
        $token = self::token($request);
        if (!$node || !is_string($token) || $token === '') abort(401, 'Unauthorized node');
        $machineId = (int) ($node->machine_id ?? 0);
        $scoped = str_starts_with($token, 'machine-v1.');
        if ($scoped) {
            // A credential for another machine must not trigger a credential-table read.
            if ($machineId < 1 || preg_match('/^machine-v1\.' . $machineId . '\.[a-f0-9]{64}$/D', $token) !== 1) {
                abort(401, 'Invalid machine-scoped credential');
            }
        } else {
            $legacyToken = is_string($legacyToken) ? $legacyToken : $legacyToken();
            if (!is_string($legacyToken) || $legacyToken === '' || !hash_equals($legacyToken, $token)) abort(401, 'Invalid node credential');
        }
        $machine = $machineId > 0 ? Machine::query()->select(['id', 'api_token', 'node_token_scoped_at'])->find($machineId) : null;
        if ($machineId > 0 && !$machine) abort(401, 'Machine no longer exists');
        if ($scoped) {
            if (!$machine || !hash_equals(self::credential($machine), $token)) abort(401, 'Invalid machine-scoped credential');
            // Retire the legacy key only after a client actually uses its scoped key.
            if ((int) $machine->node_token_scoped_at === 0) {
                Machine::whereKey($machine->id)->where('node_token_scoped_at', 0)->update(['node_token_scoped_at' => time()]);
            }
            return;
        }
        if ($machine && (int) $machine->node_token_scoped_at > 0 && time() >= (int) $machine->node_token_scoped_at + 300) {
            abort(401, 'Machine-scoped credential required');
        }
    }
}
