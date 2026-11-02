<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Knowledge;
use App\Models\User;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    private const VISIBLE_BODY_RECHECK_BATCH_SIZE = 25;
    private const VISIBLE_BODY_RECHECK_BYTES = 1048576;

    public function getCategory(Request $request)
    {
        $request->validate(['language' => 'nullable|string|max:32']);
        $categories = Knowledge::categoryNames(
            Knowledge::where('language', $request->input('language'))->where('show', 1),
            true
        );
        return response(['data' => $categories]);
    }

    public function fetch(Request $request)
    {
        $request->validate([
            'id' => 'nullable|integer|min:0|max:2147483647',
            'language' => 'nullable|string|max:32',
            'keyword' => 'nullable|string|max:255',
        ]);
        if ($request->input('id')) {
            $knowledge = Knowledge::where('id', $request->input('id'))
                ->where('show', 1)
                ->first();
            if (!$knowledge) abort(404, __('Article does not exist'));
            $knowledge = $knowledge->toArray();
            $user = User::find($request->user['id'], ['id', 'token', 'banned', 'transfer_enable', 'expired_at']);
            if (!$user) abort(500, __('The user does not exist'));
            $userService = new UserService();
            if (!$userService->isAvailable($user)) {
                $this->formatAccessData($knowledge['body']);
            }
            $knowledge['body'] = str_replace('{{siteName}}', config('v2board.app_name', 'V2Board'), $knowledge['body']);
            // Hidden blocks and token-only articles do not need a generated URL.
            if (str_contains($knowledge['body'], '{{subscribeUrl}}')
                || str_contains($knowledge['body'], '{{urlEncodeSubscribeUrl}}')
                || str_contains($knowledge['body'], '{{safeBase64SubscribeUrl}}')) {
                $subscribeUrl = Helper::getSubscribeUrl($user['token'], (int) $user['id']);
                $knowledge['body'] = str_replace('{{subscribeUrl}}', $subscribeUrl, $knowledge['body']);
                $knowledge['body'] = str_replace('{{urlEncodeSubscribeUrl}}', urlencode($subscribeUrl), $knowledge['body']);
                $knowledge['body'] = str_replace(
                    '{{safeBase64SubscribeUrl}}',
                    str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($subscribeUrl)),
                    $knowledge['body']
                );
            }
            $knowledge['body'] = str_replace('{{subscribeToken}}', $user['token'], $knowledge['body']);
            return response([
                'data' => $knowledge
            ]);
        }
        $builder = Knowledge::select(['id', 'category', 'title', 'updated_at'])
            ->where('language', $request->input('language'))
            ->where('show', 1)
            ->orderBy('sort', 'ASC')
            ->orderBy('id', 'ASC');
        $keyword = $request->input('keyword');
        $filterRestricted = false;
        if ($keyword !== null && $keyword !== '') {
            $user = User::find($request->user['id'], ['id', 'banned', 'transfer_enable', 'expired_at']);
            if (!$user) abort(500, __('The user does not exist'));
            $filterRestricted = !(new UserService())->isAvailable($user);
            $builder = $builder->where(function ($query) use ($keyword) {
                $query->where('title', 'LIKE', "%{$keyword}%")
                    ->orWhere('body', 'LIKE', "%{$keyword}%");
            });
            if ($filterRestricted) {
                $builder->selectRaw(
                    'CASE WHEN title LIKE ? THEN NULL WHEN body LIKE ? THEN body ELSE NULL END AS body',
                    ["%{$keyword}%", '%<!--access start-->%']
                );
            }
        }

        $knowledges = $builder->get();
        if ($filterRestricted) {
            $visibleBodies = [];
            foreach ($knowledges as $knowledge) {
                $body = $knowledge->body;
                unset($knowledge->body);
                if ($body === null) continue;
                $originalBody = $body;
                $this->formatAccessData($body);
                if ($body !== $originalBody) {
                    $visibleBodies[$knowledge->id] = $body;
                }
            }
            if ($visibleBodies) {
                $pattern = "%{$keyword}%";
                $visibleIds = [];
                // Bound each SQL payload, not the number of articles returned to the user.
                foreach ($this->visibleBodyBatches($visibleBodies) as $batch) {
                    $cases = [];
                    $bindings = [];
                    foreach ($batch as $id => $body) {
                        $cases[] = 'WHEN ? THEN ?';
                        array_push($bindings, $id, $body);
                    }
                    // ELSE body preserves the column's MySQL collation; PHP matching would not.
                    $bodyMatch = '(CASE id ' . implode(' ', $cases) . ' ELSE body END) LIKE ?';
                    $bindings[] = $pattern;
                    $matches = (clone $builder)->select('id')
                        ->whereIn('id', array_keys($batch))
                        ->where(function ($query) use ($pattern, $bodyMatch, $bindings) {
                            $query->where('title', 'LIKE', $pattern)->orWhereRaw($bodyMatch, $bindings);
                        })->pluck('id');
                    foreach ($matches as $id) $visibleIds[$id] = true;
                }
                $knowledges = $knowledges->filter(function ($knowledge) use ($visibleBodies, $visibleIds) {
                    return !array_key_exists($knowledge->id, $visibleBodies) || isset($visibleIds[$knowledge->id]);
                })->values();
            }
        }
        return response([
            'data' => $knowledges->groupBy('category')
        ]);
    }

    private function visibleBodyBatches(array $bodies): iterable
    {
        $batch = [];
        $bytes = 0;
        foreach ($bodies as $id => $body) {
            $size = strlen($body);
            if ($batch && (count($batch) >= self::VISIBLE_BODY_RECHECK_BATCH_SIZE
                || $bytes + $size > self::VISIBLE_BODY_RECHECK_BYTES)) {
                yield $batch;
                $batch = [];
                $bytes = 0;
            }
            // A single large article is kept intact, never truncated to fit a batch.
            $batch[$id] = $body;
            $bytes += $size;
        }
        if ($batch) yield $batch;
    }

    private function formatAccessData(&$body)
    {
        $parts = preg_split('/(<!--access start-->|<!--access end-->)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        $depth = 0;
        $visible = [];
        foreach ($parts as $part) {
            if ($part === '<!--access start-->') {
                if ($depth === 0) {
                    $visible[] = '<div class="v2board-no-access">'. __('You must have a valid subscription to view content in this area') .'</div>';
                }
                $depth++;
            } elseif ($part === '<!--access end-->' && $depth > 0) {
                $depth--;
            } elseif ($depth === 0) {
                $visible[] = $part;
            }
        }
        // An unclosed restricted block stays hidden rather than exposing its remainder.
        $body = implode('', $visible);
    }
}
