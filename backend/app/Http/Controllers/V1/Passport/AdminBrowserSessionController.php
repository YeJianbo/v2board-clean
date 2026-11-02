<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthLogin;
use App\Services\AdminBrowserSessionService;
use Illuminate\Http\Request;

class AdminBrowserSessionController extends Controller
{
    private AdminBrowserSessionService $sessions;

    public function __construct(AdminBrowserSessionService $sessions)
    {
        $this->sessions = $sessions;
        $this->middleware(function ($request, $next) {
            $this->sessions->assertBrowserRequest($request);
            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            return $response;
        });
    }

    public function bootstrap(Request $request)
    {
        $session = $this->sessions->authenticateCookie($request, false);
        return response()->json(['data' => $this->sessions->data($session)]);
    }

    public function login(AuthLogin $request, AuthController $auth)
    {
        // Reuse password verification, throttling and session creation, but never return its JWT response.
        $response = $auth->login($request, true);
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR)['data'];
        $session = $this->sessions->authenticateToken($data['auth_data']);
        return response()->json(['data' => $this->sessions->data($session)])
            ->withCookie($this->sessions->cookie($session));
    }

    public function migrate(Request $request)
    {
        // Migration authenticates only the explicitly supplied legacy Authorization, not input or cookies.
        $session = $this->sessions->authenticateToken($request->header('Authorization'));
        return response()->json(['data' => $this->sessions->data($session)])
            ->withCookie($this->sessions->cookie($session));
    }

    public function logout(Request $request)
    {
        $session = $this->sessions->authenticateCookie($request);
        $this->sessions->logout($request, $session);
        return response()->json(['data' => $this->sessions->data($session)])
            ->withCookie($this->sessions->expiredCookie());
    }
}
