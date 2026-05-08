<?php

namespace App;

enum FailureCode: string
{
    case Validation = 'validation';
    case BridgeError = 'bridge_error';
    case BridgeTimeout = 'bridge_timeout';
    case Oom = 'oom';
    case DiskFull = 'disk_full';
    case EmptyOutput = 'empty_output';
    case InvalidPptx = 'invalid_pptx';
    case PdfRender = 'pdf_render';
    case Interrupted = 'interrupted';
    case RateLimited = 'rate_limited';

    public function message(): string
    {
        return match ($this) {
            self::Validation => 'The image could not be accepted.',
            self::BridgeError, self::EmptyOutput, self::InvalidPptx => 'We could not convert this image. Please try a different image.',
            self::BridgeTimeout => 'Conversion took too long. Please try a smaller image.',
            self::Oom => 'Conversion failed because the image was too complex. Try a simpler image.',
            self::DiskFull => 'Server is out of space. Please try again later.',
            self::PdfRender => 'PDF rendering failed; the .pptx file is still available.',
            self::Interrupted => 'The conversion was interrupted. Please regenerate.',
            self::RateLimited => 'Too many uploads. Please wait a few minutes.',
        };
    }
}
