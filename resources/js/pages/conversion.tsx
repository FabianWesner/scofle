import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { AppShell } from '@/components/app-shell';
import { destroy, regenerate } from '@/routes/conversions';
import type { ConversionPageProps } from '@/types/conversion';

export default function Conversion({
    conversion,
    recentConversions,
    attempts,
    selectedAttempt,
    ttlHours,
}: ConversionPageProps) {
    const [pollExpired, setPollExpired] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const regenerateForm = useForm({});
    const deleteForm = useForm({});
    const isInflight =
        selectedAttempt.status === 'pending' ||
        selectedAttempt.status === 'running';

    useEffect(() => {
        if (!isInflight || pollExpired) {
            return;
        }

        const startedAt = Date.now();
        const timer = window.setInterval(() => {
            if (Date.now() - startedAt > 180_000) {
                setPollExpired(true);
                window.clearInterval(timer);

                return;
            }

            router.reload({
                only: ['attempts', 'selectedAttempt', 'recentConversions'],
            });
        }, 2000);

        return () => window.clearInterval(timer);
    }, [isInflight, pollExpired]);

    function submitRegenerate() {
        regenerateForm.post(regenerate.url(conversion.uuid), {
            preserveScroll: true,
        });
    }

    function submitDelete() {
        deleteForm.delete(destroy.url(conversion.uuid));
    }

    return (
        <AppShell recentConversions={recentConversions}>
            <Head title={`Conversion ${conversion.uuid.slice(0, 8)}`} />
            <section className="flex flex-col gap-5">
                <div className="flex flex-col justify-between gap-4 md:flex-row md:items-start">
                    <div>
                        <p className="font-mono text-xs text-zinc-500">
                            {conversion.uuid}
                        </p>
                        <h1 className="mt-1 text-2xl font-semibold tracking-normal">
                            Conversion {conversion.uuid.slice(0, 8)}
                        </h1>
                        <p className="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-300">
                            Only this browser session can open this conversion.
                            Temporary files are deleted after {ttlHours} hours.
                            Download files you want to keep.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href="/"
                            className="inline-flex h-10 items-center rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                        >
                            New image
                        </Link>
                        <button
                            type="button"
                            onClick={submitRegenerate}
                            disabled={isInflight || regenerateForm.processing}
                            className="h-10 rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-blue-500 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                        >
                            Regenerate
                        </button>
                        <button
                            type="button"
                            onClick={() => setDeleteOpen(true)}
                            className="h-10 rounded-md border border-red-200 bg-white px-3 text-sm font-medium text-red-700 outline-none hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-red-500 dark:border-red-900 dark:bg-zinc-900 dark:text-red-300 dark:hover:bg-red-950"
                        >
                            Delete
                        </button>
                    </div>
                </div>

                {pollExpired && (
                    <p className="rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">
                        Still working. Refresh to check again.
                    </p>
                )}

                <div className="grid gap-5 xl:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
                    <section className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-sm font-semibold">
                                Input image
                            </h2>
                            <StatusBadge
                                status={selectedAttempt.displayStatus}
                            />
                        </div>
                        <div className="mt-4 flex aspect-[4/3] items-center justify-center overflow-hidden rounded-md bg-zinc-100 dark:bg-zinc-800">
                            {selectedAttempt.inputPreview ? (
                                <img
                                    src={selectedAttempt.inputPreview}
                                    alt="Uploaded source image"
                                    className="h-full w-full object-contain"
                                />
                            ) : (
                                <span className="text-sm text-zinc-500">
                                    No preview
                                </span>
                            )}
                        </div>
                        <dl className="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt className="text-zinc-500">File</dt>
                                <dd className="truncate font-medium">
                                    {selectedAttempt.displayFilename ??
                                        'Unknown'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-zinc-500">Input</dt>
                                <dd className="font-medium">
                                    {formatBytes(selectedAttempt.inputBytes)}
                                </dd>
                            </div>
                        </dl>
                    </section>

                    <section className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                            <h2 className="text-sm font-semibold">
                                Output preview
                            </h2>
                            <div className="flex flex-wrap gap-2">
                                {selectedAttempt.downloads.pptx && (
                                    <a
                                        href={selectedAttempt.downloads.pptx}
                                        className="rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white outline-none hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500"
                                    >
                                        Download .pptx
                                    </a>
                                )}
                                {selectedAttempt.downloads.pdf && (
                                    <a
                                        href={selectedAttempt.downloads.pdf}
                                        className="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
                                    >
                                        Download .pdf
                                    </a>
                                )}
                            </div>
                        </div>

                        <OutputState attempt={selectedAttempt} />
                    </section>
                </div>

                <section className="flex flex-wrap gap-2">
                    {attempts.map((attempt) => (
                        <Link
                            key={attempt.id}
                            href={attempt.url}
                            className={`rounded-md border px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-blue-500 ${
                                attempt.selected
                                    ? 'border-blue-600 bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-200'
                                    : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800'
                            }`}
                        >
                            {attempt.label} ({attempt.displayStatus})
                        </Link>
                    ))}
                </section>
            </section>

            {deleteOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-md rounded-lg bg-white p-5 shadow-xl dark:bg-zinc-900">
                        <h2 className="text-lg font-semibold">
                            Delete conversion
                        </h2>
                        <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            This removes the temporary conversion, attempts, and
                            files.
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setDeleteOpen(false)}
                                className="rounded-md border border-zinc-300 px-3 py-2 text-sm outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={submitDelete}
                                disabled={deleteForm.processing}
                                className="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white outline-none hover:bg-red-700 focus-visible:ring-2 focus-visible:ring-red-500 disabled:opacity-50"
                            >
                                Delete conversion
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    );
}

function OutputState({
    attempt,
}: {
    attempt: ConversionPageProps['selectedAttempt'];
}) {
    if (attempt.status === 'pending' || attempt.status === 'running') {
        return (
            <div className="mt-4 flex min-h-96 flex-col items-center justify-center gap-3 rounded-md bg-zinc-100 p-6 text-center dark:bg-zinc-800">
                <div className="h-3 w-48 animate-pulse rounded-full bg-zinc-300 dark:bg-zinc-700" />
                <div className="h-3 w-64 animate-pulse rounded-full bg-zinc-300 dark:bg-zinc-700" />
                <p className="text-sm text-zinc-600 dark:text-zinc-300">
                    Generating. This usually takes 5-20 seconds.
                </p>
            </div>
        );
    }

    if (attempt.status === 'failed') {
        return (
            <div className="mt-4 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {attempt.failureMessage ??
                    'We could not convert this image. Please try a different image.'}
            </div>
        );
    }

    if (attempt.displayStatus === 'partial') {
        return (
            <div className="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                PDF rendering failed; the .pptx file is still available.
            </div>
        );
    }

    if (!attempt.downloads.pdfInline) {
        return (
            <div className="mt-4 rounded-md border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-700 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">
                PDF preview unavailable.
            </div>
        );
    }

    return (
        <object
            data={attempt.downloads.pdfInline}
            type="application/pdf"
            className="mt-4 h-[36rem] w-full rounded-md border border-zinc-200 bg-zinc-100 dark:border-zinc-800"
        >
            <div className="p-4 text-sm text-zinc-700 dark:text-zinc-200">
                Your browser blocks inline PDF; download to view.
            </div>
        </object>
    );
}

function StatusBadge({
    status,
}: {
    status: ConversionPageProps['selectedAttempt']['displayStatus'];
}) {
    const className =
        status === 'ready'
            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200'
            : status === 'failed'
              ? 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-200'
              : status === 'partial'
                ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-200'
                : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200';

    return (
        <span
            className={`rounded-full px-2 py-1 text-xs font-medium ${className}`}
        >
            {status}
        </span>
    );
}

function formatBytes(bytes: number | null) {
    if (!bytes) {
        return '0 KB';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
