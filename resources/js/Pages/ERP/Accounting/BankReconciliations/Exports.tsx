import { panel, secondary } from '../CashTransfers/types';
export default function Exports({ links = {}, error }: { links?: Record<string, string>; error?: string }) {
    if (!links.current_csv && !links.approved_csv && !error) return null;
    return <section className={`${panel} space-y-3`} aria-label="Reconciliation exports"><h2 className="font-semibold">Export reconciliation</h2>{error && <p role="alert" className="text-sm text-red-700 dark:text-red-300">{error}</p>}<p className="text-sm text-slate-500">Full results, independent of screen pagination. Approved snapshots are labelled historical after reopening.</p><div className="flex flex-wrap gap-3">
        {links.current_csv && <a className={secondary} href={links.current_csv}>Current report · CSV</a>}
        {links.current_print && <a className={secondary} href={links.current_print} target="_blank" rel="noopener noreferrer">Current report · Print</a>}
        {links.approved_csv && <a className={secondary} href={links.approved_csv}>Approved snapshot · CSV</a>}
        {links.approved_print && <a className={secondary} href={links.approved_print} target="_blank" rel="noopener noreferrer">Approved snapshot · Print</a>}
    </div></section>;
}
