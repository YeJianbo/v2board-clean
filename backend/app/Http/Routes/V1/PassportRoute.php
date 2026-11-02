<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class PassportRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => 'passport'
        ], function ($router) {
            // Auth
            $router->post('/auth/register', 'V1\\Passport\\AuthController@register');
            $router->post('/auth/login', 'V1\\Passport\\AuthController@login');
            $router->get('/auth/admin-session', 'V1\\Passport\\AdminBrowserSessionController@bootstrap');
            $router->post('/auth/admin-login', 'V1\\Passport\\AdminBrowserSessionController@login');
            $router->post('/auth/admin-session/migrate', 'V1\\Passport\\AdminBrowserSessionController@migrate');
            $router->post('/auth/admin-logout', 'V1\\Passport\\AdminBrowserSessionController@logout');
            $router->get ('/auth/token2Login', 'V1\\Passport\\AuthController@token2Login');
            $router->post('/auth/forget', 'V1\\Passport\\AuthController@forget');
            $router->post('/auth/getQuickLoginUrl', 'V1\\Passport\\AuthController@getQuickLoginUrl');
            // Comm
            $router->post('/comm/sendEmailVerify', 'V1\\Passport\\CommController@sendEmailVerify');
            $router->post('/comm/pv', 'V1\\Passport\\CommController@pv');
        });
    }
}
