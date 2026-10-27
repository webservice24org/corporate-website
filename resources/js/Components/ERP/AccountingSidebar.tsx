import { Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Calculator, LogOut, X } from 'lucide-react';
import { useAuthorization } from '@/hooks/useAuthorization';
import { accountingLinks } from '@/lib/accounting-navigation';
import { sidebarLinkIsActive, sidebarPath } from '@/lib/erp-sidebar-active';

type Props = { mobileOpen: boolean; onMobileClose: () => void };

export default function AccountingSidebar({ mobileOpen, onMobileClose }: Props) {
    const { can, user } = useAuthorization();
    const path = sidebarPath(usePage().url);
    const links = accountingLinks(can);
    const active = links.filter(item => sidebarLinkIsActive(path, item.href, item.exact)).sort((a, b) => b.href.length - a.href.length)[0];
    const groups = [...new Set(links.map(item => item.group))];

    return <>
        {mobileOpen && <button type="button" aria-label="Close accounting navigation" onClick={onMobileClose} className="fixed inset-0 z-40 bg-black/50 lg:hidden" />}
        <aside className={`fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-slate-800 bg-slate-950 text-slate-300 transition-transform duration-300 lg:static lg:translate-x-0 ${mobileOpen ? 'translate-x-0' : '-translate-x-full'}`}>
            <div className="flex h-16 items-center justify-between border-b border-slate-800 px-5">
                <div className="flex items-center gap-3"><span className="flex size-9 items-center justify-center rounded-lg bg-indigo-600 text-white"><Calculator className="size-5" /></span><div><p className="font-bold text-white">Accounting</p><p className="text-[10px] font-semibold uppercase tracking-widest text-slate-500">Corporate ERP</p></div></div>
                <button type="button" aria-label="Close accounting navigation" onClick={onMobileClose} className="rounded-md p-2 hover:bg-slate-800 lg:hidden"><X className="size-5" /></button>
            </div>
            <nav aria-label="Accounting navigation" className="flex-1 space-y-5 overflow-y-auto px-3 py-5">
                {groups.map(group => <section key={group} aria-label={group}>
                    <p className="mb-2 px-3 text-[10px] font-bold uppercase tracking-widest text-slate-500">{group}</p>
                    <div className="space-y-1">{links.filter(item => item.group === group).map(item => <Link key={item.href} href={item.href} onClick={onMobileClose} aria-current={active?.href === item.href ? 'page' : undefined} className={`block rounded-lg px-3 py-2 text-xs font-medium transition ${active?.href === item.href ? 'bg-indigo-600 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-white'}`}>{item.label}</Link>)}</div>
                </section>)}
            </nav>
            <div className="border-t border-slate-800 p-3">
                <Link href="/erp/dashboard" onClick={onMobileClose} className="mb-2 flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-900"><ArrowLeft className="size-4" /> ERP workspace</Link>
                <div className="flex items-center justify-between rounded-lg bg-slate-900/60 p-2"><div className="min-w-0"><p className="truncate text-xs font-medium text-white">{user?.name ?? 'User'}</p><p className="truncate text-[10px] text-slate-500">{user?.email ?? ''}</p></div><button type="button" onClick={() => router.post('/logout')} aria-label="Log out" className="rounded-lg p-2 hover:bg-slate-800"><LogOut className="size-4" /></button></div>
            </div>
        </aside>
    </>;
}
