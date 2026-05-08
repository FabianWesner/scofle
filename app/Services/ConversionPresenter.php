<?php

namespace App\Services;

use App\AttemptStatus;
use App\FailureCode;
use App\Models\Attempt;
use App\Models\Conversion;
use App\Models\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class ConversionPresenter
{
    public function __construct(
        private readonly ConversionStorage $storage,
        private readonly UploadNonceManager $nonces,
    ) {
        //
    }

    /**
     * @return array<string, mixed>
     */
    public function home(Session $session): array
    {
        return [
            'uploadNonce' => $this->nonces->create(),
            'ttlHours' => config('conversion.ttl_hours'),
            'maxBatchUploads' => config('conversion.max_batch_uploads'),
            'recentConversions' => $this->recentConversions($session),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function conversion(Conversion $conversion, Session $session, ?int $selectedAttemptNumber = null): array
    {
        $conversion->load(['attempts' => fn ($query) => $query->orderByDesc('n')]);

        $selected = $selectedAttemptNumber === null
            ? $conversion->attempts->first()
            : $conversion->attempts->firstWhere('n', $selectedAttemptNumber);

        abort_if(! $selected instanceof Attempt, 404);

        return [
            'conversion' => [
                'uuid' => $conversion->uuid,
                'url' => route('conversions.show', $conversion),
                'totalBytes' => $conversion->total_bytes,
            ],
            'ttlHours' => config('conversion.ttl_hours'),
            'recentConversions' => $this->recentConversions($session),
            'attempts' => $conversion->attempts
                ->map(fn (Attempt $attempt): array => $this->attemptSummary($attempt, selected: $attempt->is($selected)))
                ->values(),
            'selectedAttempt' => $this->attemptDetail($selected),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentConversions(Session $session): array
    {
        return $session->conversions()
            ->with(['attempts' => fn ($query) => $query->latest('n')->limit(1)])
            ->latest('created_at')
            ->limit((int) config('conversion.max_per_session'))
            ->get()
            ->map(function (Conversion $conversion): array {
                $attempt = $conversion->attempts->first();

                return [
                    'uuid' => $conversion->uuid,
                    'url' => route('conversions.show', $conversion),
                    'label' => $attempt?->display_filename ?: 'Untitled conversion',
                    'status' => $attempt?->status->value ?? AttemptStatus::Pending->value,
                    'displayStatus' => $attempt instanceof Attempt ? $this->displayStatus($attempt) : AttemptStatus::Pending->value,
                    'createdAt' => $conversion->created_at?->toISOString(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptSummary(Attempt $attempt, bool $selected): array
    {
        return [
            'id' => $attempt->id,
            'n' => $attempt->n,
            'label' => 'a'.$attempt->n,
            'status' => $attempt->status->value,
            'displayStatus' => $this->displayStatus($attempt),
            'selected' => $selected,
            'url' => route('conversions.attempts.show', [$attempt->conversion, $attempt->n]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptDetail(Attempt $attempt): array
    {
        return [
            ...$this->attemptSummary($attempt, selected: true),
            'displayFilename' => $attempt->display_filename,
            'inputBytes' => $attempt->input_bytes,
            'inputPixels' => $attempt->input_pixels,
            'inputPreview' => $this->inputPreview($attempt),
            'pptxBytes' => $attempt->pptx_bytes,
            'pdfBytes' => $attempt->pdf_bytes,
            'failureCode' => $attempt->failure_code,
            'failureMessage' => $this->failureMessage($attempt),
            'downloads' => $this->downloads($attempt),
            'createdAt' => $attempt->created_at?->toISOString(),
            'startedAt' => $attempt->started_at?->toISOString(),
            'finishedAt' => $attempt->finished_at?->toISOString(),
        ];
    }

    private function displayStatus(Attempt $attempt): string
    {
        if ($attempt->isPartial()) {
            return 'partial';
        }

        return $attempt->status->value;
    }

    private function failureMessage(Attempt $attempt): ?string
    {
        if ($attempt->failure_message !== null) {
            return $attempt->failure_message;
        }

        if ($attempt->failure_code === null) {
            return null;
        }

        $code = FailureCode::tryFrom($attempt->failure_code);

        return $code?->message();
    }

    /**
     * @return array{pptx: string|null, pdf: string|null, pdfInline: string|null}
     */
    private function downloads(Attempt $attempt): array
    {
        if ($attempt->status !== AttemptStatus::Ready) {
            return ['pptx' => null, 'pdf' => null, 'pdfInline' => null];
        }

        $expires = now()->addHours((int) config('conversion.ttl_hours'));

        return [
            'pptx' => $attempt->pptx_bytes !== null
                ? URL::temporarySignedRoute('downloads.show', $expires, ['attempt' => $attempt->id, 'kind' => 'pptx'])
                : null,
            'pdf' => $attempt->pdf_bytes !== null
                ? URL::temporarySignedRoute('downloads.show', $expires, ['attempt' => $attempt->id, 'kind' => 'pdf'])
                : null,
            'pdfInline' => $attempt->pdf_bytes !== null
                ? URL::temporarySignedRoute('downloads.show', $expires, ['attempt' => $attempt->id, 'kind' => 'pdf', 'inline' => 1])
                : null,
        ];
    }

    private function inputPreview(Attempt $attempt): ?string
    {
        $filename = $attempt->inputFilename();

        if ($filename === null) {
            return null;
        }

        $relativePath = $this->storage->attemptRelativeDirectory($attempt).'/'.$filename;

        if (! Storage::disk('local')->exists($relativePath)) {
            return null;
        }

        $contents = Storage::disk('local')->get($relativePath);
        $mime = $attempt->input_mime ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }
}
