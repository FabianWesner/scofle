<?php

namespace App\Http\Controllers;

use App\AttemptStatus;
use App\Models\Attempt;
use App\Models\Session;
use App\Services\ConversionStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    public function __invoke(Request $request, ConversionStorage $storage, Attempt $attempt, string $kind): BinaryFileResponse
    {
        abort_unless(in_array($kind, ['pptx', 'pdf'], true), 404);

        /** @var Session $session */
        $session = $request->attributes->get('image_session');
        $attempt->loadMissing('conversion');
        abort_unless($attempt->conversion->session_id === $session->id, 404);
        abort_unless($attempt->status === AttemptStatus::Ready, 404);

        $filename = $kind === 'pptx' ? 'output.pptx' : 'output.pdf';
        $bytesColumn = $kind === 'pptx' ? 'pptx_bytes' : 'pdf_bytes';
        abort_if($attempt->{$bytesColumn} === null, 404);

        $path = $storage->absolutePath($attempt, $filename);
        abort_unless(is_file($path), 404);

        $inline = $kind === 'pdf' && $request->boolean('inline');
        $downloadName = sprintf('conversion-%s-a%d.%s', substr($attempt->conversion->uuid, 0, 8), $attempt->n, $kind);
        $contentType = $kind === 'pptx'
            ? 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
            : 'application/pdf';

        $response = response()->file($path, [
            'Content-Type' => $contentType,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$downloadName.'"',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if ($inline) {
            $response->headers->set('Content-Security-Policy', 'sandbox');
        }

        return $response;
    }
}
