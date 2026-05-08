import { Link, router, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { home } from '@/routes';
import { destroyAll } from '@/routes/conversions';
import type { RecentConversion } from '@/types/conversion';

type AppShellProps = Readonly<
    PropsWithChildren<{
        recentConversions: readonly RecentConversion[];
    }>
>;

function deleteAllTemporaryFiles() {
    if (
        !globalThis.confirm(
            'Delete all temporary files in this browser session?',
        )
    ) {
        return;
    }

    router.delete(destroyAll.url(), {
        preserveScroll: true,
    });
}

export function AppShell({ recentConversions, children }: AppShellProps) {
    const page = usePage();
    const currentPath = page.url.split('?')[0] ?? page.url;

    return (
        <div className="min-h-screen bg-stone-50 text-zinc-950 dark:bg-zinc-950 dark:text-zinc-50">
            <header className="sticky top-0 z-30 border-b border-blue-900/10 bg-white/90 px-4 backdrop-blur dark:border-blue-100/10 dark:bg-zinc-950/90">
                <div className="flex h-14 items-center justify-between gap-4">
                    <Link
                        href={home.url()}
                        className="flex items-center gap-2 rounded-sm text-sm font-semibold tracking-normal text-zinc-950 outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-zinc-50"
                    >
                        <span className="grid size-7 place-items-center rounded-md bg-blue-700 text-[11px] font-bold text-white shadow-sm shadow-blue-900/20 dark:bg-blue-400 dark:text-blue-950">
                            S
                        </span>
                        <span>Scofle</span>
                    </Link>
                    <div className="flex items-center gap-2">
                        <a
                            href="https://github.com/FabianWesner/scofle"
                            target="_blank"
                            rel="noreferrer"
                            className="hidden items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-600 outline-none hover:border-blue-300 hover:text-blue-800 focus-visible:ring-2 focus-visible:ring-blue-500 sm:inline-flex dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:text-blue-200"
                        >
                            <GitHubIcon />
                            Open source
                        </a>
                        <span className="rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-800 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200">
                            this browser session
                        </span>
                    </div>
                </div>
            </header>

            <div className="grid min-h-[calc(100vh-57px)] grid-cols-1 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <aside className="border-b border-blue-900/10 bg-white/70 px-3 py-4 lg:sticky lg:top-14 lg:h-[calc(100vh-57px)] lg:overflow-y-auto lg:border-r lg:border-b-0 dark:border-blue-100/10 dark:bg-zinc-950/70">
                    <div className="flex items-center justify-between gap-3 px-2">
                        <div>
                            <h2 className="text-[11px] font-semibold tracking-normal text-zinc-500 uppercase dark:text-zinc-400">
                                Recent
                            </h2>
                            <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                Temporary session files
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="rounded-full bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-800 dark:bg-blue-950 dark:text-blue-200">
                                {recentConversions.length}
                            </span>
                            {recentConversions.length > 0 && (
                                <button
                                    type="button"
                                    onClick={deleteAllTemporaryFiles}
                                    className="rounded-sm px-1.5 py-0.5 text-[11px] font-medium text-red-600 outline-none hover:bg-red-50 focus-visible:ring-2 focus-visible:ring-red-500 dark:text-red-300 dark:hover:bg-red-950"
                                >
                                    Delete all
                                </button>
                            )}
                        </div>
                    </div>
                    <nav className="mt-3 flex flex-col gap-1">
                        {recentConversions.length === 0 ? (
                            <p className="rounded-md border border-dashed border-zinc-200 bg-stone-50 px-2.5 py-3 text-sm text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400">
                                No conversions in this session yet.
                            </p>
                        ) : (
                            recentConversions.map((conversion) => {
                                const active =
                                    currentPath === conversion.url ||
                                    currentPath.startsWith(
                                        `${conversion.url}/`,
                                    );

                                return (
                                    <Link
                                        key={conversion.uuid}
                                        href={conversion.url}
                                        className={`border-l-2 px-2.5 py-2 text-sm transition outline-none focus-visible:ring-2 focus-visible:ring-zinc-500 ${
                                            active
                                                ? 'border-l-blue-700 bg-white text-zinc-950 shadow-xs dark:border-l-blue-300 dark:bg-zinc-900 dark:text-zinc-50'
                                                : 'border-l-transparent text-zinc-600 hover:bg-white hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-zinc-900 dark:hover:text-zinc-50'
                                        }`}
                                    >
                                        <span className="flex items-center gap-2">
                                            <StatusIcon
                                                status={
                                                    conversion.displayStatus
                                                }
                                            />
                                            <span className="block min-w-0 truncate font-medium">
                                                {conversion.label}
                                            </span>
                                        </span>
                                        <span className="block font-mono text-[11px] text-zinc-400">
                                            {conversion.uuid.slice(0, 8)}
                                        </span>
                                    </Link>
                                );
                            })
                        )}
                    </nav>
                </aside>

                <main className="min-w-0 px-3 py-3 sm:px-4 lg:px-5">
                    {children}
                    <footer className="mt-5 flex flex-col gap-3 border-t border-blue-900/10 py-5 text-sm text-zinc-600 sm:flex-row sm:items-center sm:justify-between dark:border-blue-100/10 dark:text-zinc-300">
                        <p>
                            Scofle is an open-source MIT licensed private
                            project by{' '}
                            <a
                                href="https://www.linkedin.com/in/fabian-wesner/"
                                target="_blank"
                                rel="noreferrer"
                                className="font-medium text-blue-800 underline decoration-blue-300 underline-offset-4 outline-none hover:text-blue-950 focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-blue-200 dark:hover:text-blue-100"
                            >
                                Fabian Wesner
                            </a>
                            {'.'}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <a
                                href="https://github.com/FabianWesner/scofle"
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 outline-none hover:border-blue-300 hover:text-blue-800 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:text-blue-200"
                            >
                                <GitHubIcon />
                                Source code
                            </a>
                            <a
                                href="https://github.com/FabianWesner/scofle/blob/main/LICENSE"
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-xs font-medium text-zinc-700 outline-none hover:border-blue-300 hover:text-blue-800 focus-visible:ring-2 focus-visible:ring-blue-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:text-blue-200"
                            >
                                MIT license
                            </a>
                        </div>
                    </footer>
                </main>
            </div>
        </div>
    );
}

function GitHubIcon() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 16 16"
            className="size-3.5 fill-current"
        >
            <path d="M8 0C3.58 0 0 3.67 0 8.2c0 3.62 2.29 6.69 5.47 7.78.4.08.55-.18.55-.4v-1.54c-2.23.5-2.7-.98-2.7-.98-.36-.96-.89-1.22-.89-1.22-.73-.51.06-.5.06-.5.8.06 1.22.85 1.22.85.72 1.26 1.87.9 2.33.69.07-.53.28-.9.5-1.1-1.78-.21-3.64-.91-3.64-4.04 0-.9.31-1.63.82-2.2-.08-.21-.36-1.05.08-2.18 0 0 .67-.22 2.2.84A7.36 7.36 0 0 1 8 3.93c.68 0 1.36.09 2 .27 1.52-1.06 2.19-.84 2.19-.84.44 1.13.16 1.97.08 2.18.51.57.82 1.3.82 2.2 0 3.14-1.87 3.83-3.65 4.03.29.26.54.76.54 1.52v2.29c0 .22.14.48.55.4A8.14 8.14 0 0 0 16 8.2C16 3.67 12.42 0 8 0Z" />
        </svg>
    );
}

function StatusIcon({
    status,
}: Readonly<{ status: RecentConversion['displayStatus'] }>) {
    const title = statusTitle(status);
    const className = statusIconClassName(status);

    return (
        <span
            aria-label={title}
            title={title}
            className={`size-2.5 shrink-0 rounded-full border ${className}`}
        />
    );
}

function statusTitle(status: RecentConversion['displayStatus']) {
    switch (status) {
        case 'pending':
            return 'waiting';
        case 'running':
            return 'processing';
        case 'failed':
            return 'failed';
        default:
            return 'done';
    }
}

function statusIconClassName(status: RecentConversion['displayStatus']) {
    switch (status) {
        case 'pending':
            return 'border-amber-500 bg-amber-100 dark:bg-amber-950';
        case 'running':
            return 'animate-pulse border-blue-700 bg-blue-700 dark:border-blue-300 dark:bg-blue-300';
        case 'failed':
            return 'border-red-500 bg-white dark:bg-zinc-900';
        default:
            return 'border-sky-700 bg-sky-700 dark:border-sky-300 dark:bg-sky-300';
    }
}
