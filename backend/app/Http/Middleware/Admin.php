<?php

namespace App\Http\Middleware;

use App\Services\AdminBrowserSessionService;
use Closure;

class Admin
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
        $sessions = app(AdminBrowserSessionService::class);
        $user = $sessions->identity($request);
        if (!$user || !$user['is_admin']) abort(403, '未登录或登陆已过期');
        $request->merge([
            'user' => $user
        ]);
        $response = $next($request);
        if ($sessions->usesCookie($request)) $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }
}
