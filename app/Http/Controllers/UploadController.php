<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImageUploadRequest;
use App\Models\Session;
use App\Services\ConversionLifecycle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

class UploadController extends Controller
{
    public function __invoke(StoreImageUploadRequest $request, ConversionLifecycle $conversions): RedirectResponse
    {
        /** @var Session $session */
        $session = $request->attributes->get('image_session');
        $file = $request->file('image');

        abort_unless($file instanceof UploadedFile, 422);

        $conversion = $conversions->createFromUpload($session, $file, (string) $request->validated('nonce'));

        return redirect()->route('conversions.show', $conversion);
    }
}
