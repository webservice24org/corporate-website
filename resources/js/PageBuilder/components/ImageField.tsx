import { useRef, useState } from "react";
import axios from "axios";
import toast from "react-hot-toast";

import {
    ImageIcon,
    Trash2,
    Upload,
} from "lucide-react";

import { Button } from "@/Components/ui/button";

type Props = {
    label: string;
    value: unknown;
    onChange: (value: string) => void;
};

export default function ImageField({
    label,
    value,
    onChange,
}: Props) {
    const fileInputRef =
        useRef<HTMLInputElement | null>(null);

    const [uploading, setUploading] =
        useState(false);

    const imageUrl =
        typeof value === "string"
            ? value
            : "";

    const uploadImage = async (
        file: File,
    ) => {
        const formData = new FormData();

        formData.append("image", file);

        try {
            setUploading(true);

            const response =
                await axios.post(
                    "/admin/page-builder/upload-image",
                    formData,
                    {
                        headers: {
                            "Content-Type":
                                "multipart/form-data",
                        },
                    },
                );

            onChange(
                response.data.url,
            );

            toast.success(
                "Image uploaded successfully.",
            );
        } catch {
            toast.error(
                "Unable to upload image.",
            );
        } finally {
            setUploading(false);

            if (fileInputRef.current) {
                fileInputRef.current.value =
                    "";
            }
        }
    };

    return (
        <div>
            <label className="mb-2 block text-sm font-medium">
                {label}
            </label>

            <input
                ref={fileInputRef}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                onChange={(event) => {
                    const file =
                        event.target.files?.[0];

                    if (file) {
                        uploadImage(file);
                    }
                }}
            />

            {imageUrl ? (
                <div className="overflow-hidden rounded-lg border bg-muted/20">
                    <div className="aspect-video overflow-hidden bg-muted">
                        <img
                            src={imageUrl}
                            alt=""
                            className="h-full w-full object-cover"
                        />
                    </div>

                    <div className="flex items-center justify-between gap-2 p-3">
                        <div className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                            {imageUrl}
                        </div>

                        <div className="flex gap-1">
                            <Button
                                type="button"
                                variant="outline"
                                size="icon-sm"
                                aria-label="Replace image"
                                isDisabled={
                                    uploading
                                }
                                onPress={() =>
                                    fileInputRef.current?.click()
                                }
                            >
                                <Upload />
                            </Button>

                            <Button
                                type="button"
                                variant="destructive"
                                size="icon-sm"
                                aria-label="Remove image"
                                isDisabled={
                                    uploading
                                }
                                onPress={() =>
                                    onChange("")
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </div>
                </div>
            ) : (
                <button
                    type="button"
                    disabled={uploading}
                    onClick={() =>
                        fileInputRef.current?.click()
                    }
                    className="flex w-full flex-col items-center justify-center rounded-lg border border-dashed bg-muted/20 px-4 py-8 text-center transition hover:bg-muted/40 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <ImageIcon className="mb-2 size-8 text-muted-foreground" />

                    <span className="text-sm font-medium">
                        {uploading
                            ? "Uploading..."
                            : "Choose Image"}
                    </span>

                    <span className="mt-1 text-xs text-muted-foreground">
                        JPG, PNG or WebP · max
                        5 MB
                    </span>
                </button>
            )}
        </div>
    );
}