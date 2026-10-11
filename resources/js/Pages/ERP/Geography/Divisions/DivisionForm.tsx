import { Link } from "@inertiajs/react";
import {
    Globe2,
    Save,
} from "lucide-react";

import type {
    FormEvent,
} from "react";

type DivisionFormData = {
    name: string;
    name_bn: string;
    code: string;
    is_active: boolean;
};

type Errors = Partial<
    Record<
        keyof DivisionFormData,
        string
    >
>;

type Props = {
    data: DivisionFormData;

    errors: Errors;

    processing: boolean;

    submitLabel: string;

    setData: <
        K extends keyof DivisionFormData,
    >(
        key: K,
        value: DivisionFormData[K]
    ) => void;

    onSubmit:
        (
            event:
                FormEvent<HTMLFormElement>
        ) => void;
};

const inputClass =
    "h-10 w-full rounded-md border border-neutral-200 bg-white px-3 text-sm outline-none transition focus:border-neutral-400 focus:ring-2 focus:ring-neutral-950/10 dark:border-neutral-800 dark:bg-neutral-950 dark:focus:border-neutral-600 dark:focus:ring-white/10";

const labelClass =
    "mb-1.5 block text-sm font-medium text-neutral-800 dark:text-neutral-200";

export default function DivisionForm({
    data,
    errors,
    processing,
    submitLabel,
    setData,
    onSubmit,
}: Props) {
    return (
        <form
            onSubmit={
                onSubmit
            }
            className="space-y-6"
        >
            <div className="rounded-xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-950">
                <div className="mb-6 flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-neutral-100 dark:bg-neutral-900">
                        <Globe2 className="h-5 w-5" />
                    </div>

                    <div>
                        <h2 className="font-semibold">
                            Division Information
                        </h2>

                        <p className="mt-0.5 text-sm text-neutral-500">
                            Configure the geographic
                            division master record.
                        </p>
                    </div>
                </div>

                <div className="grid gap-5 md:grid-cols-2">
                    <div>
                        <label
                            className={
                                labelClass
                            }
                        >
                            Division Name *
                        </label>

                        <input
                            type="text"
                            value={
                                data.name
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "name",
                                    event.target.value
                                )
                            }
                            placeholder="Dhaka"
                            className={
                                inputClass
                            }
                        />

                        {errors.name && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    errors.name
                                }
                            </p>
                        )}
                    </div>

                    <div>
                        <label
                            className={
                                labelClass
                            }
                        >
                            Bengali Name
                        </label>

                        <input
                            type="text"
                            value={
                                data.name_bn
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "name_bn",
                                    event.target.value
                                )
                            }
                            placeholder="ঢাকা"
                            className={
                                inputClass
                            }
                        />

                        {errors.name_bn && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    errors.name_bn
                                }
                            </p>
                        )}
                    </div>

                    <div>
                        <label
                            className={
                                labelClass
                            }
                        >
                            Code
                        </label>

                        <input
                            type="text"
                            value={
                                data.code
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "code",
                                    event.target.value
                                        .toUpperCase()
                                )
                            }
                            placeholder="DHA"
                            className={
                                inputClass
                            }
                        />

                        {errors.code && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    errors.code
                                }
                            </p>
                        )}

                        <p className="mt-1 text-xs text-neutral-500">
                            Optional short unique code.
                        </p>
                    </div>

                    <div>
                        <label
                            className={
                                labelClass
                            }
                        >
                            Status
                        </label>

                        <select
                            value={
                                data.is_active
                                    ? "1"
                                    : "0"
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "is_active",
                                    event.target.value ===
                                        "1"
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="1">
                                Active
                            </option>

                            <option value="0">
                                Inactive
                            </option>
                        </select>

                        {errors.is_active && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    errors.is_active
                                }
                            </p>
                        )}
                    </div>
                </div>
            </div>

            <div className="flex flex-wrap justify-end gap-3">
                <Link
                    href="/erp/geography/divisions"
                    className="inline-flex h-10 items-center justify-center rounded-md border border-neutral-200 px-4 text-sm font-medium transition hover:bg-neutral-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                >
                    Cancel
                </Link>

                <button
                    type="submit"
                    disabled={
                        processing
                    }
                    className="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-neutral-950 px-4 text-sm font-medium text-white transition hover:bg-neutral-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-neutral-950"
                >
                    <Save className="h-4 w-4" />

                    {processing
                        ? "Saving..."
                        : submitLabel}
                </button>
            </div>
        </form>
    );
}

export type {
    DivisionFormData,
};