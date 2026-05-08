import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { AppShell } from '@/components/app-shell';
import { home } from '@/routes';
import { destroy, regenerate } from '@/routes/conversions';
import type { ConversionPageProps } from '@/types/conversion';

export default function Conversion({
    conversion,
    recentConversions,
    attempts,
    selectedAttempt,
    ttlHours,
}: Readonly<ConversionPageProps>) {
    const retention = ttlHours === 1 ? '1 hour' : `${ttlHours} hours`;
    const [pollExpired, setPollExpired] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const regenerateForm = useForm({});
    const deleteForm = useForm({});
    const isInflight =
        selectedAttempt.status === 'pending' ||
        selectedAttempt.status === 'running';
    const hasRecentInflight = recentConversions.some(
        (recentConversion) =>
            recentConversion.status === 'pending' ||
            recentConversion.status === 'running',
    );
    const shouldPoll = isInflight || hasRecentInflight;

    useEffect(() => {
        if (!shouldPoll || pollExpired) {
            return;
        }

        const startedAt = Date.now();
        const timer = globalThis.setInterval(() => {
            if (Date.now() - startedAt > 180_000) {
                setPollExpired(true);
                globalThis.clearInterval(timer);

                return;
            }

            router.reload({
                only: ['attempts', 'selectedAttempt', 'recentConversions'],
            });
        }, 2000);

        return () => globalThis.clearInterval(timer);
    }, [shouldPoll, pollExpired]);

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
            <section className="flex min-h-[calc(100vh-5.5rem)] flex-col gap-3">
                <div className="rounded-lg border border-blue-900/10 bg-white shadow-sm shadow-blue-900/5 dark:border-blue-100/10 dark:bg-zinc-900">
                    <div className="flex flex-col justify-between gap-3 px-4 py-3 xl:flex-row xl:items-center">
                        <div className="flex min-w-0 items-center gap-3">
                            <StatusBadge
                                status={selectedAttempt.displayStatus}
                            />
                            <div className="min-w-0">
                                <p className="truncate font-mono text-[11px] text-zinc-500">
                                    {conversion.uuid}
                                </p>
                                <h1 className="truncate text-lg font-semibold tracking-normal">
                                    Conversion {conversion.uuid.slice(0, 8)}
                                </h1>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <a
                                href={selectedAttempt.downloads.pptx ?? '#'}
                                aria-disabled={!selectedAttempt.downloads.pptx}
                                className={`inline-flex h-9 items-center rounded-md px-3 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-zinc-500 ${
                                    selectedAttempt.downloads.pptx
                                        ? 'bg-blue-700 text-white hover:bg-blue-800 dark:bg-blue-300 dark:text-blue-950 dark:hover:bg-blue-200'
                                        : 'pointer-events-none bg-zinc-200 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-500'
                                }`}
                            >
                                Download .pptx
                            </a>
                            {selectedAttempt.downloads.pdf && (
                                <a
                                    href={selectedAttempt.downloads.pdf}
                                    className="inline-flex h-9 items-center rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                                >
                                    Download .pdf
                                </a>
                            )}
                            <Link
                                href={home.url()}
                                className="inline-flex h-9 items-center rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                            >
                                New image
                            </Link>
                            <button
                                type="button"
                                onClick={submitRegenerate}
                                disabled={
                                    isInflight || regenerateForm.processing
                                }
                                className="h-9 rounded-md border border-zinc-300 bg-white px-3 text-sm font-medium outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-zinc-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                            >
                                Regenerate
                            </button>
                            <button
                                type="button"
                                onClick={() => setDeleteOpen(true)}
                                className="h-9 rounded-md border border-red-200 bg-white px-3 text-sm font-medium text-red-700 outline-none hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-red-500 dark:border-red-900 dark:bg-zinc-900 dark:text-red-300 dark:hover:bg-red-950"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                    <dl className="grid gap-px border-t border-blue-900/10 bg-blue-900/10 text-xs sm:grid-cols-3 dark:border-blue-100/10 dark:bg-blue-100/10">
                        <div className="bg-blue-50/70 px-4 py-2 dark:bg-blue-950/20">
                            <dt className="font-medium text-zinc-500">
                                Access
                            </dt>
                            <dd className="mt-0.5 text-zinc-700 dark:text-zinc-200">
                                This browser session only
                            </dd>
                        </div>
                        <div className="bg-blue-50/70 px-4 py-2 dark:bg-blue-950/20">
                            <dt className="font-medium text-zinc-500">
                                Temporary retention
                            </dt>
                            <dd className="mt-0.5 text-zinc-700 dark:text-zinc-200">
                                Deleted after {retention}
                            </dd>
                        </div>
                        <div className="bg-blue-50/70 px-4 py-2 dark:bg-blue-950/20">
                            <dt className="font-medium text-zinc-500">
                                Stored output
                            </dt>
                            <dd className="mt-0.5 text-zinc-700 dark:text-zinc-200">
                                {formatBytes(conversion.totalBytes)}
                            </dd>
                        </div>
                    </dl>
                </div>

                {pollExpired && (
                    <p className="rounded-md border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">
                        Still working. Refresh to check again.
                    </p>
                )}

                <div className="grid flex-1 items-start gap-3 xl:grid-cols-[minmax(22rem,0.68fr)_minmax(40rem,1.32fr)] 2xl:grid-cols-[minmax(24rem,0.62fr)_minmax(48rem,1.38fr)]">
                    <section className="flex min-h-0 flex-col rounded-lg border border-blue-900/10 bg-white shadow-sm shadow-blue-900/5 dark:border-blue-100/10 dark:bg-zinc-900">
                        <div className="flex items-center justify-between gap-3 border-b border-blue-900/10 px-4 py-3 dark:border-blue-100/10">
                            <h2 className="text-sm font-semibold">
                                Input image
                            </h2>
                            <span className="font-mono text-[11px] text-zinc-500">
                                a{selectedAttempt.n}
                            </span>
                        </div>
                        <div className="p-3">
                            <div className="flex aspect-[16/10] items-center justify-center overflow-hidden rounded-md bg-zinc-100 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                                {selectedAttempt.inputPreview ? (
                                    <img
                                        src={selectedAttempt.inputPreview}
                                        alt="Uploaded source"
                                        className="h-full w-full object-contain"
                                    />
                                ) : (
                                    <span className="text-sm text-zinc-500">
                                        No preview
                                    </span>
                                )}
                            </div>
                        </div>
                        <dl className="grid grid-cols-2 gap-px border-y border-zinc-200 bg-zinc-200 text-sm dark:border-zinc-800 dark:bg-zinc-800">
                            <div className="min-w-0 bg-white px-4 py-3 dark:bg-zinc-900">
                                <dt className="text-xs font-medium text-zinc-500">
                                    File
                                </dt>
                                <dd className="mt-0.5 truncate font-medium">
                                    {selectedAttempt.displayFilename ??
                                        'Unknown'}
                                </dd>
                            </div>
                            <div className="bg-white px-4 py-3 dark:bg-zinc-900">
                                <dt className="text-xs font-medium text-zinc-500">
                                    Input
                                </dt>
                                <dd className="mt-0.5 font-medium">
                                    {formatBytes(selectedAttempt.inputBytes)}
                                </dd>
                            </div>
                        </dl>
                        <div className="flex flex-wrap gap-2 p-3">
                            {attempts.map((attempt) => (
                                <Link
                                    key={attempt.id}
                                    href={attempt.url}
                                    className={`rounded-md border px-2.5 py-1.5 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-zinc-500 ${
                                        attempt.selected
                                            ? 'border-zinc-950 bg-zinc-950 text-white dark:border-zinc-50 dark:bg-zinc-50 dark:text-zinc-950'
                                            : 'border-zinc-300 bg-white text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800'
                                    }`}
                                >
                                    {attempt.label} ({attempt.displayStatus})
                                </Link>
                            ))}
                        </div>
                    </section>

                    <section className="flex min-h-[34rem] flex-col rounded-lg border border-blue-900/10 bg-white shadow-sm shadow-blue-900/5 xl:self-stretch dark:border-blue-100/10 dark:bg-zinc-900">
                        <div className="flex flex-col justify-between gap-3 border-b border-blue-900/10 px-4 py-3 sm:flex-row sm:items-center dark:border-blue-100/10">
                            <div>
                                <h2 className="text-sm font-semibold">
                                    Output preview
                                </h2>
                                <p className="mt-0.5 text-xs text-zinc-500">
                                    PDF render of the generated deck
                                </p>
                            </div>
                            <div className="text-xs text-zinc-500">
                                {selectedAttempt.pptxBytes
                                    ? `${formatBytes(selectedAttempt.pptxBytes)} pptx`
                                    : 'Awaiting output'}
                                {selectedAttempt.pdfBytes
                                    ? ` / ${formatBytes(selectedAttempt.pdfBytes)} pdf`
                                    : ''}
                            </div>
                        </div>

                        <OutputState attempt={selectedAttempt} />
                    </section>
                </div>
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
                                className="rounded-md border border-zinc-300 px-3 py-2 text-sm outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-zinc-500 dark:border-zinc-700 dark:hover:bg-zinc-800"
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
}: Readonly<{
    attempt: ConversionPageProps['selectedAttempt'];
}>) {
    if (attempt.status === 'pending' || attempt.status === 'running') {
        return (
            <div className="m-3 flex min-h-[28rem] flex-1 flex-col items-center justify-center gap-3 rounded-md bg-zinc-100 p-6 text-center ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                <div className="h-2.5 w-48 animate-pulse rounded-full bg-zinc-300 dark:bg-zinc-700" />
                <div className="h-2.5 w-64 animate-pulse rounded-full bg-zinc-300 dark:bg-zinc-700" />
                <p className="text-sm text-zinc-600 dark:text-zinc-300">
                    Generating. This usually takes 5-20 seconds.
                </p>
            </div>
        );
    }

    if (attempt.status === 'failed') {
        return (
            <div className="m-3 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                {attempt.failureMessage ??
                    'We could not convert this image. Please try a different image.'}
            </div>
        );
    }

    if (attempt.displayStatus === 'partial') {
        return (
            <div className="m-3 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                PDF rendering failed; the .pptx file is still available.
            </div>
        );
    }

    if (!attempt.downloads.pdfInline) {
        const message =
            attempt.failureCode === null
                ? 'PowerPoint deck is ready. PDF preview is disabled for this local setup.'
                : 'PDF preview unavailable.';

        return (
            <div className="m-3 rounded-md border border-zinc-200 bg-zinc-50 p-4 text-sm text-zinc-700 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-200">
                {message}
            </div>
        );
    }

    return (
        <object
            data={attempt.downloads.pdfInline}
            type="application/pdf"
            className="m-3 min-h-[34rem] w-full flex-1 rounded-md border border-zinc-200 bg-zinc-100 dark:border-zinc-800"
        >
            <div className="p-4 text-sm text-zinc-700 dark:text-zinc-200">
                Your browser blocks inline PDF; download to view.
            </div>
        </object>
    );
}

function StatusBadge({
    status,
}: Readonly<{
    status: ConversionPageProps['selectedAttempt']['displayStatus'];
}>) {
    const className = statusBadgeClassName(status);

    return (
        <span
            className={`rounded-full px-2 py-1 text-xs font-medium ${className}`}
        >
            {status}
        </span>
    );
}

function statusBadgeClassName(
    status: ConversionPageProps['selectedAttempt']['displayStatus'],
) {
    switch (status) {
        case 'ready':
            return 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-100';
        case 'failed':
            return 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-200';
        case 'partial':
            return 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-200';
        default:
            return 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-100';
    }
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
