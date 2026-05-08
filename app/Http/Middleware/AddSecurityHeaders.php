<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->isHtmlResponse($response)) {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
            $response->headers->set('X-Frame-Options', 'DENY');
        }

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html')
            || str_contains($contentType, 'application/xhtml+xml');
    }
}
