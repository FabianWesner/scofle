<?php

namespace App\Services;

use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class ImageSessionManager
{
    public const CookieName = 'image2pptx_session';

    public function resolve(Request $request): Session
    {
        $token = $request->cookies->get(self::CookieName);

        if (is_string($token) && $this->isValidToken($token)) {
            $session = Session::firstOrCreate(
                ['token' => $token],
                ['last_seen_at' => now()],
            );
        } else {
            $session = Session::create([
                'token' => Str::random(80),
                'last_seen_at' => now(),
            ]);
        }

        $session->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('image_session', $session);

        return $session;
    }

    public function cookie(Session $session): Cookie
    {
        return new Cookie(
            name: self::CookieName,
            value: $session->token,
            expire: now()->addYears(10),
            path: '/',
            domain: null,
            secure: (bool) config('conversion.session_cookie_secure'),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }

    private function isValidToken(string $token): bool
    {
        return strlen($token) >= 60 && ctype_alnum($token);
    }
}
