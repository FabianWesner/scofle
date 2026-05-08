<?php

namespace App\Models;

use App\AttemptStatus;
use Database\Factories\AttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attempt extends Model
{
    /** @use HasFactory<AttemptFactory> */
    use HasFactory;

    protected $fillable = [
        'conversion_id',
        'n',
        'status',
        'display_filename',
        'input_mime',
        'input_ext',
        'input_bytes',
        'input_pixels',
        'pptx_bytes',
        'pdf_bytes',
        'pptx_sha256',
        'failure_code',
        'failure_message',
        'heartbeat_at',
        'started_at',
        'finished_at',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'input_bytes' => 'integer',
            'input_pixels' => 'integer',
            'pptx_bytes' => 'integer',
            'pdf_bytes' => 'integer',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversion, $this>
     */
    public function conversion(): BelongsTo
    {
        return $this->belongsTo(Conversion::class);
    }

    public function isPartial(): bool
    {
        return $this->status === AttemptStatus::Ready
            && $this->failure_code === 'pdf_render'
            && $this->pdf_bytes === null;
    }

    public function inputFilename(): ?string
    {
        if ($this->input_ext === null) {
            return null;
        }

        return "input.{$this->input_ext}";
    }
}
