<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request that only reads, sent by a page that is already showing (a deferred or partial reload whose answer leaves
 * out the page's message, or a JSON search such as a picker's), leaves the session as it found it.
 *
 * Laravel's sessions aren't locked: each request reads the session when it starts and writes all of it back when it
 * ends. A read that is still running when a press is posted would write its older copy back over the message the press
 * had just put there (the to-do list's own second load, "more_below", takes a while), and the press's answer, its Undo
 * and what to do next would be lost. Such a read changes nothing in the session, so it doesn't write it. A request
 * that signs you back in (a new session id), puts a message in the session, or sends the page its message is saved as
 * usual.
 */
class LeaveSessionOnReads
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->hasSession() ? $request->session() : null;
        $id = $session?->getId();
        $response = $next($request);
        if ($session !== null && $session->getId() === $id && $session->get('_flash.new', []) === []
            && $request->user() !== null && self::onlyRead($request, $response)) {
            $session->setHandler(new NullSessionHandler);
        }

        return $response;
    }

    /** A GET from a page already showing, answered with data that doesn't carry the page's message (so didn't use it up). */
    public static function onlyRead(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->isPrecognitive() || $response->getStatusCode() !== 200) {
            return false;
        }
        if ($request->header('X-Inertia')) {
            // A partial reload sends only what it asked for; the message stays for the next whole page.
            if (! $request->header('X-Inertia-Partial-Data') && ! $request->header('X-Inertia-Partial-Except')) {
                return false;
            }
            $page = $response instanceof JsonResponse ? $response->getData(true) : null;

            return is_array($page) && is_array($page['props'] ?? null) && ! array_key_exists('flash', $page['props']);
        }

        // A page's own JSON call (a search, a sheet's figures): it never shows a message.
        return $request->wantsJson() && $response instanceof JsonResponse;
    }
}
