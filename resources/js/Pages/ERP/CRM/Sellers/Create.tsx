import {
    Head,
    useForm,
} from "@inertiajs/react";

import type {
    FormEvent,
} from "react";

import {
    Save,
} from "lucide-react";

import ERPLayout from "@/Layouts/erp-layout";

import Form from "./Form";

import type {
    SellerFormData,
} from "./Form";

import type {
    SellerFormDealer,
    SellerFormManager,
} from "@/types/seller";

type Props = {
    dealers:
        SellerFormDealer[];

    managers:
        SellerFormManager[];
};

export default function Create({
    dealers,
    managers,
}: Props) {
    const form =
        useForm<SellerFormData>({
            dealer_id: "",

            business_name: "",
            proprietor_name: "",

            seller_type:
                "retailer",

            phone: "",
            alternate_phone: "",
            email: "",

            trade_license_number:
                "",

            nid_number: "",
            tin_number: "",
            vat_number: "",

            business_address:
                "",

            postal_code: "",

            credit_limit: 0,

            payment_terms_days:
                0,

            manager_user_id: "",

            is_active: true,

            notes: "",
        });

    const submit = (
        event:
            FormEvent
    ) => {
        event.preventDefault();

        form.post(
            "/erp/crm/sellers",
            {
                preserveScroll:
                    true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Create Seller" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">
                        Create Seller
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        Add a Retailer or
                        General Seller under
                        an existing Dealer.
                    </p>
                </div>

                <form
                    onSubmit={
                        submit
                    }
                    className="space-y-6"
                >
                    <Form
                        form={
                            form
                        }
                        dealers={
                            dealers
                        }
                        managers={
                            managers
                        }
                    />

                    <button
                        type="submit"
                        disabled={
                            form.processing
                        }
                        className="inline-flex h-10 items-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-neutral-950"
                    >
                        <Save className="h-4 w-4" />

                        {form.processing
                            ? "Saving..."
                            : "Create Seller"}
                    </button>
                </form>
            </div>
        </ERPLayout>
    );
}