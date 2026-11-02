<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModelRelayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModelRelayController extends Controller
{
    public function quality(Request $request, \App\Services\ModelQualityService $service)
    {
        $d = $request->validate(['upstream_id' => 'required|integer|min:1', 'page' => 'nullable|integer|min:1',
            'summary' => 'sometimes|boolean']);
        $summary = !empty($d['summary']);
        $query = DB::table('v2_model_quality_run')->where('upstream_id', $d['upstream_id'])->orderByDesc('id');
        if ($summary) {
            $query->select(['id', 'upstream_id', 'model', 'protocol', 'source', 'status', 'score', 'tokens',
                'pricing', 'cost', 'cost_status', 'alert', 'started_at', 'finished_at', 'created_at', 'updated_at'])
                ->selectRaw("CASE WHEN results IS NULL OR results IN ('', '[]', 'null') THEN 0 ELSE 1 END AS has_results");
        }
        $runs = $query->paginate(10);
        foreach ($runs as $run) {
            $run->results = $summary ? [] : json_decode($run->results ?? '[]', true);
            if ($summary) $run->has_results = (bool) $run->has_results;
            unset($run->settings);
        }
        return $this->success($service->config($d['upstream_id']) + ['runs' => $runs]);
    }

    public function qualityDetail(Request $request)
    {
        $d = $request->validate(['upstream_id' => 'required|integer|min:1', 'run_id' => 'required|integer|min:1']);
        $run = DB::table('v2_model_quality_run')->where('upstream_id', $d['upstream_id'])->where('id', $d['run_id'])
            ->first(['id', 'upstream_id', 'status', 'results']);
        abort_unless($run, 404, '检测记录不存在');
        $results = json_decode($run->results ?? '[]', true);
        abort_unless(is_array($results), 500, '检测记录内容格式异常');
        $run->results = $results;
        return $this->success($run);
    }
    public function qualitySave(Request $request, \App\Services\ModelQualityService $service)
    {
        $d = $request->validate(['upstream_id' => 'required|integer|min:1', 'settings' => 'required|array']);
        return $this->success($service->save($d['upstream_id'], $d['settings']));
    }
    public function qualityRun(Request $request, \App\Services\ModelQualityService $service)
    {
        $d = $request->validate(['upstream_id' => 'required|integer|min:1']);
        return $this->success(['id' => $service->enqueue($d['upstream_id'])]);
    }
    public function qualityBaseline(Request $request, \App\Services\ModelQualityService $service)
    {
        $d = $request->validate(['run_id' => 'required|integer|min:1']); $service->baseline($d['run_id']);
        return $this->success(true);
    }
    public function status(ModelRelayService $service) { return $this->success($service->status()); }
    public function upstream(Request $request, ModelRelayService $service) { return $this->success(['id' => $service->upstream($request->all())]); }
    public function saveKey(Request $request, ModelRelayService $service) { return $this->success($service->saveKey($request->all())); }
    public function test(Request $request, ModelRelayService $service)
    {
        $data = $request->validate(['id' => 'required|integer|min:1']);
        return $this->success($service->testUpstream($data['id']));
    }
    public function keyAction(Request $request, ModelRelayService $service)
    {
        $d = $request->validate(['id' => 'required|integer', 'action' => 'required|in:rotate,revoke']);
        return $this->success(['key' => $service->keyAction($d['id'], $d['action'])]);
    }
    public function usage(Request $request)
    {
        $d = $request->validate(['machine_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1|max:1000000',
            'status' => 'nullable|in:pending,complete,rejected,interrupted,unknown,estimated', 'summary' => 'sometimes|boolean']);
        $query = DB::table('v2_model_usage')->orderByDesc('created_at')->orderByDesc('request_id');
        if (!empty($d['machine_id'])) $query->where('machine_id', $d['machine_id']);
        if (!empty($d['status'])) $query->where('status', $d['status']);
        if (!empty($d['summary'])) {
            $query->select(['request_id', 'machine_id', 'model', 'protocol', 'created_at', 'input_tokens',
                'output_tokens', 'charged', 'cache_read_tokens', 'cache_write_tokens', 'cost', 'cost_status',
                'status', 'http_status', 'duration_ms', 'error_code']);
        }
        return $this->success($query->paginate(25));
    }
}
