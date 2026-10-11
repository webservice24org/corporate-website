import {
    ReactNode,
} from "react";

export type EmployeeDocumentFormData = {
    document_type: string;
    title: string;
    document_number: string;
    issue_date: string;
    expiry_date: string;

    file:
        | File
        | null;

    notes: string;
    status: string;
};

type Props = {
    data:
        EmployeeDocumentFormData;

    setData: <
        K extends keyof EmployeeDocumentFormData,
    >(
        key: K,
        value:
            EmployeeDocumentFormData[K],
    ) => void;

    errors: Partial<
        Record<
            keyof EmployeeDocumentFormData,
            string
        >
    >;

    processing: boolean;

    submitLabel: string;

    currentFile?: string | null;

    onSubmit: (
        event:
            React.FormEvent<HTMLFormElement>,
    ) => void;

    footer?: ReactNode;
};

export default function EmployeeDocumentForm({
    data,
    setData,
    errors,
    processing,
    submitLabel,
    currentFile,
    onSubmit,
    footer,
}: Props) {
    return (
        <form
            onSubmit={onSubmit}
            className="space-y-6"
        >
            <section className="rounded-xl border bg-white p-6 shadow-sm">
                <div className="grid gap-5 md:grid-cols-2">
                    <Field
                        label="Document Type"
                        required
                        error={
                            errors.document_type
                        }
                    >
                        <select
                            value={
                                data.document_type
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "document_type",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="">
                                Select type
                            </option>

                            <option value="national_id">
                                National ID
                            </option>

                            <option value="passport">
                                Passport
                            </option>

                            <option value="birth_certificate">
                                Birth Certificate
                            </option>

                            <option value="appointment_letter">
                                Appointment Letter
                            </option>

                            <option value="employment_contract">
                                Employment Contract
                            </option>

                            <option value="educational_certificate">
                                Educational Certificate
                            </option>

                            <option value="experience_certificate">
                                Experience Certificate
                            </option>

                            <option value="training_certificate">
                                Training Certificate
                            </option>

                            <option value="professional_certificate">
                                Professional Certificate
                            </option>

                            <option value="tax_document">
                                Tax Document
                            </option>

                            <option value="other">
                                Other
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Title"
                        required
                        error={
                            errors.title
                        }
                    >
                        <input
                            value={
                                data.title
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "title",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Document Number"
                        error={
                            errors.document_number
                        }
                    >
                        <input
                            value={
                                data.document_number
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "document_number",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Status"
                        required
                        error={
                            errors.status
                        }
                    >
                        <select
                            value={
                                data.status
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "status",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        >
                            <option value="active">
                                Active
                            </option>

                            <option value="inactive">
                                Inactive
                            </option>
                        </select>
                    </Field>

                    <Field
                        label="Issue Date"
                        error={
                            errors.issue_date
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.issue_date
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "issue_date",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label="Expiry Date"
                        error={
                            errors.expiry_date
                        }
                    >
                        <input
                            type="date"
                            value={
                                data.expiry_date
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "expiry_date",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>

                    <Field
                        label={
                            currentFile
                                ? "Replace File"
                                : "File"
                        }
                        required={
                            !currentFile
                        }
                        error={
                            errors.file
                        }
                    >
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "file",
                                    event.target.files?.[0] ??
                                        null,
                                )
                            }
                            className={
                                inputClass
                            }
                        />

                        {currentFile && (
                            <p className="mt-2 text-xs text-slate-500">
                                Current:{" "}
                                {
                                    currentFile
                                }
                            </p>
                        )}
                    </Field>
                </div>

                <div className="mt-5">
                    <Field
                        label="Notes"
                        error={
                            errors.notes
                        }
                    >
                        <textarea
                            rows={4}
                            value={
                                data.notes
                            }
                            onChange={(
                                event,
                            ) =>
                                setData(
                                    "notes",
                                    event.target.value,
                                )
                            }
                            className={
                                inputClass
                            }
                        />
                    </Field>
                </div>
            </section>

            <div className="flex justify-between">
                <div>
                    {footer}
                </div>

                <button
                    disabled={
                        processing
                    }
                    type="submit"
                    className="rounded-lg bg-slate-900 px-5 py-2.5 text-sm font-medium text-white disabled:opacity-50"
                >
                    {processing
                        ? "Saving..."
                        : submitLabel}
                </button>
            </div>
        </form>
    );
}

function Field({
    label,
    children,
    required = false,
    error,
}: {
    label: string;
    children: ReactNode;
    required?: boolean;
    error?: string;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-sm font-medium text-slate-700">
                {label}

                {required && (
                    <span className="ml-1 text-red-500">
                        *
                    </span>
                )}
            </label>

            {children}

            {error && (
                <p className="mt-1 text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}

const inputClass =
    "block min-h-10 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-slate-400 focus:ring-2 focus:ring-slate-200";