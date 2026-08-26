export function getYouTubeId(
    value?: string,
): string | null {
    if (!value) {
        return null;
    }

    const input = value.trim();

    // Allow direct video ID.
    if (/^[a-zA-Z0-9_-]{11}$/.test(input)) {
        return input;
    }

    try {
        const url = new URL(input);

        if (url.hostname === "youtu.be") {
            return (
                url.pathname
                    .split("/")
                    .filter(Boolean)[0] ??
                null
            );
        }

        if (
            url.hostname.includes(
                "youtube.com",
            )
        ) {
            // youtube.com/watch?v=...
            const watchId =
                url.searchParams.get("v");

            if (watchId) {
                return watchId;
            }

            const parts =
                url.pathname
                    .split("/")
                    .filter(Boolean);

            const specialPaths = [
                "embed",
                "shorts",
                "live",
            ];

            if (
                specialPaths.includes(
                    parts[0] ?? "",
                ) &&
                parts[1]
            ) {
                return parts[1];
            }
        }
    } catch {
        return null;
    }

    return null;
}

export function getYouTubeThumbnail(
    value?: string,
): string | null {
    const id = getYouTubeId(value);

    if (!id) {
        return null;
    }

    return `https://i.ytimg.com/vi/${id}/hqdefault.jpg`;
}

export function getYouTubeEmbedUrl(
    value?: string,
): string | null {
    const id = getYouTubeId(value);

    if (!id) {
        return null;
    }

    return `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0`;
}