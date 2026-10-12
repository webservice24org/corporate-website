
import { Head, Link } from '@inertiajs/react';

import Form, {
    type FormOptions,
} from './Form';
import ERPLayout from "@/Layouts/erp-layout";

export default function Create(props: FormOptions) {
    return (
        <ERPLayout>
            <Head title="Create Stock Transfer" />

            <main className="mx-auto w-full min-w-0 max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8">
                <div>
                    <Link
                        href="/erp/inventory/stock-transfers"
                        className="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                    >
                        ← Back to Stock Transfers
                    </Link>

                    <h1 className="mt-3 text-2xl font-bold text-slate-900 dark:text-white">
                        Create Stock Transfer
                    </h1>

                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        Prepare a new warehouse-to-warehouse
                        stock transfer draft.
                    </p>
                </div>

                <Form {...props} />
            </main>
        </ERPLayout>
    );
}

