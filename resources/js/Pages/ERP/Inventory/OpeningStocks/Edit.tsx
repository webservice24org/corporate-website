
import { Head, Link } from "@inertiajs/react";
import type { ComponentProps } from "react";
import ERPLayout from "@/Layouts/erp-layout";
import Form from "./Form";

type Props = {
    companies: ComponentProps<typeof Form>["companies"];
    warehouses: ComponentProps<typeof Form>["warehouses"];
    products: ComponentProps<typeof Form>["products"];
    openingStock: NonNullable<
        ComponentProps<typeof Form>["openingStock"]
    >;
};

export default function Edit({
    companies,
    warehouses,
    products,
    openingStock,
}: Props) {
    return (
        <ERPLayout>
            <Head
                title={`Edit ${openingStock.document_number}`}
            />

            <div className="min-w-0 space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Edit Opening Stock
                        </h1>
                        <p className="text-sm text-neutral-500">
                            {openingStock.document_number}
                        </p>
                    </div>

                    <Link
                        href={`/erp/inventory/opening-stocks/${openingStock.id}`}
                        className="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700"
                    >
                        Back to Document
                    </Link>
                </div>

                <Form
                    mode="edit"
                    openingStock={openingStock}
                    companies={companies}
                    warehouses={warehouses}
                    products={products}
                />
            </div>
        </ERPLayout>
    );
}
