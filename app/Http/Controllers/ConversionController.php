<?php

namespace App\Http\Controllers;

use App\Models\Conversion;
use App\Models\Session;
use App\Services\ConversionLifecycle;
use App\Services\ConversionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConversionController extends Controller
{
    public function show(Request $request, ConversionLifecycle $conversions, ConversionPresenter $presenter, Conversion $conversion, ?int $attempt = null): Response
    {
        /** @var Session $session */
        $session = $request->attributes->get('image_session');
        $conversions->assertOwns($conversion, $session);

        return Inertia::render('conversion', $presenter->conversion($conversion, $session, $attempt));
    }

    public function regenerate(Request $request, ConversionLifecycle $conversions, Conversion $conversion): RedirectResponse
    {
        /** @var Session $session */
        $session = $request->attributes->get('image_session');

        $conversions->regenerate($conversion, $session);

        return redirect()->route('conversions.show', $conversion);
    }

    public function destroy(Request $request, ConversionLifecycle $conversions, Conversion $conversion): RedirectResponse
    {
        /** @var Session $session */
        $session = $request->attributes->get('image_session');
        $conversions->assertOwns($conversion, $session);
        $conversions->delete($conversion);

        return redirect()->route('home');
    }
}
