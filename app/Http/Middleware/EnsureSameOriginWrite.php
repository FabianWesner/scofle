<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureSameOriginWrite
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $source = $request->headers->get('Origin') ?: $request->headers->get('Referer');

        if (is_string($source) && $source !== '') {
            $sourceHost = parse_url($source, PHP_URL_HOST);
            $requestHost = $request->getHost();

            if (! is_string($sourceHost) || Str::lower($sourceHost) !== Str::lower($requestHost)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
