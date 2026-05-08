import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { DragEvent, FormEvent } from 'react';

import { AppShell } from '@/components/app-shell';
import { store } from '@/routes/uploads';
import type { RecentConversion } from '@/types/conversion';

type HomeProps = Readonly<{
    uploadNonce: string;
    ttlHours: number;
    maxBatchUploads: number;
    recentConversions: readonly RecentConversion[];
}>;

type QueueStatus = 'waiting' | 'uploading' | 'done' | 'failed';

type QueuedImage = {
    id: string;
    file: File;
    status: QueueStatus;
};

type UploadResponse = {
    redirect: string;
    uploadNonce: string;
};

const trustItems = [
    ['Editable .pptx', 'Move text, shapes, and charts'],
    ['Free to use', 'No account needed'],
    ['Private by design', 'Session-only storage'],
] as const;

const workflowSteps = [
    [
        'Create',
        'Make a slide image with ChatGPT, Nano Banana, or any design tool.',
    ],
    [
        'Convert',
        'Scofle reconstructs the image as an editable PowerPoint deck.',
    ],
    [
        'Download',
        'Keep the generated .pptx before the temporary session expires.',
    ],
] as const;

export default function Home({
    uploadNonce,
    ttlHours,
    maxBatchUploads,
    recentConversions,
}: HomeProps) {
    const uploadInputId = 'image-upload-input';
    const retention = ttlHours === 1 ? '1 hour' : `${ttlHours} hours`;
    const [clientError, setClientError] = useState<string | null>(null);
    const [serverError, setServerError] = useState<string | null>(null);
    const [nonce, setNonce] = useState(uploadNonce);
    const [queuedImages, setQueuedImages] = useState<QueuedImage[]>([]);
    const [isUploading, setIsUploading] = useState(false);
    const submitLabel = uploadButtonLabel(isUploading, queuedImages.length);

    function selectFiles(files: FileList | null) {
        setClientError(null);
        setServerError(null);

        if (!files || files.length === 0) {
            return;
        }

        const selectedFiles = Array.from(files);

        if (queuedImages.length + selectedFiles.length > maxBatchUploads) {
            setClientError(`Upload at most ${maxBatchUploads} images at once.`);

            return;
        }

        const invalidFile = selectedFiles.find(
            (file) => !['image/png', 'image/jpeg'].includes(file.type),
        );

        if (invalidFile) {
            setClientError('Only PNG and JPEG images are accepted.');

            return;
        }

        setQueuedImages((images) => [
            ...images,
            ...selectedFiles.map((file, index) => ({
                id: `${file.name}-${file.size}-${file.lastModified}-${Date.now()}-${index}`,
                file,
                status: 'waiting' as const,
            })),
        ]);
    }

    function removeFile(id: string) {
        setClientError(null);
        setServerError(null);
        setQueuedImages((images) => images.filter((image) => image.id !== id));
    }

    function onDrop(event: DragEvent<HTMLLabelElement>) {
        event.preventDefault();
        const hasDirectory = Array.from(event.dataTransfer.items).some(
            (item) => item.kind !== 'file',
        );

        if (hasDirectory) {
            setClientError('Drag an image, not a folder.');

            return;
        }

        selectFiles(event.dataTransfer.files);
    }

    async function submit(event: FormEvent) {
        event.preventDefault();

        if (queuedImages.length === 0) {
            setClientError('Choose one or more PNG or JPEG images.');

            return;
        }

        setIsUploading(true);
        setClientError(null);
        setServerError(null);

        let nextNonce = nonce;
        let firstRedirect: string | null = null;

        for (const queuedImage of queuedImages) {
            setQueuedImageStatus(queuedImage.id, 'uploading');

            try {
                const response = await uploadImage(queuedImage.file, nextNonce);
                firstRedirect ??= response.redirect;
                nextNonce = response.uploadNonce;
                setQueuedImageStatus(queuedImage.id, 'done');
            } catch (error) {
                setQueuedImageStatus(queuedImage.id, 'failed');
                setNonce(nextNonce);
                setIsUploading(false);
                setServerError(
                    error instanceof Error
                        ? error.message
                        : 'Upload failed. Try again.',
                );

                return;
            }
        }

        if (firstRedirect) {
            router.visit(firstRedirect);

            return;
        }

        setNonce(nextNonce);
        setIsUploading(false);
    }

    function setQueuedImageStatus(id: string, status: QueueStatus) {
        setQueuedImages((images) =>
            images.map((image) =>
                image.id === id ? { ...image, status } : image,
            ),
        );
    }

    return (
        <AppShell recentConversions={recentConversions}>
            <Head title="Convert images to editable PowerPoint slides" />
            <section className="grid min-h-[calc(100vh-9rem)] items-stretch gap-4 xl:grid-cols-[minmax(30rem,0.96fr)_minmax(34rem,1.04fr)]">
                <div className="overflow-hidden rounded-lg border border-blue-900/10 bg-[#f8fbff] shadow-sm shadow-blue-900/5 dark:border-blue-100/10 dark:bg-zinc-900">
                    <div className="grid gap-0 lg:grid-cols-[minmax(0,1fr)_17rem] xl:grid-cols-1 2xl:grid-cols-[minmax(0,1fr)_18rem]">
                        <div className="p-5 sm:p-7">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="rounded-full bg-blue-700 px-3 py-1 text-xs font-semibold tracking-normal text-white uppercase dark:bg-blue-300 dark:text-blue-950">
                                    AI slides, now editable
                                </span>
                                <span className="rounded-full bg-[#ffe1d8] px-3 py-1 text-xs font-semibold tracking-normal text-[#8c2f1f] uppercase dark:bg-[#3d1d18] dark:text-[#ffb29f]">
                                    Free
                                </span>
                            </div>
                            <h1 className="mt-5 max-w-3xl text-4xl leading-[1.05] font-semibold tracking-normal text-zinc-950 sm:text-5xl dark:text-zinc-50">
                                Convert your images to editable PowerPoint
                                slides.
                            </h1>
                            <p className="mt-5 max-w-2xl text-base leading-7 text-zinc-700 dark:text-zinc-300">
                                Create amazing slides with AI tools like ChatGPT
                                or Nano Banana, then turn those image-only
                                results into editable PowerPoint decks.
                            </p>
                            <div className="mt-6 grid gap-2 sm:grid-cols-3">
                                {trustItems.map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="rounded-md border border-blue-900/10 bg-white/75 px-3 py-2 shadow-xs dark:border-blue-100/10 dark:bg-zinc-950"
                                    >
                                        <p className="text-[11px] font-medium text-zinc-500">
                                            {label}
                                        </p>
                                        <p className="mt-0.5 text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                            {value}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <SlideWorkbenchPreview />
                    </div>

                    <div className="grid border-t border-blue-900/10 bg-white/65 lg:grid-cols-3 dark:border-blue-100/10 dark:bg-zinc-950/50">
                        {workflowSteps.map(([label, copy], index) => (
                            <div
                                key={label}
                                className="border-blue-900/10 p-4 lg:border-r lg:last:border-r-0 dark:border-blue-100/10"
                            >
                                <div className="flex items-center gap-2">
                                    <span className="grid size-7 place-items-center rounded-md bg-amber-200 text-sm font-semibold text-amber-950 dark:bg-amber-900 dark:text-amber-100">
                                        {index + 1}
                                    </span>
                                    <p className="text-sm font-semibold">
                                        {label}
                                    </p>
                                </div>
                                <p className="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                                    {copy}
                                </p>
                            </div>
                        ))}
                    </div>

                    <div className="border-t border-sky-900/10 bg-sky-50 px-5 py-4 dark:border-sky-100/10 dark:bg-sky-950/30">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-base font-semibold text-sky-950 dark:text-sky-100">
                                    No data stays on our server.
                                </h2>
                                <p className="mt-1 text-sm leading-6 text-sky-900/80 dark:text-sky-100/80">
                                    Temporary files are deleted after{' '}
                                    {retention}; you can delete them sooner from
                                    the session sidebar.
                                </p>
                            </div>
                            <span className="inline-flex w-fit rounded-full border border-sky-200 bg-white px-3 py-1 text-xs font-semibold text-sky-800 dark:border-sky-900 dark:bg-zinc-950 dark:text-sky-100">
                                Session-only
                            </span>
                        </div>
                    </div>
                </div>

                <form
                    onSubmit={submit}
                    className="flex min-h-[34rem] flex-col rounded-lg border border-blue-900/10 bg-white shadow-sm shadow-blue-900/5 dark:border-blue-100/10 dark:bg-zinc-900"
                >
                    <div className="border-b border-blue-900/10 bg-blue-50/70 px-4 py-4 dark:border-blue-100/10 dark:bg-blue-950/20">
                        <h2 className="text-lg font-semibold tracking-normal">
                            Start with one or more images
                        </h2>
                        <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            PNG or JPEG. Up to 8 MB, up to 4096 px on the
                            longest side. Batch uploads are processed one by
                            one.
                        </p>
                    </div>

                    <input
                        id={uploadInputId}
                        type="file"
                        name="images[]"
                        accept="image/png,image/jpeg"
                        multiple
                        className="sr-only"
                        onChange={(event) => {
                            selectFiles(event.currentTarget.files);
                            event.currentTarget.value = '';
                        }}
                    />

                    <div className="flex flex-1 flex-col gap-3 p-3">
                        <label
                            htmlFor={uploadInputId}
                            onDragOver={(event) => event.preventDefault()}
                            onDrop={onDrop}
                            className="flex min-h-72 cursor-pointer flex-col items-center justify-center gap-4 rounded-md border border-dashed border-blue-300 bg-[#f8fbff] px-6 py-10 text-center transition hover:border-blue-500 hover:bg-blue-50 dark:border-blue-800 dark:bg-zinc-950/50 dark:hover:bg-blue-950/20"
                        >
                            <UploadMark />
                            <span className="rounded-md border border-blue-200 bg-white px-3 py-2 text-sm font-medium text-blue-900 shadow-sm dark:border-blue-800 dark:bg-zinc-900 dark:text-blue-100">
                                Drop images here or click to browse
                            </span>
                            <span className="max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                                Great for image exports from ChatGPT, Nano
                                Banana, screenshots, and design mockups.
                            </span>
                        </label>

                        {queuedImages.length > 0 && (
                            <div className="rounded-md border border-blue-900/10 bg-white text-left dark:border-blue-100/10 dark:bg-zinc-900">
                                <div className="border-b border-blue-900/10 px-3 py-2 text-xs font-medium text-blue-800 dark:border-blue-100/10 dark:text-blue-200">
                                    {queuedImages.length} queued
                                </div>
                                <ul className="max-h-52 overflow-y-auto">
                                    {queuedImages.map((queuedImage) => (
                                        <li
                                            key={queuedImage.id}
                                            className="flex items-center justify-between gap-3 border-b border-zinc-100 px-3 py-2 last:border-b-0 dark:border-zinc-800"
                                        >
                                            <div className="flex min-w-0 items-center gap-2">
                                                <QueuedFileStatusIcon
                                                    status={queuedImage.status}
                                                />
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">
                                                        {queuedImage.file.name}
                                                    </p>
                                                    <p className="text-xs text-zinc-500">
                                                        {queuedImage.status} /{' '}
                                                        {formatBytes(
                                                            queuedImage.file
                                                                .size,
                                                        )}
                                                    </p>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    removeFile(queuedImage.id)
                                                }
                                                disabled={isUploading}
                                                className="rounded-md border border-zinc-300 px-2 py-1 text-xs text-zinc-600 outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                            >
                                                Remove
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {(clientError || serverError) && (
                            <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                                {clientError || serverError}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-col gap-3 border-t border-blue-900/10 px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-blue-100/10">
                        <p className="text-xs leading-5 text-zinc-500">
                            This browser session only. Deleted after {retention}
                            . Download outputs you want to keep.
                        </p>
                        <button
                            type="submit"
                            disabled={isUploading}
                            className="inline-flex h-10 items-center justify-center rounded-md bg-blue-700 px-4 text-sm font-semibold text-white shadow-sm shadow-blue-900/20 outline-none hover:bg-blue-800 focus-visible:ring-2 focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-blue-300 dark:text-blue-950 dark:hover:bg-blue-200"
                        >
                            {submitLabel}
                        </button>
                    </div>
                </form>
            </section>
        </AppShell>
    );
}

function SlideWorkbenchPreview() {
    return (
        <div className="flex min-h-full items-center justify-center border-t border-blue-900/10 bg-blue-800 p-5 lg:border-t-0 lg:border-l xl:border-t 2xl:border-t-0 2xl:border-l dark:border-blue-100/10 dark:bg-blue-950">
            <div className="w-full max-w-sm">
                <div className="rounded-lg bg-white p-3 shadow-xl shadow-blue-950/20 dark:bg-zinc-900">
                    <div className="mb-3 flex items-center justify-between">
                        <div className="flex gap-1.5">
                            <span className="size-2.5 rounded-full bg-[#ff8a65]" />
                            <span className="size-2.5 rounded-full bg-amber-300" />
                            <span className="size-2.5 rounded-full bg-blue-400" />
                        </div>
                        <span className="rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold text-sky-800 dark:bg-sky-950 dark:text-sky-100">
                            Editable .pptx
                        </span>
                    </div>
                    <div className="grid gap-2">
                        <div className="rounded-md border border-zinc-200 bg-[#fff8ee] p-3 dark:border-zinc-800 dark:bg-zinc-950">
                            <div className="mb-3 h-3 w-28 rounded-full bg-zinc-900 dark:bg-zinc-100" />
                            <div className="grid grid-cols-[1fr_4rem] gap-3">
                                <div className="space-y-2">
                                    <div className="h-2 rounded-full bg-blue-600" />
                                    <div className="h-2 w-4/5 rounded-full bg-amber-300" />
                                    <div className="h-2 w-2/3 rounded-full bg-[#ff8a65]" />
                                </div>
                                <div className="grid grid-cols-2 gap-1">
                                    <span className="rounded bg-blue-100" />
                                    <span className="rounded bg-amber-100" />
                                    <span className="rounded bg-[#ffe1d8]" />
                                    <span className="rounded bg-sky-100" />
                                </div>
                            </div>
                        </div>
                        <div className="flex items-center justify-between rounded-md border border-dashed border-blue-300 bg-blue-50 px-3 py-2 dark:border-blue-800 dark:bg-blue-950/50">
                            <span className="text-xs font-medium text-blue-900 dark:text-blue-100">
                                Image in
                            </span>
                            <span className="text-xs font-medium text-blue-900 dark:text-blue-100">
                                Shapes out
                            </span>
                        </div>
                    </div>
                </div>
                <div className="mt-3 grid grid-cols-3 gap-2 text-center text-[10px] font-semibold text-blue-50">
                    <span className="rounded-full bg-white/15 px-2 py-1">
                        Text
                    </span>
                    <span className="rounded-full bg-white/15 px-2 py-1">
                        Charts
                    </span>
                    <span className="rounded-full bg-white/15 px-2 py-1">
                        Layout
                    </span>
                </div>
            </div>
        </div>
    );
}

function UploadMark() {
    return (
        <span className="grid size-14 place-items-center rounded-full bg-blue-700 text-white shadow-lg shadow-blue-900/20 dark:bg-blue-300 dark:text-blue-950">
            <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                className="size-7 fill-none stroke-current stroke-2"
            >
                <path d="M12 16V5" strokeLinecap="round" />
                <path d="m7 10 5-5 5 5" strokeLinecap="round" />
                <path d="M5 19h14" strokeLinecap="round" />
            </svg>
        </span>
    );
}

async function uploadImage(file: File, nonce: string): Promise<UploadResponse> {
    const csrfToken = document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content');

    if (!csrfToken) {
        throw new Error('This upload form expired. Refresh and try again.');
    }

    const body = new FormData();
    body.append('images[]', file);
    body.append('nonce', nonce);

    const response = await fetch(store.url(), {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (response.status === 413) {
        throw new Error(
            'The selected image is larger than the local web server accepts.',
        );
    }

    if (!response.ok) {
        const payload = await parseJson(response);

        throw new Error(validationMessage(payload));
    }

    return (await response.json()) as UploadResponse;
}

async function parseJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

function validationMessage(payload: unknown): string {
    if (!payload || typeof payload !== 'object') {
        return 'Upload failed. Try again.';
    }

    const errors = 'errors' in payload ? payload.errors : null;

    if (errors && typeof errors === 'object') {
        const typedErrors = errors as Record<string, string[] | string>;
        const message =
            firstError(typedErrors.images) ??
            firstError(typedErrors['images.0']) ??
            firstError(typedErrors.nonce);

        if (message) {
            return message;
        }
    }

    if ('message' in payload && typeof payload.message === 'string') {
        return payload.message;
    }

    return 'Upload failed. Try again.';
}

function firstError(error: string[] | string | undefined): string | null {
    if (typeof error === 'string') {
        return error;
    }

    return error?.[0] ?? null;
}

function QueuedFileStatusIcon({ status }: Readonly<{ status: QueueStatus }>) {
    const className = queuedStatusClassName(status);

    return (
        <span
            aria-label={status}
            title={status}
            className={`size-2.5 shrink-0 rounded-full border ${className}`}
        />
    );
}

function queuedStatusClassName(status: QueueStatus) {
    switch (status) {
        case 'uploading':
            return 'animate-pulse border-blue-700 bg-blue-700 dark:border-blue-300 dark:bg-blue-300';
        case 'done':
            return 'border-sky-700 bg-sky-700 dark:border-sky-300 dark:bg-sky-300';
        case 'failed':
            return 'border-red-500 bg-white dark:bg-zinc-900';
        default:
            return 'border-amber-500 bg-amber-100 dark:bg-amber-950';
    }
}

function uploadButtonLabel(isUploading: boolean, imageCount: number) {
    if (isUploading) {
        return 'Uploading...';
    }

    if (imageCount === 0) {
        return 'Convert images';
    }

    return `Convert ${imageCount} image${imageCount === 1 ? '' : 's'}`;
}

function formatBytes(bytes: number) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
