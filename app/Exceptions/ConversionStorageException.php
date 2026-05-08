<?php

namespace App\Exceptions;

use RuntimeException;

class ConversionStorageException extends RuntimeException
{
    public static function missingInputExtension(): self
    {
        return new self('Attempt input extension is missing.');
    }

    public static function unreadableUpload(): self
    {
        return new self('Uploaded file is not readable.');
    }
}
