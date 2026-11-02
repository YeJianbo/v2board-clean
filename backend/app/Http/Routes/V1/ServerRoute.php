<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class ServerRoute
{
    private const ALLOWED_ENDPOINTS = [
        'deepbwork' => [
            'controller' => 'DeepbworkController',
            'actions' => [
                'user' => 'user',
                'submit' => 'submit',
                'config' => 'config',
            ],
        ],
        'machine' => [
            'controller' => 'MachineController',
            'actions' => [
                'config' => 'config',
                'push' => 'push',
                'address' => 'address',
                'enroll' => 'enroll',
                'v2nodeconfig' => 'v2nodeConfig',
                'ravelconfig' => 'ravelConfig',
                'relayconfig' => 'relayConfig',
                'unregister' => 'unregister',
                'restartack' => 'restartAck',
                'updateack' => 'updateAck',
                'bbrack' => 'bbrAck',
                'connectivitytestack' => 'connectivityTestAck',
                'runtimetaskack' => 'runtimeTaskAck',
                'infrastructurepoll' => 'infrastructurePoll',
                'infrastructureack' => 'infrastructureAck',
                'infrastructureupload' => 'infrastructureUpload',
                'infrastructuredownload' => 'infrastructureDownload',
                'infrastructureevent' => 'infrastructureEvent',
                'networkquality' => 'networkQuality',
            ],
        ],
        'machineapi' => [
            'controller' => 'MachineApiController',
            'actions' => [
                'pushstatus' => 'pushStatus',
                'fetchcommand' => 'fetchCommand',
            ],
        ],
        'shadowsockstidalab' => [
            'controller' => 'ShadowsocksTidalabController',
            'actions' => [
                'user' => 'user',
                'submit' => 'submit',
            ],
        ],
        'trojantidalab' => [
            'controller' => 'TrojanTidalabController',
            'actions' => [
                'user' => 'user',
                'submit' => 'submit',
                'config' => 'config',
            ],
        ],
        'uniproxy' => [
            'controller' => 'UniProxyController',
            'actions' => [
                'user' => 'user',
                'push' => 'push',
                'alivelist' => 'alivelist',
                'alive' => 'alive',
                'config' => 'config',
            ],
        ],
    ];

    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'server'
        ], function ($router) {
            $router->any('/{class}/{action}', function($class, $action) {
                $classKey = strtolower((string) $class);
                $actionKey = strtolower((string) $action);
                $endpoint = self::ALLOWED_ENDPOINTS[$classKey] ?? null;

                if (!$endpoint || !isset($endpoint['actions'][$actionKey])) {
                    abort(404);
                }

                $controller = "\\App\\Http\\Controllers\\V1\\Server\\" . $endpoint['controller'];
                $method = $endpoint['actions'][$actionKey];
                $ctrl = \App::make($controller);
                $response = \App::call([$ctrl, $method]);
                if ($response instanceof \Symfony\Component\HttpFoundation\Response) $response->headers->set('Cache-Control', 'no-store');
                return $response;
            });
        });
    }
}
