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
    Seller,
    SellerFormDealer,
    SellerFormManager,
} from "@/types/seller";

type Props = {
    seller:
        Seller;

    dealers:
        SellerFormDealer[];

    managers:
        SellerFormManager[];
};

export default function Edit({
    seller,
    dealers,
    managers,
}: Props) {
    const form =
    useForm<SellerFormData>({
        dealer_id:
            seller.dealer_id,

        business_name:
            seller.business_name,

        proprietor_name:
            seller.proprietor_name,

        proprietor_photo:
            null,

        seller_type:
            seller.seller_type,

        phone:
            seller.phone,

        alternate_phone:
            seller.alternate_phone ??
            "",

        email:
            seller.email ??
            "",

        trade_license_number:
            seller.trade_license_number ??
            "",

        nid_number:
            seller.nid_number ??
            "",

        tin_number:
            seller.tin_number ??
            "",

        vat_number:
            seller.vat_number ??
            "",

        business_address:
            seller.business_address,

        postal_code:
            seller.postal_code ??
            "",

        credit_limit:
            Number(
                seller.credit_limit ??
                    0
            ),

        payment_terms_days:
            seller.payment_terms_days ??
            0,

        manager_user_id:
            seller.manager_user_id ??
            "",

        is_active:
            seller.is_active,

        notes:
            seller.notes ??
            "",
    });

    const submit = (
        event: FormEvent
    ) => {
        event.preventDefault();

        form.transform(
            (data) => ({
                ...data,
                _method: "put",
            })
        );

        form.post(
            `/erp/crm/sellers/${seller.id}`,
            {
                preserveScroll: true,
                forceFormData: true,
            }
        );
    };

    return (
        <ERPLayout>
            <Head title="Edit Seller" />

            <div className="space-y-6">
                <div>
                    <h1 className="text-2xl font-bold">
                        Edit Seller
                    </h1>

                    <p className="mt-1 text-sm text-neutral-500">
                        {
                            seller.business_name
                        }
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
                        existingProprietorPhoto={
                            seller.proprietor_photo
                                ? `/erp/crm/sellers/${seller.id}/proprietor-photo`
                                : null
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
                            : "Save Changes"}
                    </button>
                </form>
            </div>
        </ERPLayout>
    );
}