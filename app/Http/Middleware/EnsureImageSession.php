<?php

namespace App\Http\Middleware;

use App\Services\ImageSessionManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureImageSession
{
    public function __construct(private readonly ImageSessionManager $sessions)
    {
        //
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->resolve($request);
        $response = $next($request);
        $response->headers->setCookie($this->sessions->cookie($session));

        return $response;
    }
}
