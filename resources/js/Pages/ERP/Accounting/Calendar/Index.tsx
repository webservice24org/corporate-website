import { Head, Link } from '@inertiajs/react';
import ERPLayout from '@/Layouts/erp-layout';
import CompanyFilter from '../CompanyFilter';
import { button, companyUrl, panel } from '../types';
import Notice from './Notice';
import { base } from './types';
import type { Context, Year } from './types';
type Props = Context & { years: Year[]; canManageYears: boolean; canViewPeriods: boolean };
export default function Index(props: Props) {
    return <ERPLayout><Head title="Fiscal Calendar" /><div className="space-y-5"><header className="flex flex-wrap justify-between gap-3"><div><h1 className="text-2xl font-semibold">Fiscal Calendar</h1><p className="text-sm text-slate-600">Company fiscal years and accounting periods. Dates are inclusive company calendar dates.</p></div>{props.company && props.issues.length === 0 && props.canManageYears && <Link className={button} href={companyUrl(`${base}/create`, props.company.id)}>Create fiscal year</Link>}</header><CompanyFilter companies={props.companies} selectedId={props.company?.id ?? null} path={base} /><Notice context={props} /><section className={`${panel} overflow-x-auto`}><table className="w-full text-left text-sm"><thead><tr>{['Year', 'Starts', 'Ends', 'State', 'Actions'].map(title => <th className="p-3" key={title}>{title}</th>)}</tr></thead><tbody>{props.years.map(year => <tr className="border-t" key={year.id}><td className="p-3">{year.code} — {year.name}</td><td className="p-3">{year.starts_on}</td><td className="p-3">{year.ends_on}</td><td className="p-3">{year.state}</td><td className="flex flex-wrap gap-3 p-3">{props.canViewPeriods && <Link className="underline" href={`${base}/${year.id}`}>Periods</Link>}{props.canManageYears && props.issues.length === 0 && year.state === 'open' && <Link className="underline" href={`${base}/${year.id}/edit`}>Edit year</Link>}</td></tr>)}</tbody></table>{props.years.length === 0 && <p className="p-3">{props.company ? 'No fiscal years configured.' : 'Select a company.'}</p>}</section><p className="text-sm text-slate-500">Calendar setup does not enable posting. Open a fiscal year to review period controls and accounting-date coverage.</p></div></ERPLayout>;
}
