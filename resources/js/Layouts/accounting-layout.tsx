import { Link, usePage } from '@inertiajs/react';
import { Menu, User } from 'lucide-react';
import { useState, type PropsWithChildren } from 'react';
import FlashMessages from '@/Components/FlashMessages';
import AccountingSidebar from '@/Components/ERP/AccountingSidebar';
import NotificationBell from '@/Components/ERP/NotificationBell';
import type { PageProps } from '@/types';

export default function AccountingLayout({ children }: PropsWithChildren) {
    const [open, setOpen] = useState(false);
    const user = usePage<PageProps>().props.auth?.user;

    return (
        <div className="erp-light-theme min-h-screen bg-slate-100 text-slate-900">
            <FlashMessages />
            <div className="flex min-h-screen">
                <AccountingSidebar mobileOpen={open} onMobileClose={() => setOpen(false)} />
                <div className="min-w-0 flex-1">
                    <header className="sticky top-0 z-30 flex h-16 items-center justify-between gap-3 border-b bg-white/95 px-4 shadow-sm backdrop-blur sm:px-6 lg:px-8">
                        <div className="flex min-w-0 items-center gap-3">
                            <button type="button" aria-label="Open accounting navigation" aria-expanded={open} onClick={() => setOpen(true)} className="rounded-lg border p-2 text-slate-600 lg:hidden"><Menu className="size-5" /></button>
                            <div className="min-w-0"><p className="truncate font-semibold">Accounting &amp; Finance</p><p className="hidden text-xs text-slate-500 sm:block">Corporate ERP</p></div>
                        </div>
                        <div className="flex shrink-0 items-center gap-2 sm:gap-3">
                            <Link href="/erp/dashboard" className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50">ERP</Link>
                            <Link href="/admin/dashboard" className="hidden rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50 md:inline-flex">CMS Admin</Link>
                            <NotificationBell />
                            <div className="hidden text-right sm:block"><p className="text-sm font-semibold">{user?.name ?? 'User'}</p><p className="text-xs text-slate-500">{user?.email ?? ''}</p></div>
                            <Link href="/profile" aria-label="Profile" className="flex size-9 items-center justify-center rounded-full bg-slate-100 text-slate-600 hover:bg-slate-200"><User className="size-4" /></Link>
                        </div>
                    </header>
                    <main className="p-4 sm:p-6 lg:p-8">{children}</main>
                </div>
            </div>
        </div>
    );
}
