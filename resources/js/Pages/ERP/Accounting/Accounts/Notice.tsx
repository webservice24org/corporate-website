import { Link, usePage } from '@inertiajs/react';
import { companyUrl } from '../types';
import type { Context } from './types';
export default function Notice({ context }: { context: Context }) {
    const page = usePage();
    const flash = page.props.flash as { success?: string; error?: string } | undefined;
    const errors = page.props.errors ?? {};
    return <div role="status" className="space-y-2">{flash?.success && <p className="text-green-800">{flash.success}</p>}{flash?.error && <p className="text-red-700">{flash.error}</p>}{Object.entries(errors).map(([key, value]) => <p className="text-red-700" key={key}>{String(value)}</p>)}{context.issues.map(issue => <p className="text-amber-800" key={issue}>{issue}</p>)}{context.issues.length > 0 && context.canViewSetup && <Link className="underline" href={companyUrl('/erp/accounting/settings', context.company?.id ?? null)}>Review Accounting Setup</Link>}</div>;
}
