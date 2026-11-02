<?php
namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\DomainTrafficService;
use App\Services\DomainTrafficPolicy;
use App\Services\DomainTrafficBlockService;
use App\Models\Machine;
use App\Models\ServerV2node;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DomainTrafficController extends Controller
{
    public function blockOptions(DomainTrafficBlockService $service)
    {
        return response()->json(['data' => $service->options()]);
    }

    public function block(Request $request, DomainTrafficBlockService $service)
    {
        $data = $request->validate(['domain' => 'required|string|max:253', 'machine_ids' => 'required|array|min:1|max:100',
            'machine_ids.*' => 'required|integer|min:1|distinct']);
        try {
            return response()->json(['data' => $service->block($data['domain'], $data['machine_ids'])]);
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }
    }
    public function collection()
    {
        $saved = DB::table('v2_domain_traffic_setting')->get();
        $byScope = [];
        foreach ($saved as $row) $byScope[$row->scope][(int) $row->target_id] = (bool) $row->enabled;
        $machines = Machine::query()->orderBy('sort')->orderBy('id')->get(['id', 'name']);
        $allNodes = ServerV2node::query()->orderBy('sort')->orderBy('id')
            ->get(['id', 'name', 'machine_id']);
        $machineIds = array_fill_keys($machines->pluck('id')->all(), true);
        [$boundNodes, $standalone] = $allNodes->partition(fn ($node) => isset($machineIds[(int) $node->machine_id]));
        $nodesByMachine = $boundNodes->groupBy('machine_id');
        return response()->json(['data' => [
            'master_enabled' => (bool) admin_setting('domain_traffic_enable', true),
            'standalone_nodes' => $standalone->map(fn ($node) => [
                'id' => (int) $node->id, 'name' => $node->name,
                'enabled' => $byScope['node'][$node->id] ?? true,
                'machine_id' => (int) $node->machine_id,
                'binding_missing' => (int) $node->machine_id > 0,
            ])->values()->all(),
            'machines' => $machines->map(fn ($machine) => [
                'id' => (int) $machine->id, 'name' => $machine->name,
                'enabled' => $byScope['machine'][$machine->id] ?? true,
                'nodes' => $nodesByMachine->get($machine->id, collect())->map(fn ($node) => [
                    'id' => (int) $node->id, 'name' => $node->name,
                    'enabled' => $byScope['node'][$node->id] ?? true,
                ])->values()->all(),
            ])->values()->all(),
        ]]);
    }

    public function setCollection(Request $request, DomainTrafficPolicy $policy)
    {
        $data = $request->validate(['scope' => 'required|in:machine,node', 'id' => 'required|integer|min:1', 'enabled' => 'required|boolean']);
        $model = $data['scope'] === 'machine' ? Machine::class : ServerV2node::class;
        abort_unless($model::whereKey($data['id'])->exists(), 404, '采集对象不存在');
        $policy->set($data['scope'], (int) $data['id'], (bool) $data['enabled']);
        return response()->json(['data' => ['scope' => $data['scope'], 'id' => (int) $data['id'], 'enabled' => (bool) $data['enabled']]]);
    }

    public function history(Request $request, DomainTrafficService $service)
    {
        $data = $request->validate(['domain' => 'required|string|max:253', 'days' => 'sometimes|integer|in:1,7,30', 'node_id' => 'nullable|integer|min:1',
            'start_at' => 'required_with:end_at|integer|min:1', 'end_at' => 'required_with:start_at|integer|min:1']);
        $domain = strtolower(rtrim(trim($data['domain']), '.'));
        abort_unless(filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && str_contains($domain, '.'), 422, '域名无效');
        if (isset($data['start_at'], $data['end_at'])) {
            $this->validateRange((int) $data['start_at'], (int) $data['end_at']);
            return response()->json(['data' => $service->historyRange($domain, (int) $data['start_at'], (int) $data['end_at'], isset($data['node_id']) ? (int) $data['node_id'] : null)]);
        }
        return response()->json(['data' => $service->history($domain, (int) ($data['days'] ?? 1), isset($data['node_id']) ? (int) $data['node_id'] : null)]);
    }
    public function settings(Request $request)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        admin_setting(['domain_traffic_enable' => (bool) $data['enabled']]);
        return response()->json(['data' => ['enabled' => (bool) $data['enabled']]]);
    }

    public function analyse(Request $request, \App\Services\DomainDirectAnalysis $service)
    {
        $data = $request->validate(['days' => 'sometimes|integer|in:1,7,30', 'node_id' => 'nullable|integer|min:1',
            'start_at' => 'required_with:end_at|integer|min:1', 'end_at' => 'required_with:start_at|integer|min:1']);
        if (isset($data['start_at'], $data['end_at'])) $this->validateRange((int) $data['start_at'], (int) $data['end_at']);
        $result = $service->analyse((int) ($data['days'] ?? 1), isset($data['node_id']) ? (int) $data['node_id'] : null,
            isset($data['start_at']) ? (int) $data['start_at'] : null, isset($data['end_at']) ? (int) $data['end_at'] : null);
        $token = (string) \Illuminate\Support\Str::uuid();
        Cache::put('domain-analysis:' . $token, $result + ['admin_id' => (int) $request->input('user.id')], 900);
        unset($result['fingerprint']);
        return response()->json(['data' => $result + ['token' => $token]]);
    }

    public function apply(Request $request, \App\Services\DomainDirectAnalysis $service)
    {
        $data = $request->validate(['token' => 'required|uuid', 'domains' => 'required|array|min:1|max:500', 'domains.*' => 'required|string|max:253']);
        $preview = Cache::get('domain-analysis:' . $data['token']);
        abort_unless($preview && $preview['admin_id'] === (int) $request->input('user.id'), 409, '分析已过期，请重新分析');
        $result = $service->apply($preview, $data['domains']);
        Cache::forget('domain-analysis:' . $data['token']);
        return response()->json(['data' => $result]);
    }

    public function index(Request $request, DomainTrafficService $service)
    {
        $data = $request->validate(['days' => 'sometimes|integer|in:1,7,30', 'search' => 'sometimes|nullable|string|max:253', 'node_id' => 'nullable|integer|min:1',
            'start_at' => 'required_with:end_at|integer|min:1', 'end_at' => 'required_with:start_at|integer|min:1']);
        $days = (int) ($data['days'] ?? 1);
        $search = strtolower(trim((string) ($data['search'] ?? '')));
        $nodeId = isset($data['node_id']) ? (int) $data['node_id'] : null;
        if (isset($data['start_at'], $data['end_at'])) {
            $startAt = (int) $data['start_at']; $endAt = (int) $data['end_at'];
            $this->validateRange($startAt, $endAt);
            $result = $service->cachedRankingRange($startAt, $endAt, $search, $nodeId);
        } else {
            $result = Cache::remember('domain-traffic:rank:v3:' . (int) floor(time() / 86400) . ':' . $days . ':' . ($nodeId ?? 'all') . ':' . sha1($search), 15,
                fn () => $service->ranking($days, $search, $nodeId));
        }
        $result['nodes'] = Cache::remember('domain-traffic:node-options', 15, function () {
            $ids = \Illuminate\Support\Facades\DB::table('v2_domain_traffic')->distinct()->pluck('node_id');
            $names = \App\Models\ServerV2node::whereIn('id', $ids)->pluck('name', 'id');
            return $ids->map(fn ($id) => ['id' => (int) $id, 'name' => $names[$id] ?? '已删除节点 #' . $id])->values()->all();
        });
        $result['enabled'] = (bool) admin_setting('domain_traffic_enable', true);
        $result['retention'] = \App\Services\TrafficRetention::settings();
        $result['lifetime_total'] = (int) Cache::remember('domain-traffic:lifetime:v1:'.($nodeId ?? 'all'), 15,
            fn () => app(\App\Services\TrafficRetention::class)->domainTotal($nodeId));
        return response()->json(['data' => $result]);
    }

    private function validateRange(int $startAt, int $endAt): void
    {
        $days = \App\Services\TrafficRetention::days('domain_daily');
        abort_unless($startAt < $endAt && $endAt <= time() + 3600 && $startAt >= time() - ($days + 1) * 86400
            && $endAt - $startAt <= ($days + 1) * 86400, 422, "时间范围须在保留的 {$days} 天内，且开始时间早于结束时间");
    }
}
