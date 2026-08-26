import {
    MessageCircle,
    Phone,
} from "lucide-react";

import type {
    SectionSettings,
} from "../../types";

type Props = {
    settings: SectionSettings;
    preview?: boolean;
};

export default function FloatingContact01({
    settings,
    preview = false,
}: Props) {
    const showPhone = Boolean(
        settings.show_phone ?? true,
    );

    const phoneNumber = String(
        settings.phone_number ??
            "+8801700000000",
    );

    const phoneLabel = String(
        settings.phone_label ??
            "Call Corporate",
    );

    const phoneColor = String(
        settings.phone_color ??
            "#059669",
    );

    const showWhatsApp = Boolean(
        settings.show_whatsapp ?? true,
    );

    const whatsappNumber = String(
        settings.whatsapp_number ??
            "8801800000000",
    );

    const whatsappLabel = String(
        settings.whatsapp_label ??
            "Chat on WhatsApp",
    );

    const whatsappColor = String(
        settings.whatsapp_color ??
            "#22c55e",
    );

    const showPulse = Boolean(
        settings.whatsapp_pulse ??
            true,
    );

    const position = String(
        settings.position ??
            "bottom-right",
    );

    const bottomOffset = Number(
        settings.bottom_offset ?? 24,
    );

    const sideOffset = Number(
        settings.side_offset ?? 24,
    );

    const buttonSize = Math.max(
        44,
        Number(
            settings.button_size ?? 56,
        ),
    );

    const positionClass =
        position === "bottom-left"
            ? "left-6"
            : "right-6";

    const sideStyle =
        position === "bottom-left"
            ? {
                  left:
                      `${sideOffset}px`,
              }
            : {
                  right:
                      `${sideOffset}px`,
              };

    return (
        <div
            className={`fixed z-50 flex flex-col gap-3 ${positionClass}`}
            style={{
                bottom:
                    `${bottomOffset}px`,
                ...sideStyle,
            }}
        >
            {showPhone && (
                <a
                    href={
                        preview
                            ? undefined
                            : `tel:${phoneNumber}`
                    }
                    onClick={(event) => {
                        if (preview) {
                            event.preventDefault();
                        }
                    }}
                    className="group/btn relative flex items-center justify-center rounded-full text-white shadow-2xl transition-all duration-300 hover:scale-110 active:scale-95"
                    style={{
                        width:
                            `${buttonSize}px`,

                        height:
                            `${buttonSize}px`,

                        backgroundColor:
                            phoneColor,
                    }}
                    aria-label={
                        phoneLabel
                    }
                >
                    <Phone className="size-5" />

                    <Tooltip
                        label={
                            phoneLabel
                        }
                        position={
                            position
                        }
                    />
                </a>
            )}

            {showWhatsApp && (
                <a
                    href={
                        preview
                            ? undefined
                            : `https://wa.me/${normalizeWhatsAppNumber(
                                  whatsappNumber,
                              )}`
                    }
                    target={
                        preview
                            ? undefined
                            : "_blank"
                    }
                    rel="noreferrer"
                    onClick={(event) => {
                        if (preview) {
                            event.preventDefault();
                        }
                    }}
                    className="group/btn relative flex items-center justify-center rounded-full text-white shadow-2xl transition-all duration-300 hover:scale-110 active:scale-95"
                    style={{
                        width:
                            `${buttonSize}px`,

                        height:
                            `${buttonSize}px`,

                        backgroundColor:
                            whatsappColor,
                    }}
                    aria-label={
                        whatsappLabel
                    }
                >
                    {showPulse && (
                        <span
                            className="absolute inset-0 animate-ping rounded-full opacity-60"
                            style={{
                                backgroundColor:
                                    whatsappColor,
                            }}
                        />
                    )}

                    <MessageCircle className="relative z-10 size-6" />

                    <Tooltip
                        label={
                            whatsappLabel
                        }
                        position={
                            position
                        }
                    />
                </a>
            )}
        </div>
    );
}

type TooltipProps = {
    label: string;
    position: string;
};

function Tooltip({
    label,
    position,
}: TooltipProps) {
    const placement =
        position === "bottom-left"
            ? "left-16"
            : "right-16";

    return (
        <span
            className={`pointer-events-none absolute ${placement} whitespace-nowrap rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white opacity-0 shadow-md transition-opacity duration-200 group-hover/btn:opacity-100`}
        >
            {label}
        </span>
    );
}

function normalizeWhatsAppNumber(
    value: string,
) {
    return value.replace(
        /[^0-9]/g,
        "",
    );
}