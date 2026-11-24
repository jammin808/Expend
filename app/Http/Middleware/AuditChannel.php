<?php

namespace App\Http\Middleware;

use App\Domain\Intel\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every web request is one action: whatever it writes to the audit trail is recorded as from the web, under one
 * correlation id, with the knock-on writes it causes (the other side of a transfer confirmed, the suggestions learned
 * from your decision, a queued job it starts). The actor stays as it is: you, unless the code acting names itself.
 */
final class AuditChannel
{
    public function handle(Request $request, Closure $next): Response
    {
        return AuditContext::with(null, fn () => $next($request), channel: 'web', correlation: AuditContext::newCorrelation());
    }
}
