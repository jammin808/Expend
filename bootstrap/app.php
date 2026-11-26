<?php

use App\Domain\Quick\QuickWorker;
use App\Http\Controllers\QuickController;
use App\Http\Middleware\AuditChannel;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LeaveSessionOnReads;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [__DIR__.'/../routes/web.php', __DIR__.'/../routes/public.php'],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Telegram can't send a CSRF token; the webhook is protected by its secret header instead.
        $middleware->validateCsrfTokens(except: ['hooks/telegram']);
        $middleware->web(append: [
            SecurityHeaders::class,
            // Around the Inertia middleware, so it sees the answer as sent: a read that doesn't use the page's message
            // leaves the session alone (a press posted meanwhile keeps its message, Undo and follow-ups).
            LeaveSessionOnReads::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // Last, so it's around the controller: what a request writes to the audit trail is from the web, as one action.
            AuditChannel::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A photo over PHP's post_max_size from the phone: the phone's own words and error code, as for any upload
        // over the limit (QuickController::photo).
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            if (! $request->is('quick/*')) {
                return null;
            }

            return response()->json(['error' => 'too_big', 'message' => 'Too big to send from your phone (over '
                .QuickController::megabytes(QuickWorker::maxUploadBytes()).'). Upload it from a computer.'], 413);
        });
    })->create();
