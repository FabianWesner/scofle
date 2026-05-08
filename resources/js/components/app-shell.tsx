import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { home } from '@/routes';
import type { RecentConversion } from '@/types/conversion';

type AppShellProps = PropsWithChildren<{
    recentConversions: RecentConversion[];
}>;

export function AppShell({ recentConversions, children }: AppShellProps) {
    return (
        <div className="min-h-screen bg-zinc-50 text-zinc-950 dark:bg-zinc-950 dark:text-zinc-50">
            <header className="border-b border-zinc-200 bg-white/95 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900/95">
                <div className="mx-auto flex max-w-7xl items-center justify-between gap-4">
                    <Link
                        href={home.url()}
                        className="text-sm font-semibold tracking-normal text-zinc-950 outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-zinc-50"
                    >
                        image-to-powerpoint
                    </Link>
                    <span className="text-xs text-zinc-500 dark:text-zinc-400">
                        this device
                    </span>
                </div>
            </header>

            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-0 lg:grid-cols-[18rem_1fr]">
                <aside className="border-b border-zinc-200 bg-white px-4 py-5 lg:min-h-[calc(100vh-53px)] lg:border-r lg:border-b-0 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 className="text-xs font-semibold tracking-normal text-zinc-500 uppercase dark:text-zinc-400">
                        Recent
                    </h2>
                    <nav className="mt-3 flex flex-col gap-1">
                        {recentConversions.length === 0 ? (
                            <p className="text-sm text-zinc-500 dark:text-zinc-400">
                                No conversions in this session yet.
                            </p>
                        ) : (
                            recentConversions.map((conversion) => (
                                <Link
                                    key={conversion.uuid}
                                    href={conversion.url}
                                    className="rounded-md px-2 py-2 text-sm text-zinc-700 outline-none hover:bg-zinc-100 focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                >
                                    <span className="block truncate">
                                        {conversion.label}
                                    </span>
                                    <span className="block font-mono text-[11px] text-zinc-400">
                                        {conversion.uuid.slice(0, 8)}
                                    </span>
                                </Link>
                            ))
                        )}
                    </nav>
                </aside>

                <main className="px-4 py-6 sm:px-6 lg:px-8">{children}</main>
            </div>
        </div>
    );
}
