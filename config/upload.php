<?php

return [
    'max_bytes' => (int) env('UPLOAD_MAX_BYTES', 8_000_000),
    'max_long_edge' => (int) env('UPLOAD_MAX_LONG_EDGE', 4096),
    'max_pixels' => (int) env('UPLOAD_MAX_PIXELS', 16_777_216),
];
