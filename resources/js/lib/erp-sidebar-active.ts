/** Compare path segments; filters and fragments never change the active menu. */
export function sidebarPath(url: string): string {
    const path = url.split(/[?#]/, 1)[0].replace(/\/+$/, "");
    return path || "/";
}

export function sidebarLinkIsActive(
    currentUrl: string,
    href: string,
    exact = false,
    excludePrefix?: string,
): boolean {
    const current = sidebarPath(currentUrl);
    const target = sidebarPath(href);
    const within = (prefix: string): boolean =>
        current === prefix || current.startsWith(`${prefix}/`);

    return (exact ? current === target : within(target)) &&
        !(excludePrefix && within(sidebarPath(excludePrefix)));
}
