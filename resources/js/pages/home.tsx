import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { DragEvent, FormEvent } from 'react';

import { AppShell } from '@/components/app-shell';
import { store } from '@/routes/uploads';
import type { RecentConversion } from '@/types/conversion';

type HomeProps = {
    uploadNonce: string;
    ttlHours: number;
    maxBatchUploads: number;
    recentConversions: RecentConversion[];
};

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

export default function Home({
    uploadNonce,
    ttlHours,
    maxBatchUploads,
    recentConversions,
}: HomeProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const retention = ttlHours === 1 ? '1 hour' : `${ttlHours} hours`;
    const [clientError, setClientError] = useState<string | null>(null);
    const [serverError, setServerError] = useState<string | null>(null);
    const [nonce, setNonce] = useState(uploadNonce);
    const [queuedImages, setQueuedImages] = useState<QueuedImage[]>([]);
    const [isUploading, setIsUploading] = useState(false);

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

    function onDrop(event: DragEvent<HTMLDivElement>) {
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
            <Head title="Upload" />
            <section className="min-h-[calc(100vh-5.5rem)]">
                <form
                    onSubmit={submit}
                    className="flex min-h-[32rem] flex-col rounded-lg border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900"
                >
                    <div className="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <h1 className="text-lg font-semibold tracking-normal">
                            Convert images to PowerPoint
                        </h1>
                        <p className="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                            PNG or JPEG. Up to 10 MB, up to 4096 px on the
                            longest side.
                        </p>
                    </div>

                    <input
                        ref={inputRef}
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
                        <div
                            role="button"
                            tabIndex={0}
                            onClick={() => inputRef.current?.click()}
                            onKeyDown={(event) => {
                                if (
                                    event.key === 'Enter' ||
                                    event.key === ' '
                                ) {
                                    event.preventDefault();
                                    inputRef.current?.click();
                                }
                            }}
                            onDragOver={(event) => event.preventDefault()}
                            onDrop={onDrop}
                            className="flex min-h-[24rem] flex-1 cursor-pointer flex-col items-center justify-center gap-4 rounded-md border border-dashed border-zinc-300 bg-zinc-50 px-6 py-10 text-center transition outline-none hover:border-zinc-500 hover:bg-white focus-visible:ring-2 focus-visible:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-950/40 dark:hover:bg-zinc-950"
                        >
                            <div className="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                                Drop images here or click to browse
                            </div>
                            {queuedImages.length > 0 ? (
                                <div className="w-full max-w-2xl rounded-md border border-zinc-200 bg-white text-left dark:border-zinc-800 dark:bg-zinc-900">
                                    <div className="border-b border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-500 dark:border-zinc-800">
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
                                                        status={
                                                            queuedImage.status
                                                        }
                                                    />
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium text-zinc-800 dark:text-zinc-100">
                                                            {
                                                                queuedImage.file
                                                                    .name
                                                            }
                                                        </p>
                                                        <p className="text-xs text-zinc-500">
                                                            {queuedImage.status}{' '}
                                                            /{' '}
                                                            {formatBytes(
                                                                queuedImage.file
                                                                    .size,
                                                            )}
                                                        </p>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={(event) => {
                                                        event.stopPropagation();
                                                        removeFile(
                                                            queuedImage.id,
                                                        );
                                                    }}
                                                    disabled={isUploading}
                                                    className="rounded-md border border-zinc-300 px-2 py-1 text-xs text-zinc-600 outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-zinc-500 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                >
                                                    Remove
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : (
                                <p className="max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                                    Files are temporary. Download generated
                                    decks you want to keep.
                                </p>
                            )}
                        </div>

                        {(clientError || serverError) && (
                            <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                                {clientError || serverError}
                            </p>
                        )}
                    </div>

                    <div className="flex items-center justify-between gap-3 border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <p className="text-xs text-zinc-500">
                            This browser session only. Deleted after {retention}
                            . Download outputs you want to keep.
                        </p>
                        <button
                            type="submit"
                            disabled={isUploading}
                            className="inline-flex h-9 items-center justify-center rounded-md bg-zinc-950 px-4 text-sm font-medium text-white outline-none hover:bg-zinc-800 focus-visible:ring-2 focus-visible:ring-zinc-500 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-zinc-50 dark:text-zinc-950 dark:hover:bg-zinc-200"
                        >
                            {isUploading
                                ? 'Uploading...'
                                : queuedImages.length > 0
                                  ? `Convert ${queuedImages.length} image${queuedImages.length === 1 ? '' : 's'}`
                                  : 'Convert images'}
                        </button>
                    </div>
                </form>
            </section>
        </AppShell>
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

function QueuedFileStatusIcon({ status }: { status: QueueStatus }) {
    const className =
        status === 'uploading'
            ? 'animate-pulse border-zinc-700 bg-zinc-700 dark:border-zinc-300 dark:bg-zinc-300'
            : status === 'done'
              ? 'border-zinc-950 bg-zinc-950 dark:border-zinc-50 dark:bg-zinc-50'
              : status === 'failed'
                ? 'border-red-500 bg-white dark:bg-zinc-900'
                : 'border-zinc-400 bg-white dark:bg-zinc-900';

    return (
        <span
            aria-label={status}
            title={status}
            className={`size-2.5 shrink-0 rounded-full border ${className}`}
        />
    );
}

function formatBytes(bytes: number) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
