<?php

return [
    'ttl_hours' => (int) env('CONVERSION_TTL_HOURS', 24),
    'tmp_bytes_cap' => (int) env('CONVERSION_TMP_BYTES_CAP', 5_368_709_120),
    'max_attempts' => (int) env('CONVERSION_MAX_ATTEMPTS', 5),
    'max_per_session' => (int) env('CONVERSION_MAX_PER_SESSION', 20),
    'queue_depth_cap' => (int) env('CONVERSION_QUEUE_DEPTH_CAP', 50),
    'session_cookie_secure' => (bool) env('IMAGE_SESSION_COOKIE_SECURE', true),
    'python' => env('PPT_PYTHON', storage_path('app/python-venv/bin/python')),
    'bridge' => env('PPT_BRIDGE', base_path('python/bridge.py')),
    'soffice' => env('PPT_SOFFICE', 'soffice'),
    'timeout' => (int) env('PPT_CONVERSION_TIMEOUT', 90),
    'max_inpaint_size' => (int) env('PPT_MAX_INPAINT_SIZE', 2048),
    'log_limit' => (int) env('PPT_JOB_LOG_LIMIT', 262_144),
    'author' => 'image-to-powerpoint',
];
