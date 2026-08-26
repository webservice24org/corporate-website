import AdminLayout from "@/Layouts/admin-layout";

import { Head, router, useForm } from "@inertiajs/react";

import { Button } from "@/Components/ui/button";

import {
    Mail,
    Send,
    Server,
    ShieldCheck,
} from "lucide-react";

import { useState } from "react";
import toast from "react-hot-toast";
import type {
    ReactNode,
} from "react";

type MailSettings = {
    id: number;
    mailer: string;

    host: string | null;
    port: number;

    username: string | null;
    password: string;

    encryption: string | null;

    from_address: string | null;
    from_name: string | null;

    reply_to_address: string | null;
    reply_to_name: string | null;

    is_active: boolean;
};

type Props = {
    settings: MailSettings | null;
};

type MailForm = {
    mailer: string;

    host: string;
    port: number | string;

    username: string;
    password: string;

    encryption: string;

    from_address: string;
    from_name: string;

    reply_to_address: string;
    reply_to_name: string;

    is_active: boolean;
};

export default function Edit({
    settings,
}: Props) {
    const {
        data,
        setData,
        put,
        processing,
        errors,
    } = useForm<MailForm>({
        mailer:
            settings?.mailer ?? "smtp",

        host:
            settings?.host ?? "",

        port:
            settings?.port ?? 587,

        username:
            settings?.username ?? "",

        /*
         * Controller intentionally returns
         * an empty password.
         */
        password: "",

        encryption:
            settings?.encryption ??
            "tls",

        from_address:
            settings?.from_address ??
            "",

        from_name:
            settings?.from_name ?? "",

        reply_to_address:
            settings?.reply_to_address ??
            "",

        reply_to_name:
            settings?.reply_to_name ??
            "",

        is_active:
            settings?.is_active ?? true,
    });

    const [
        testEmail,
        setTestEmail,
    ] = useState(
        settings?.from_address ?? "",
    );

    const [
        testing,
        setTesting,
    ] = useState(false);

    const submit = () => {
        put("/admin/mail-settings", {
            preserveScroll: true,

            onError: () => {
                toast.error(
                    "Please check the mail settings.",
                );
            },
        });
    };

    const sendTestEmail = () => {
        if (!testEmail.trim()) {
            toast.error(
                "Please enter a test email address.",
            );

            return;
        }

        setTesting(true);

        router.post(
            "/admin/mail-settings/test",
            {
                email:
                    testEmail.trim(),
            },
            {
                preserveScroll: true,
                preserveState: true,

                onSuccess: () => {
                    toast.success(
                        "Test email request completed.",
                    );
                },

                onError: () => {
                    toast.error(
                        "Could not send the test email.",
                    );
                },

                onFinish: () => {
                    setTesting(false);
                },
            },
        );
    };

    return (
        <AdminLayout>
            <Head title="Mail Settings" />

            <div className="space-y-6">
                {/* Page Header */}
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">
                        Mail Settings
                    </h1>

                    <p className="mt-1 text-sm text-muted-foreground">
                        Configure the SMTP account
                        used for contact-message
                        replies and website emails.
                    </p>
                </div>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
                    {/* Main settings */}
                    <div className="space-y-6">
                        {/* SMTP */}
                        <section className="rounded-xl border bg-background">
                            <div className="flex items-center gap-3 border-b px-5 py-4">
                                <div className="flex size-10 items-center justify-center rounded-lg bg-muted">
                                    <Server className="size-5" />
                                </div>

                                <div>
                                    <h2 className="font-semibold">
                                        SMTP
                                        Configuration
                                    </h2>

                                    <p className="text-sm text-muted-foreground">
                                        Connection
                                        details for your
                                        outgoing mail
                                        server.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-5 p-5 md:grid-cols-2">
                                <Field
                                    label="Mailer"
                                    error={
                                        errors.mailer
                                    }
                                >
                                    <select
                                        value={
                                            data.mailer
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "mailer",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        className={inputClass}
                                    >
                                        <option value="smtp">
                                            SMTP
                                        </option>
                                    </select>
                                </Field>

                                <Field
                                    label="Encryption"
                                    error={
                                        errors.encryption
                                    }
                                >
                                    <select
                                        value={
                                            data.encryption
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "encryption",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        className={inputClass}
                                    >
                                        <option value="tls">
                                            TLS
                                        </option>

                                        <option value="ssl">
                                            SSL
                                        </option>

                                        <option value="">
                                            None
                                        </option>
                                    </select>
                                </Field>

                                <Field
                                    label="SMTP Host"
                                    error={
                                        errors.host
                                    }
                                >
                                    <input
                                        type="text"
                                        value={
                                            data.host
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "host",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="smtp.example.com"
                                        className={inputClass}
                                    />
                                </Field>

                                <Field
                                    label="SMTP Port"
                                    error={
                                        errors.port
                                    }
                                >
                                    <input
                                        type="number"
                                        min={1}
                                        max={65535}
                                        value={
                                            data.port
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "port",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="587"
                                        className={inputClass}
                                    />
                                </Field>

                                <Field
                                    label="Username"
                                    error={
                                        errors.username
                                    }
                                >
                                    <input
                                        type="text"
                                        value={
                                            data.username
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "username",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        autoComplete="off"
                                        placeholder="mail@example.com"
                                        className={inputClass}
                                    />
                                </Field>

                                <Field
                                    label={
                                        settings
                                            ? "Password (leave blank to keep current)"
                                            : "Password"
                                    }
                                    error={
                                        errors.password
                                    }
                                >
                                    <input
                                        type="password"
                                        value={
                                            data.password
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "password",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        autoComplete="new-password"
                                        placeholder={
                                            settings
                                                ? "••••••••"
                                                : "SMTP password"
                                        }
                                        className={inputClass}
                                    />
                                </Field>
                            </div>
                        </section>

                        {/* Sender */}
                        <section className="rounded-xl border bg-background">
                            <div className="flex items-center gap-3 border-b px-5 py-4">
                                <div className="flex size-10 items-center justify-center rounded-lg bg-muted">
                                    <Mail className="size-5" />
                                </div>

                                <div>
                                    <h2 className="font-semibold">
                                        Sender
                                        Information
                                    </h2>

                                    <p className="text-sm text-muted-foreground">
                                        Default From and
                                        Reply-To identity.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-5 p-5 md:grid-cols-2">
                                <Field
                                    label="From Email"
                                    error={
                                        errors.from_address
                                    }
                                >
                                    <input
                                        type="email"
                                        value={
                                            data.from_address
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "from_address",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="info@example.com"
                                        className={inputClass}
                                    />
                                </Field>

                                <Field
                                    label="From Name"
                                    error={
                                        errors.from_name
                                    }
                                >
                                    <input
                                        type="text"
                                        value={
                                            data.from_name
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "from_name",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="Corporate Website"
                                        className={inputClass}
                                    />
                                </Field>

                                <Field
                                    label="Reply-To Email"
                                    error={
                                        errors.reply_to_address
                                    }
                                >
                                    <input
                                        type="email"
                                        value={
                                            data.reply_to_address
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "reply_to_address",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="support@example.com"
                                        className={inputClass}
                                    />
                                </Field>

                                <Field
                                    label="Reply-To Name"
                                    error={
                                        errors.reply_to_name
                                    }
                                >
                                    <input
                                        type="text"
                                        value={
                                            data.reply_to_name
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setData(
                                                "reply_to_name",
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="Support Team"
                                        className={inputClass}
                                    />
                                </Field>
                            </div>
                        </section>

                        {/* Active */}
                        <section className="rounded-xl border bg-background p-5">
                            <label className="flex cursor-pointer items-start gap-3">
                                <input
                                    type="checkbox"
                                    checked={
                                        data.is_active
                                    }
                                    onChange={(
                                        event,
                                    ) =>
                                        setData(
                                            "is_active",
                                            event
                                                .target
                                                .checked,
                                        )
                                    }
                                    className="mt-1 size-4 rounded border-input"
                                />

                                <div>
                                    <div className="font-medium">
                                        Enable this
                                        mail
                                        configuration
                                    </div>

                                    <div className="mt-1 text-sm text-muted-foreground">
                                        Only active mail
                                        settings will be
                                        used by the
                                        website.
                                    </div>
                                </div>
                            </label>

                            {errors.is_active && (
                                <p className="mt-2 text-sm text-destructive">
                                    {
                                        errors.is_active
                                    }
                                </p>
                            )}
                        </section>

                        <div className="flex justify-end">
                            <Button
                                onPress={submit}
                                isDisabled={
                                    processing
                                }
                            >
                                {processing
                                    ? "Saving..."
                                    : "Save Mail Settings"}
                            </Button>
                        </div>
                    </div>

                    {/* Right sidebar */}
                    <aside className="space-y-6">
                        {/* Test email */}
                        <section className="rounded-xl border bg-background">
                            <div className="border-b p-5">
                                <div className="flex items-center gap-3">
                                    <div className="flex size-10 items-center justify-center rounded-lg bg-muted">
                                        <Send className="size-5" />
                                    </div>

                                    <div>
                                        <h2 className="font-semibold">
                                            Send Test
                                            Email
                                        </h2>

                                        <p className="text-sm text-muted-foreground">
                                            Verify the
                                            saved SMTP
                                            configuration.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-4 p-5">
                                <div>
                                    <label className="mb-2 block text-sm font-medium">
                                        Recipient Email
                                    </label>

                                    <input
                                        type="email"
                                        value={
                                            testEmail
                                        }
                                        onChange={(
                                            event,
                                        ) =>
                                            setTestEmail(
                                                event
                                                    .target
                                                    .value,
                                            )
                                        }
                                        placeholder="you@example.com"
                                        className={inputClass}
                                    />
                                </div>

                                <Button
                                    variant="outline"
                                    className="w-full"
                                    onPress={
                                        sendTestEmail
                                    }
                                    isDisabled={
                                        testing ||
                                        !settings
                                    }
                                >
                                    <Send />

                                    {testing
                                        ? "Sending..."
                                        : "Send Test Email"}
                                </Button>

                                {!settings && (
                                    <p className="text-xs text-muted-foreground">
                                        Save your SMTP
                                        settings before
                                        sending a test
                                        email.
                                    </p>
                                )}
                            </div>
                        </section>

                        {/* Security notice */}
                        <section className="rounded-xl border bg-muted/20 p-5">
                            <div className="flex gap-3">
                                <ShieldCheck className="mt-0.5 size-5 shrink-0 text-muted-foreground" />

                                <div>
                                    <h3 className="text-sm font-semibold">
                                        Password
                                        Security
                                    </h3>

                                    <p className="mt-2 text-xs leading-5 text-muted-foreground">
                                        The SMTP
                                        password is
                                        encrypted before
                                        being stored.
                                        Existing
                                        passwords are
                                        never returned to
                                        this page.
                                    </p>
                                </div>
                            </div>
                        </section>

                        {settings && (
                            <section className="rounded-xl border bg-background p-5">
                                <h3 className="text-sm font-semibold">
                                    Current Status
                                </h3>

                                <div className="mt-4 flex items-center gap-2">
                                    <span
                                        className={`size-2.5 rounded-full ${
                                            data.is_active
                                                ? "bg-emerald-500"
                                                : "bg-slate-300"
                                        }`}
                                    />

                                    <span className="text-sm">
                                        {data.is_active
                                            ? "Mail configuration active"
                                            : "Mail configuration disabled"}
                                    </span>
                                </div>
                            </section>
                        )}
                    </aside>
                </div>
            </div>
        </AdminLayout>
    );
}

const inputClass =
    "h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none transition focus:border-ring focus:ring-3 focus:ring-ring/20";

type FieldProps = {
    label: string;
    error?: string;
    children: ReactNode;
};

function Field({
    label,
    error,
    children,
}: FieldProps) {
    return (
        <div>
            <label className="mb-2 block text-sm font-medium">
                {label}
            </label>

            {children}

            {error && (
                <p className="mt-1.5 text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}