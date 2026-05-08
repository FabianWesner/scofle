<?php

namespace App\Services;

use App\AttemptStatus;
use App\Jobs\ProcessConversionQueueJob;
use App\Models\Attempt;
use App\Models\Conversion;
use App\Models\Session;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConversionLifecycle
{
    public function __construct(
        private readonly ImageUploadValidator $validator,
        private readonly ConversionStorage $storage,
        private readonly UploadNonceManager $nonces,
    ) {
        //
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, Conversion>
     */
    public function createFromUploads(Session $session, array $files, string $nonce): array
    {
        $this->assertCanQueueAttempts(count($files));

        $validatedFiles = collect($files)
            ->map(fn (UploadedFile $file, int $index): array => [
                'file' => $file,
                'validated' => $this->validator->validate($file, "images.{$index}"),
            ])
            ->all();

        $this->nonces->consume($nonce);

        $conversions = DB::transaction(function () use ($session, $validatedFiles): array {
            $this->evictOldSessionConversions($session, reserveSlot: count($validatedFiles));

            return collect($validatedFiles)
                ->map(function (array $validatedFile) use ($session): Conversion {
                    /** @var UploadedFile $file */
                    $file = $validatedFile['file'];

                    /** @var array{mime: string, ext: string, bytes: int, pixels: int} $validated */
                    $validated = $validatedFile['validated'];

                    $conversion = Conversion::create([
                        'uuid' => (string) Str::uuid(),
                        'session_id' => $session->id,
                        'total_bytes' => 0,
                    ]);

                    $attempt = $this->createAttempt($conversion, $file->getClientOriginalName(), $validated);
                    $this->storage->storeInput($attempt, $file);
                    $this->storage->refreshConversionBytes($conversion);

                    return $conversion;
                })
                ->all();
        });

        ProcessConversionQueueJob::dispatch();

        return $conversions;
    }

    public function regenerate(Conversion $conversion, Session $session): Attempt
    {
        $this->assertOwns($conversion, $session);
        $this->assertCanQueueAttempts(1);

        $source = $conversion->attempts()
            ->where('status', '!=', AttemptStatus::Failed->value)
            ->latest('n')
            ->first();

        if (! $source instanceof Attempt || $source->input_ext === null) {
            throw new HttpResponseException(response('Regenerate needs a successful source image first.', 422));
        }

        $attempt = DB::transaction(function () use ($conversion, $source): Attempt {
            $attempt = Attempt::create([
                'conversion_id' => $conversion->id,
                'n' => $this->nextAttemptNumber($conversion),
                'status' => AttemptStatus::Pending,
                'display_filename' => $source->display_filename,
                'input_mime' => $source->input_mime,
                'input_ext' => $source->input_ext,
                'input_bytes' => $source->input_bytes,
                'input_pixels' => $source->input_pixels,
            ]);

            $this->storage->copyInput($source, $attempt);
            $this->evictOldAttempts($conversion);
            $this->storage->refreshConversionBytes($conversion);

            return $attempt;
        });

        ProcessConversionQueueJob::dispatch();

        return $attempt;
    }

    public function delete(Conversion $conversion): void
    {
        $this->storage->deleteConversion($conversion);
        $conversion->delete();
    }

    public function deleteAllForSession(Session $session): void
    {
        $session->conversions()
            ->oldest('created_at')
            ->get()
            ->each(function (Conversion $conversion): void {
                $this->delete($conversion);
            });

        $this->storage->deleteSessionConversions($session);
    }

    public function assertOwns(Conversion $conversion, Session $session): void
    {
        abort_unless($conversion->session_id === $session->id, 404);
    }

    /**
     * @param  array{mime: string, ext: string, bytes: int, pixels: int}  $validated
     */
    private function createAttempt(Conversion $conversion, string $displayFilename, array $validated): Attempt
    {
        return Attempt::create([
            'conversion_id' => $conversion->id,
            'n' => $this->nextAttemptNumber($conversion),
            'status' => AttemptStatus::Pending,
            'display_filename' => Str::limit($displayFilename, 180, ''),
            'input_mime' => $validated['mime'],
            'input_ext' => $validated['ext'],
            'input_bytes' => $validated['bytes'],
            'input_pixels' => $validated['pixels'],
        ]);
    }

    private function nextAttemptNumber(Conversion $conversion): int
    {
        return ((int) $conversion->attempts()->max('n')) + 1;
    }

    private function assertCanQueueAttempts(int $count): void
    {
        if ($count < 1) {
            throw new HttpResponseException(response('Choose one or more PNG or JPEG images.', 422));
        }

        $inflightCount = Attempt::query()
            ->whereIn('status', [AttemptStatus::Pending->value, AttemptStatus::Running->value])
            ->count();

        if ($inflightCount + $count > config('conversion.queue_depth_cap')) {
            throw new HttpResponseException(response('Service is busy, try again in a moment.', 503));
        }
    }

    private function evictOldAttempts(Conversion $conversion): void
    {
        while ($conversion->attempts()->count() > config('conversion.max_attempts')) {
            $attempt = $conversion->attempts()
                ->whereNotIn('status', [AttemptStatus::Pending->value, AttemptStatus::Running->value])
                ->oldest('n')
                ->first();

            if (! $attempt instanceof Attempt) {
                return;
            }

            $this->storage->deleteAttempt($attempt);
            $attempt->delete();
        }
    }

    private function evictOldSessionConversions(Session $session, int $reserveSlot): void
    {
        while ($session->conversions()->count() + $reserveSlot > config('conversion.max_per_session')) {
            $conversion = $session->conversions()
                ->with('attempts')
                ->oldest('created_at')
                ->get()
                ->first(fn (Conversion $conversion): bool => ! $conversion->hasInflightAttempt());

            if (! $conversion instanceof Conversion) {
                return;
            }

            $this->delete($conversion);
        }
    }
}
