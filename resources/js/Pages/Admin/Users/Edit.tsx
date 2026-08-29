import AdminLayout from "@/Layouts/admin-layout";

import {
    Head,
    Link,
    useForm,
} from "@inertiajs/react";

import {
    ArrowLeft,
    Save,
} from "lucide-react";

import {
    Button,
} from "@/Components/ui/button";

import UserForm, {
    type UserFormData,
} from "@/Components/Admin/UserForm";

type Role = {
    id: number;
    name: string;
};

type UserProfile = {
    profile_photo:
        | string
        | null;

    phone:
        | string
        | null;

    employee_id:
        | string
        | null;

    designation:
        | string
        | null;

    department:
        | string
        | null;

    date_of_birth:
        | string
        | null;

    gender:
        | string
        | null;

    address:
        | string
        | null;

    city:
        | string
        | null;

    country:
        | string
        | null;

    bio:
        | string
        | null;
};

type User = {
    id: number;

    name: string;
    email: string;

    roles: string[];

    profile:
        | UserProfile
        | null;
};

type Props = {
    user: User;

    roles: Role[];
};

export default function Edit({
    user,
    roles,
}: Props) {
    const profile =
        user.profile;

    const {
        data,
        setData,
        post,
        processing,
        errors,
        transform,
    } = useForm<UserFormData & {
        _method?: string;
    }>({
        name:
            user.name,

        email:
            user.email,

        password: "",

        password_confirmation:
            "",

        roles:
            user.roles ?? [],

        profile_photo:
            null,

        remove_profile_photo:
            false,

        phone:
            profile?.phone ??
            "",

        employee_id:
            profile?.employee_id ??
            "",

        designation:
            profile?.designation ??
            "",

        department:
            profile?.department ??
            "",

        date_of_birth:
            profile?.date_of_birth
                ? profile.date_of_birth.substring(
                      0,
                      10,
                  )
                : "",

        gender:
            profile?.gender ??
            "",

        address:
            profile?.address ??
            "",

        city:
            profile?.city ??
            "",

        country:
            profile?.country ??
            "",

        bio:
            profile?.bio ??
            "",
    });

    const submit = () => {
        /*
         * File uploads + PUT can be
         * awkward with PHP multipart
         * requests, so use POST with
         * method spoofing.
         */
        transform(
            (formData) => ({
                ...formData,

                _method: "put",
            }),
        );

        post(
            `/admin/users/${user.id}`,
            {
                forceFormData:
                    true,
            },
        );
    };

    const currentPhoto =
        profile?.profile_photo
            ? `/storage/${profile.profile_photo}`
            : null;

    return (
        <AdminLayout>
            <Head
                title={`Edit User - ${user.name}`}
            />

            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">
                            Edit User
                        </h1>

                        <p className="mt-1 text-sm text-muted-foreground">
                            Update account,
                            profile and role
                            assignments.
                        </p>
                    </div>

                    <div className="flex gap-2">
                        <Link
                            href="/admin/users"
                            className="inline-flex h-9 items-center gap-2 rounded-md border bg-background px-4 text-sm font-medium hover:bg-muted"
                        >
                            <ArrowLeft className="size-4" />
                            Back
                        </Link>

                        <Button
                            onPress={
                                submit
                            }
                            isDisabled={
                                processing
                            }
                        >
                            <Save />

                            {processing
                                ? "Saving..."
                                : "Save Changes"}
                        </Button>
                    </div>
                </div>

                <UserForm
                    data={
                        data
                    }
                    setData={
                        setData
                    }
                    errors={
                        errors
                    }
                    roles={
                        roles
                    }
                    currentPhoto={
                        currentPhoto
                    }
                    editing
                />
            </div>
        </AdminLayout>
    );
}