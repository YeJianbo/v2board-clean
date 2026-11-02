<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramController extends Controller
{
    public function getBotInfo()
    {
        $telegramService = app(TelegramService::class);
        return response([
            'data' => [
                'username' => $telegramService->getBotUsername()
            ]
        ]);
    }

    public function unbind(Request $request)
    {
        $user = User::where('user_id', $request->user['id'])->first();
    }
}
