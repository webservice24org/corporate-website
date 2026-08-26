import { useEffect } from "react";
import { usePage } from "@inertiajs/react";
import toast from "react-hot-toast";

import type { PageProps } from "@/types";

type FlashProps = PageProps<{
    flash?: {
        success?: string | null;
        error?: string | null;
    };
}>;

export default function FlashMessages() {
    const { flash } = usePage<FlashProps>().props;

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }
    }, [flash?.success]);

    useEffect(() => {
        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash?.error]);

    return null;
}