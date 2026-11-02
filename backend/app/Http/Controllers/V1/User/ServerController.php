<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user['id']);
        $servers = [];
        $userService = app(UserService::class);
        if ($user && $userService->isAvailable($user)) {
            $serverService = app(ServerService::class);
            $servers = $serverService->getAvailableServers($user);
        }
        $response = response([
            'data' => $servers
        ])->header('Cache-Control', 'private, no-cache');
        // Entry overrides and live rates can change without updating a node's cache_key.
        $response->setEtag(sha1($response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
