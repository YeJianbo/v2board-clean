<?php

namespace App\Http\Routes\V2;

use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server'
        ], function ($router) {
            $router->post('/certificate', function (\Illuminate\Http\Request $request) {
                return \App::make(\App\Http\Controllers\V2\Server\ServerController::class)->certificate($request);
            });
            $router->any('/{action}', function ($action) {
                abort_if($action === 'certificate', 405, 'Method not allowed');
                $ctrl = \App::make(\App\Http\Controllers\V2\Server\ServerController::class);
                $response = \App::call([$ctrl, $action]);
                if ($response instanceof \Symfony\Component\HttpFoundation\Response) $response->headers->set('Cache-Control', 'no-store');
                return $response;
            });
        });
    }
}
