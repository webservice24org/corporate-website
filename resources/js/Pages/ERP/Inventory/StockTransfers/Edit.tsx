
import { Head, Link } from '@inertiajs/react';

import Form, {
    type FormOptions,
    type StockTransfer,
} from './Form';

import ERPLayout from '@/Layouts/erp-layout';

type Props = FormOptions & {
    stockTransfer: StockTransfer;
};

export default function Edit({
    stockTransfer,
    ...options
}: Props) {
    return (
        <ERPLayout>
            <Head
                title={`Edit ${stockTransfer.document_number}`}
            />

            <main className="mx-auto w-full min-w-0 max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <div>
                    <Link
                        href={`/erp/inventory/stock-transfers/${stockTransfer.id}`}
                        className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                    >
                        ← Back to Transfer
                    </Link>

                    <h1 className="mt-3 text-2xl font-bold text-slate-900 dark:text-white">
                        Edit Stock Transfer
                    </h1>

                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {stockTransfer.document_number}
                    </p>
                </div>

                <Form
                    {...options}
                    stockTransfer={stockTransfer}
                />
            </main>
        </ERPLayout>
    );
}
