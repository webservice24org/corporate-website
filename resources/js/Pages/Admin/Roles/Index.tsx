import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    Link,
    router,
} from "@inertiajs/react";

import {
    Pencil,
    Plus,
    ShieldCheck,
    Trash2,
    Users,
} from "lucide-react";

import {
    useState,
} from "react";

import {
    Button,
} from "@/Components/ui/button";

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from "@/Components/ui/alert-dialog";

import {
    useAuthorization,
} from "@/hooks/useAuthorization";

type Role = {
    id: number;

    name: string;

    permissions_count: number;

    users_count: number;
};

type Props = {
    roles: Role[];
};

export default function Index({
    roles,
}: Props) {

    const {
        can,
    } = useAuthorization();

    return (
        <AdminLayout>
            <Head title="Roles & Permissions" />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Roles & Permissions
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Control what users
                            can access throughout
                            the administration
                            and ERP system.
                        </p>
                    </div>

                    {can(
                        "roles.create",
                    ) && (
                        <Link
                            href="/admin/roles/create"
                            className="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-xs transition hover:bg-primary/90"
                        >
                            <Plus className="size-4" />

                            Create Role
                        </Link>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border bg-background shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[800px] text-left text-sm">
                            <thead className="border-b bg-muted/40">
                                <tr>
                                    <th className="px-5 py-3 font-semibold">
                                        Role
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Permissions
                                    </th>

                                    <th className="px-5 py-3 font-semibold">
                                        Users
                                    </th>

                                    <th className="px-5 py-3 text-right font-semibold">
                                        Actions
                                    </th>
                                </tr>
                            </thead>

                            <tbody className="divide-y">
                                {roles.map(
                                    (role) => (
                                        <RoleRow
                                            key={
                                                role.id
                                            }
                                            role={
                                                role
                                            }
                                        />
                                    ),
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}

function RoleRow({
    role,
}: {
    role: Role;
}) {
    const [
        deleteOpen,
        setDeleteOpen,
    ] = useState(false);

    const [
        deleting,
        setDeleting,
    ] = useState(false);

    const protectedRole =
        role.name ===
        "super-admin";

    const destroy = () => {
        setDeleting(true);

        router.delete(
            `/admin/roles/${role.id}`,
            {
                preserveScroll:
                    true,

                onSuccess: () => {
                    setDeleteOpen(
                        false,
                    );
                },

                onFinish: () => {
                    setDeleting(
                        false,
                    );
                },
            },
        );
    };

    const {
        can,
    } = useAuthorization();
    return (
        <tr className="transition hover:bg-muted/30">
            <td className="px-5 py-4">
                <div className="flex items-center gap-3">
                    <div
                        className={`flex size-10 items-center justify-center rounded-lg ${
                            protectedRole
                                ? "bg-amber-50 text-amber-600"
                                : "bg-primary/10 text-primary"
                        }`}
                    >
                        <ShieldCheck className="size-5" />
                    </div>

                    <div>
                        <div className="font-semibold capitalize">
                            {role.name.replace(
                                /-/g,
                                " ",
                            )}
                        </div>

                        {protectedRole && (
                            <div className="mt-0.5 text-xs font-medium text-amber-600">
                                Protected role
                            </div>
                        )}
                    </div>
                </div>
            </td>

            <td className="px-5 py-4">
                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                    {
                        role.permissions_count
                    }{" "}
                    permissions
                </span>
            </td>

            <td className="px-5 py-4">
                <div className="flex items-center gap-2 text-muted-foreground">
                    <Users className="size-4" />

                    {
                        role.users_count
                    }
                </div>
            </td>

            <td className="px-5 py-4">
                <div className="flex justify-end gap-2">
                    {can(
                        "roles.edit",
                    ) && (
                        <Link
                            href={`/admin/roles/${role.id}/edit`}
                            aria-label="Edit role"
                            className="inline-flex size-8 items-center justify-center rounded-md border border-input bg-background shadow-xs transition hover:bg-accent hover:text-accent-foreground"
                        >
                            <Pencil className="size-4" />
                        </Link>
                    )}
                    {can(
                    "roles.delete",
                ) &&
                    !protectedRole && (
                        <AlertDialogTrigger
                            isOpen={
                                deleteOpen
                            }
                            onOpenChange={
                                setDeleteOpen
                            }
                        >
                            <Button
                                type="button"
                                variant="destructive"
                                size="icon-sm"
                                aria-label="Delete role"
                            >
                                <Trash2 />
                            </Button>

                            <AlertDialog>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>
                                        Delete this
                                        role?
                                    </AlertDialogTitle>

                                    <AlertDialogDescription>
                                        This will
                                        permanently
                                        delete the{" "}
                                        <strong>
                                            {role.name}
                                        </strong>{" "}
                                        role. Roles
                                        currently
                                        assigned to
                                        users cannot
                                        be deleted.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>

                                <AlertDialogFooter>
                                    <AlertDialogCancel
                                        isDisabled={
                                            deleting
                                        }
                                    >
                                        Cancel
                                    </AlertDialogCancel>

                                    <AlertDialogAction
                                        variant="destructive"
                                        onPress={
                                            destroy
                                        }
                                        isDisabled={
                                            deleting
                                        }
                                    >
                                        {deleting
                                            ? "Deleting..."
                                            : "Delete Role"}
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialog>
                        </AlertDialogTrigger>
                    )}
                </div>
            </td>
        </tr>
    );
}