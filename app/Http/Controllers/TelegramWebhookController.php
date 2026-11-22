<?php

namespace App\Http\Controllers;

use App\Jobs\HandleTelegramUpdate;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Telegram delivers here. Anything without the secret header set when connecting is rejected. */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) Setting::get('telegram_webhook_secret');
        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(403);
        }

        HandleTelegramUpdate::dispatch($request->json()->all());

        return response('', 200);
    }
}
