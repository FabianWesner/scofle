import { Head, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { DragEvent, FormEvent } from 'react';

import { AppShell } from '@/components/app-shell';
import { store } from '@/routes/uploads';
import type { RecentConversion } from '@/types/conversion';

type HomeProps = {
    uploadNonce: string;
    ttlHours: number;
    recentConversions: RecentConversion[];
};

type UploadForm = {
    image: File | null;
    nonce: string;
};

export default function Home({
    uploadNonce,
    ttlHours,
    recentConversions,
}: HomeProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [clientError, setClientError] = useState<string | null>(null);
    const form = useForm<UploadForm>({
        image: null,
        nonce: uploadNonce,
    });

    function selectFiles(files: FileList | null) {
        setClientError(null);

        if (!files || files.length === 0) {
            return;
        }

        if (files.length > 1) {
            setClientError('Only one image per upload.');

            return;
        }

        const file = files.item(0);

        if (!file) {
            return;
        }

        if (!['image/png', 'image/jpeg'].includes(file.type)) {
            setClientError('Only PNG and JPEG images are accepted.');

            return;
        }

        form.setData('image', file);
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

    function submit(event: FormEvent) {
        event.preventDefault();

        if (!form.data.image) {
            setClientError('Choose a PNG or JPEG image.');

            return;
        }

        form.post(store.url(), {
            forceFormData: true,
            preserveScroll: true,
            onError: () => setClientError(null),
        });
    }

    return (
        <AppShell recentConversions={recentConversions}>
            <Head title="Upload" />
            <section className="mx-auto flex max-w-3xl flex-col gap-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-normal">
                        Convert an image into a PowerPoint deck
                    </h1>
                    <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        PNG or JPEG. Up to 10 MB, up to 4096 px on the longest
                        side.
                    </p>
                </div>

                <form onSubmit={submit} className="flex flex-col gap-4">
                    <input
                        ref={inputRef}
                        type="file"
                        name="image"
                        accept="image/png,image/jpeg"
                        className="sr-only"
                        onChange={(event) => selectFiles(event.target.files)}
                    />
                    <input type="hidden" name="nonce" value={form.data.nonce} />

                    <div
                        role="button"
                        tabIndex={0}
                        onClick={() => inputRef.current?.click()}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                inputRef.current?.click();
                            }
                        }}
                        onDragOver={(event) => event.preventDefault()}
                        onDrop={onDrop}
                        className="flex min-h-72 cursor-pointer flex-col items-center justify-center gap-4 rounded-lg border-2 border-dashed border-zinc-300 bg-white px-6 py-10 text-center transition outline-none hover:border-blue-500 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <div className="rounded-full bg-blue-50 px-4 py-2 text-sm font-medium text-blue-700 dark:bg-blue-950 dark:text-blue-200">
                            Drop an image here or click to browse
                        </div>
                        {form.data.image ? (
                            <div className="text-sm text-zinc-700 dark:text-zinc-200">
                                <span className="font-medium">
                                    {form.data.image.name}
                                </span>
                                <span className="ml-2 text-zinc-500">
                                    {formatBytes(form.data.image.size)}
                                </span>
                            </div>
                        ) : (
                            <p className="text-sm text-zinc-500 dark:text-zinc-400">
                                Files are temporary. Download generated decks
                                you want to keep.
                            </p>
                        )}
                    </div>

                    {(clientError || form.errors.image) && (
                        <p className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                            {clientError || form.errors.image}
                        </p>
                    )}

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="inline-flex h-10 items-center justify-center rounded-md bg-blue-600 px-4 text-sm font-medium text-white outline-none hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {form.processing ? 'Uploading...' : 'Convert image'}
                    </button>
                </form>

                <footer className="text-sm text-zinc-600 dark:text-zinc-300">
                    Temporary files are deleted after {ttlHours} hours. Download
                    files you want to keep. Only this browser session can reopen
                    recent conversions.
                </footer>
            </section>
        </AppShell>
    );
}

function formatBytes(bytes: number) {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
