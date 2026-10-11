import {
    Link,
} from "@inertiajs/react";

import {
    MapPinned,
    Save,
} from "lucide-react";

import {
    useEffect,
    useMemo,
} from "react";

import type {
    FormEvent,
} from "react";

import type {
    District,
    Division,
} from "@/types/geography";

export type UpazilaFormData = {
    division_id:
        number | "";

    district_id:
        number | "";

    name: string;

    name_bn: string;

    code: string;

    is_active: boolean;
};

type UpazilaFormErrors =
    Partial<
        Record<
            keyof UpazilaFormData,
            string
        >
    >;

type Props = {
    divisions:
        Division[];

    districts:
        District[];

    data:
        UpazilaFormData;

    errors:
        UpazilaFormErrors;

    processing:
        boolean;

    submitLabel:
        string;

    setData: <
        K extends keyof UpazilaFormData,
    >(
        key: K,
        value:
            UpazilaFormData[K]
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

export default function UpazilaForm({
    divisions,
    districts,
    data,
    errors,
    processing,
    submitLabel,
    setData,
    onSubmit,
}: Props) {
    const filteredDistricts =
        useMemo(
            () => {
                if (
                    data.division_id ===
                    ""
                ) {
                    return [];
                }

                return districts.filter(
                    (district) =>
                        district.division_id ===
                        data.division_id
                );
            },
            [
                data.division_id,
                districts,
            ]
        );

    useEffect(
        () => {
            if (
                data.district_id ===
                ""
            ) {
                return;
            }

            const selectedDistrict =
                districts.find(
                    (district) =>
                        district.id ===
                        data.district_id
                );

            if (
                !selectedDistrict ||
                selectedDistrict.division_id !==
                    data.division_id
            ) {
                setData(
                    "district_id",
                    ""
                );
            }
        },
        [
            data.division_id,
            data.district_id,
            districts,
            setData,
        ]
    );

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
                        <MapPinned className="h-5 w-5" />
                    </div>

                    <div>
                        <h2 className="font-semibold">
                            Upazila Information
                        </h2>

                        <p className="mt-0.5 text-sm text-neutral-500">
                            Select the Division
                            first, then choose a
                            District under that
                            Division.
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
                            Division *
                        </label>

                        <select
                            value={
                                data.division_id
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "division_id",
                                    event.target.value
                                        ? Number(
                                              event.target.value
                                          )
                                        : ""
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select Division
                            </option>

                            {divisions.map(
                                (
                                    division
                                ) => (
                                    <option
                                        key={
                                            division.id
                                        }
                                        value={
                                            division.id
                                        }
                                    >
                                        {
                                            division.name
                                        }

                                        {division.name_bn
                                            ? ` — ${division.name_bn}`
                                            : ""}
                                    </option>
                                )
                            )}
                        </select>

                        {errors.division_id && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    errors.division_id
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
                            District *
                        </label>

                        <select
                            value={
                                data.district_id
                            }
                            disabled={
                                data.division_id ===
                                ""
                            }
                            onChange={(
                                event
                            ) =>
                                setData(
                                    "district_id",
                                    event.target.value
                                        ? Number(
                                              event.target.value
                                          )
                                        : ""
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                {data.division_id ===
                                ""
                                    ? "Select Division first"
                                    : "Select District"}
                            </option>

                            {filteredDistricts.map(
                                (
                                    district
                                ) => (
                                    <option
                                        key={
                                            district.id
                                        }
                                        value={
                                            district.id
                                        }
                                    >
                                        {
                                            district.name
                                        }

                                        {district.name_bn
                                            ? ` — ${district.name_bn}`
                                            : ""}
                                    </option>
                                )
                            )}
                        </select>

                        {errors.district_id && (
                            <p className="mt-1 text-xs text-red-600">
                                {
                                    errors.district_id
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
                            Upazila Name *
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
                            placeholder="Savar"
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
                            placeholder="সাভার"
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
                            placeholder="SAV"
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
                            Optional unique Upazila code.
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
                    href="/erp/geography/upazilas"
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