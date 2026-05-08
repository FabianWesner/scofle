<?php

use App\AttemptStatus;
use App\FailureCode;

test('attempt status enum only contains canonical persisted values', function () {
    expect(array_map(fn (AttemptStatus $status): string => $status->value, AttemptStatus::cases()))
        ->toBe(['pending', 'running', 'ready', 'failed']);
});

test('partial is derived state and not a persisted status', function () {
    expect(collect(AttemptStatus::cases())->contains(fn (AttemptStatus $status): bool => $status->value === 'partial'))
        ->toBeFalse();
});

test('failure codes expose stable user messages', function () {
    expect(FailureCode::BridgeTimeout->message())->toBe('Conversion took too long. Please try a smaller image.')
        ->and(FailureCode::PdfRender->message())->toBe('PDF rendering failed; the .pptx file is still available.')
        ->and(FailureCode::Interrupted->message())->toBe('The conversion was interrupted. Please regenerate.');
});
