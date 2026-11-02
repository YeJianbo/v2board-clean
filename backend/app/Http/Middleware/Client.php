<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\User;
use App\Utils\Helper;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->input('token');
        if (empty($token)) {
            abort(403, 'token is null');
        }
        $token = Helper::resolveSubscribeToken($token, true);
        if (!$token) {
            abort(403, 'token is error');
        }

        $user = User::where('token', $token)->first()
            ?? app(\App\Services\SubscriptionGraceService::class)->resolve($token);
        if (!$user) {
            abort(403, 'token is error');
        }
        $request->merge([
            'user' => $user
        ]);
        return $next($request)->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }
}
