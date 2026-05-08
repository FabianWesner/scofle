<?php

namespace App\Http\Controllers;

use App\Models\Session;
use App\Services\ConversionPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, ConversionPresenter $presenter): Response
    {
        /** @var Session $session */
        $session = $request->attributes->get('image_session');

        return Inertia::render('home', $presenter->home($session));
    }
}
