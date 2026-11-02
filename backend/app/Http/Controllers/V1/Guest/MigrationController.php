<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Services\InfrastructureMigrationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class MigrationController extends Controller
{
    public function package(Request $request, InfrastructureMigrationService $service)
    {
        $token = (string) $request->bearerToken();
        $data = $service->ticketData($token);
        $key = 'migration-download:' . hash('sha256', $token);
        if (RateLimiter::tooManyAttempts($key, 3)) {
            abort(429, '下载重试过于频繁，请稍后重试', ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
        }
        RateLimiter::hit($key, 600);
        $path = $service->package($service->backup($data['backup_id']), $data['settings']);
        return $service->download($path, 'migration.tar.gz');
    }
}
