
import { Head, Link } from "@inertiajs/react";
import ERPLayout from "@/Layouts/erp-layout";
import Form from "./Form";

type Props = {
    companies: React.ComponentProps<typeof Form>["companies"];
    warehouses: React.ComponentProps<typeof Form>["warehouses"];
    products: React.ComponentProps<typeof Form>["products"];
};

export default function Create({
    companies,
    warehouses,
    products,
}: Props) {
    return (
        <ERPLayout>
            <Head title="Create Opening Stock" />

            <div className="min-w-0 space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Create Opening Stock
                        </h1>
                        <p className="text-sm text-neutral-500">
                            Prepare an opening inventory document.
                        </p>
                    </div>

                    <Link
                        href="/erp/inventory/opening-stocks"
                        className="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700"
                    >
                        Back to List
                    </Link>
                </div>

                <Form
                    mode="create"
                    companies={companies}
                    warehouses={warehouses}
                    products={products}
                />
            </div>
        </ERPLayout>
    );
}
