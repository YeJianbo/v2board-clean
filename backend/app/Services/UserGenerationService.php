<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use App\Utils\Csv;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserGenerationService
{
    private UserService $users;

    public function __construct(UserService $users) { $this->users = $users; }

    public function specification(array $input, bool $legacy = false): array
    {
        $prefix = trim((string) ($input['email_prefix'] ?? ''));
        $suffix = trim((string) $input['email_suffix']);
        $bulk = $legacy ? $prefix === '' : !empty($input['generate_count']);
        $count = $bulk ? (int) $input['generate_count'] : 1;
        if ($count < 1 || $count > 500) throw ValidationException::withMessages(['generate_count' => '生成数量必须为1至500']);
        $identities = []; $seen = []; $collisions = 0;
        for ($i = 1; $i <= $count; $i++) {
            $local = $prefix !== '' ? ($bulk ? $prefix . '_' . $i : $prefix) : Helper::randomChar(6);
            $email = $local . '@' . $suffix;
            if (isset($seen[$email])) {
                if (++$collisions > 1000) throw new \RuntimeException('Unable to allocate unique emails');
                $i--; continue;
            }
            $validator = Validator::make(['email' => $email], ['email' => 'required|email:rfc|max:254']);
            if ($validator->fails()) throw ValidationException::withMessages(['email_prefix' => '邮箱前缀和后缀无法组成有效邮箱']);
            $seen[$email] = true;
            $identities[] = ['email' => $email, 'uuid' => Helper::guid(true), 'token' => Helper::guid()];
        }
        return ['input' => $input, 'legacy' => $legacy, 'bulk' => $bulk, 'identities' => $identities];
    }

    public function execute(array $spec, ?string $receipt = null, ?callable $progress = null): array
    {
        if ($receipt !== null && $this->committed($receipt)) return $this->recover($spec);
        $emails = array_column($spec['identities'], 'email');
        $duplicate = User::whereIn('email', $emails)->value('email');
        if ($duplicate !== null) throw ValidationException::withMessages(['email_prefix' => '邮箱 ' . $duplicate . ' 已存在于系统中']);
        $input = $spec['input'];
        $planId = (int) ($input['plan_id'] ?? 0);
        $trial = !$spec['legacy'] && !$planId;
        if ($trial) $planId = (int) admin_setting('try_out_plan_id', 0);
        $plan = $planId ? Plan::find($planId) : null;
        if (!$plan && !empty($input['plan_id'])) throw ValidationException::withMessages(['plan_id' => '订阅计划不存在']);
        $expiry = $input['expired_at'] ?? null;
        if ($trial && $plan) $expiry = time() + (int) admin_setting('try_out_hour', 1) * 3600;
        $context = ['plan' => $plan, 'expired_at' => $expiry, 'legacy' => $spec['legacy']];
        $prepared = [];
        // Hashing is intentionally outside the transaction and retains a unique salt per user.
        foreach ($spec['identities'] as $identity) {
            $user = $this->users->createUser(['email' => $identity['email'], 'password' => $input['password'] ?? null,
                'expired_at' => $expiry], $context);
            $user->uuid = $identity['uuid']; $user->token = $identity['token'];
            $prepared[] = $user;
            if ($progress) $progress(count($prepared));
        }
        $created = DB::transaction(function () use ($prepared, $plan, $expiry, $spec, $receipt): array {
            // Retry with unsaved clones, never with models left marked as persisted after a rollback.
            $created = array_map(static fn (User $user) => clone $user, $prepared);
            $currentPlan = $plan ? Plan::whereKey($plan->id)->sharedLock()->first() : null;
            if ($plan && !$currentPlan) throw ValidationException::withMessages(['plan_id' => '订阅计划不存在']);
            foreach ($created as $user) {
                if ($currentPlan) {
                    $this->users->applyResolvedPlan($user, $currentPlan, $expiry);
                    if ($spec['legacy']) unset($user->speed_limit);
                }
                if (!$user->save()) throw new \RuntimeException('User creation was rejected');
            }
            if ($receipt !== null) app(ProcessingBatchService::class)->record($receipt, 'user_generation');
            return $created;
        }, 3);
        return $this->report($spec, $created);
    }

    public function committed(string $receipt): bool
    {
        return DB::table('v2_processing_batch')->where('batch_id', $receipt)->where('stream', 'user_generation')->exists();
    }

    public function recover(array $spec): array
    {
        $users = User::whereIn('uuid', array_column($spec['identities'], 'uuid'))
            ->get(['id', 'email', 'uuid', 'token', 'created_at', 'expired_at'])->keyBy('uuid');
        $ordered = [];
        foreach ($spec['identities'] as $identity) {
            if (!isset($users[$identity['uuid']])) throw new \RuntimeException('Generated account changed after completion');
            $ordered[] = $users[$identity['uuid']];
        }
        return $this->report($spec, $ordered);
    }

    private function report(array $spec, array $users): array
    {
        $data = [];
        foreach ($users as $user) $data[] = [
            'email' => $user->email, 'password' => $spec['input']['password'] ?? $user->email,
            'expired_at' => $user->expired_at === null ? '长期有效' : date('Y-m-d H:i:s', $user->expired_at),
            'uuid' => $user->uuid, 'created_at' => date('Y-m-d H:i:s', $user->getRawOriginal('created_at')),
            'subscribe_url' => $this->subscriptionUrl($user),
        ];
        return ['bulk' => $spec['bulk'], 'data' => $data];
    }

    private function subscriptionUrl(User $user): string
    {
        try { return Helper::getSubscribeUrl($user->token, (int) $user->id); }
        catch (\Throwable $exception) {
            \Log::warning('Generated user subscription link unavailable', ['exception_type' => get_class($exception)]);
            // Account creation is already committed; a link failure must not invite duplicate creation.
            abort(503, '用户已生成，订阅链接暂时无法获取。请稍后在用户列表获取链接，不要重复生成。');
        }
    }

    public function csv(array $data)
    {
        return Csv::download('users.csv', static function ($handle) use ($data): void {
            Csv::writeRow($handle, ['账号', '密码', '过期时间', 'UUID', '创建时间', '订阅地址']);
            foreach ($data as $row) Csv::writeRow($handle, [Csv::text($row['email']), Csv::text($row['password']),
                $row['expired_at'], $row['uuid'], $row['created_at'], Csv::text($row['subscribe_url'])]);
        });
    }
}
