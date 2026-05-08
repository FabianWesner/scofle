<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImageUploadRequest;
use App\Models\Session;
use App\Services\ConversionLifecycle;
use App\Services\UploadNonceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class UploadController extends Controller
{
    public function __invoke(StoreImageUploadRequest $request, ConversionLifecycle $conversions, UploadNonceManager $nonces): RedirectResponse|JsonResponse
    {
        /** @var Session $session */
        $session = $request->attributes->get('image_session');
        $files = Arr::wrap($request->file('images'));

        abort_unless($files !== [] && collect($files)->every(fn ($file): bool => $file instanceof UploadedFile), 422);

        $created = $conversions->createFromUploads($session, $files, (string) $request->validated('nonce'));
        $conversion = $created[0] ?? null;

        abort_unless($conversion !== null, 422);

        if ($request->expectsJson()) {
            return response()->json([
                'redirect' => route('conversions.show', $conversion),
                'uploadNonce' => $nonces->create(),
            ], 201);
        }

        return redirect()->route('conversions.show', $conversion);
    }
}
