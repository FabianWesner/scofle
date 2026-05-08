<?php

use Illuminate\Support\Facades\File;

test('php code does not use shell execution APIs', function () {
    $forbidden = [
        'shell_exec(',
        'system(',
        'exec(',
        'proc_open(',
        'Process::fromShellCommandline(',
    ];

    $files = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php');

    foreach ($files as $file) {
        $contents = File::get($file->getPathname());
        $relativePath = str_replace(base_path().'/', '', $file->getPathname());

        foreach ($forbidden as $needle) {
            expect($contents)
                ->not->toContain($needle, "{$needle} found in {$relativePath}");
        }
    }
});

test('telemetry and local llm packages are absent from app code', function () {
    $needles = ['@sen'.'try/', 'mix'.'panel', 'seg'.'ment', 'amp'.'litude', 'post'.'hog', 'google-'.'generative'.'ai', 'generative'.'ai'];
    $directories = [
        app_path(),
        config_path(),
        resource_path(),
        base_path('bin'),
        base_path('python'),
        base_path('routes'),
        base_path('tests'),
    ];
    $files = collect($directories)
        ->flatMap(fn (string $path): array => is_dir($path) ? File::allFiles($path) : [])
        ->filter(fn (SplFileInfo $file): bool => in_array($file->getExtension(), ['php', 'ts', 'tsx', 'js', 'json'], true));

    foreach ($files as $file) {
        $contents = File::get($file->getPathname());
        $relativePath = str_replace(base_path().'/', '', $file->getPathname());

        foreach ($needles as $needle) {
            expect($contents)
                ->not->toContain($needle, "{$needle} found in {$relativePath}");
        }
    }
});

test('obsolete sharing implementation fragments are absent from active code', function () {
    $needles = [
        'Pro'.'ject'.'Controller',
        'Pro'.'ject'.'Storage',
        'Pro'.'ject'.'Lifecycle',
        'Image'.'Pro'.'jector',
        'Convert'.'Ver'.'sion'.'Job',
        'Ver'.'sion'.'Status',
        'session_'.'pro'.'jects',
        'create_'.'pro'.'jects'.'_table',
        'create_'.'ver'.'sions'.'_table',
        '/'.'pro'.'jects',
        'pro'.'jects.'.'show',
        'pro'.'jects.'.'destroy',
        'pro'.'jects.'.'regenerate',
        'Copy'.' link',
        'copy'.'Link',
        'Anyone with'.' this link',
    ];
    $directories = [
        app_path(),
        config_path(),
        database_path(),
        resource_path('js'),
        base_path('routes'),
        base_path('tests'),
    ];
    $files = collect($directories)
        ->flatMap(fn (string $path): array => is_dir($path) ? File::allFiles($path) : [])
        ->filter(fn (SplFileInfo $file): bool => in_array($file->getExtension(), ['php', 'ts', 'tsx', 'js'], true));

    foreach ($files as $file) {
        $contents = File::get($file->getPathname());
        $relativePath = str_replace(base_path().'/', '', $file->getPathname());

        foreach ($needles as $needle) {
            expect($contents)
                ->not->toContain($needle, "{$needle} found in {$relativePath}");
        }
    }
});
