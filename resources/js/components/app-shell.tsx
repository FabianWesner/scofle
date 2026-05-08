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
        <div className="min-h-screen bg-zinc-100 text-zinc-950 dark:bg-zinc-950 dark:text-zinc-50">
            <header className="sticky top-0 z-30 border-b border-zinc-200 bg-white/95 px-4 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">
                <div className="flex h-12 items-center justify-between gap-4">
                    <Link
                        href={home.url()}
                        className="flex items-center gap-2 rounded-sm text-sm font-semibold tracking-normal text-zinc-950 outline-none focus-visible:ring-2 focus-visible:ring-zinc-500 dark:text-zinc-50"
                    >
                        <span className="grid size-6 place-items-center rounded-md bg-zinc-950 text-[10px] font-bold text-white dark:bg-zinc-50 dark:text-zinc-950">
                            IP
                        </span>
                        <span>image-to-powerpoint</span>
                    </Link>
                    <span className="rounded-full border border-zinc-200 bg-zinc-50 px-2.5 py-1 text-xs font-medium text-zinc-500 dark:border-zinc-800 dark:bg-zinc-950 dark:text-zinc-400">
                        this browser session
                    </span>
                </div>
            </header>

            <div className="grid min-h-[calc(100vh-49px)] grid-cols-1 lg:grid-cols-[15rem_minmax(0,1fr)]">
                <aside className="border-b border-zinc-200 bg-zinc-50 px-3 py-4 lg:sticky lg:top-12 lg:h-[calc(100vh-49px)] lg:overflow-y-auto lg:border-r lg:border-b-0 dark:border-zinc-800 dark:bg-zinc-900">
                    <div className="flex items-center justify-between gap-3 px-2">
                        <h2 className="text-[11px] font-semibold tracking-normal text-zinc-500 uppercase dark:text-zinc-400">
                            Recent
                        </h2>
                        <div className="flex items-center gap-2">
                            <span className="text-[11px] text-zinc-400">
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
                            <p className="px-2 text-sm text-zinc-500 dark:text-zinc-400">
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
                                                ? 'border-l-zinc-950 bg-white text-zinc-950 shadow-xs dark:border-l-zinc-50 dark:bg-zinc-950 dark:text-zinc-50'
                                                : 'border-l-transparent text-zinc-600 hover:bg-white hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-zinc-950 dark:hover:text-zinc-50'
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
                </main>
            </div>
        </div>
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
            return 'border-zinc-400 bg-white dark:bg-zinc-900';
        case 'running':
            return 'animate-pulse border-zinc-700 bg-zinc-700 dark:border-zinc-300 dark:bg-zinc-300';
        case 'failed':
            return 'border-red-500 bg-white dark:bg-zinc-900';
        default:
            return 'border-zinc-950 bg-zinc-950 dark:border-zinc-50 dark:bg-zinc-50';
    }
}
