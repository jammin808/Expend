<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * Every limited route keeps its own count. Laravel's plain "throttle:N,1" counts every request a person makes to any
 * route limited that way in one shared count, so asking the assistant six times in a minute made "Check now" answer
 * "too many attempts". The third part of each limit names its own count; only the two calendar saves share one.
 */
class ThrottleCountersTest extends TestCase
{
    /** @return array<string, list<string>> each count's name and the routes that use it */
    private function counters(): array
    {
        $counters = [];
        /** @var Route $route */
        foreach (Router::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'throttle:')) {
                    continue;
                }
                $parts = explode(',', substr($middleware, strlen('throttle:')));
                if (! is_numeric($parts[0])) {
                    continue; // a named limiter has its own key
                }
                $counters[$parts[2] ?? ''][] = $route->getName() ?? $route->uri();
            }
        }
        ksort($counters);

        return $counters;
    }

    public function test_every_limited_route_has_a_count_of_its_own(): void
    {
        $counters = $this->counters();

        $this->assertArrayNotHasKey('', $counters, 'Routes sharing the plain count: '.implode(', ', $counters[''] ?? []));
        $shared = array_filter($counters, fn (array $routes) => count($routes) > 1);
        $this->assertSame(['calendar-pick' => ['calendar.pick.store', 'jobs.days.pick']], array_map(function (array $routes) {
            sort($routes);

            return $routes;
        }, $shared), 'Only the two calendar saves share a count');
        foreach (['jobs.gather', 'intel.check', 'assistant.ask', 'ai.suggest', 'calendar.month'] as $name) {
            $this->assertNotEmpty(array_filter($counters, fn (array $routes) => in_array($name, $routes, true)), "$name is limited");
        }
    }

    public function test_using_one_limited_route_leaves_the_others_count_alone(): void
    {
        $user = User::factory()->make(['id' => 41]);
        $request = Request::create('/assistant/ask', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->setRouteResolver(fn () => (new Route('POST', 'assistant/ask', fn () => null))->bind($request));
        $throttle = app(ThrottleRequests::class);
        $ok = fn () => response('ok');
        RateLimiter::clear('assistant-ask'.sha1('41'));
        RateLimiter::clear('intel-check'.sha1('41'));
        RateLimiter::clear(sha1('41'));

        // Six questions to the assistant in a minute, then "Check now", each with its own count.
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(200, $throttle->handle($request, $ok, 30, 1, 'assistant-ask')->getStatusCode());
        }
        $this->assertSame(200, $throttle->handle($request, $ok, 6, 1, 'intel-check')->getStatusCode(), '"Check now" still has all its tries');

        // The same with the plain limits shared one count, so "Check now" was refused.
        for ($i = 0; $i < 6; $i++) {
            $throttle->handle($request, $ok, 30, 1);
        }
        $this->expectException(ThrottleRequestsException::class);
        $throttle->handle($request, $ok, 6, 1);
    }
}
