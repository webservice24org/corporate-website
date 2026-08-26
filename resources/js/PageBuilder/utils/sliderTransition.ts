export type SliderTransition =
    | "fade"
    | "slide-left"
    | "slide-right"
    | "none";

export function getSliderTransitionClass(
    effect: SliderTransition,
    active: boolean,
) {
    if (effect === "none") {
        return active
            ? "opacity-100"
            : "hidden";
    }

    if (effect === "slide-left") {
        return active
            ? "translate-x-0 opacity-100"
            : "translate-x-full opacity-0";
    }

    if (effect === "slide-right") {
        return active
            ? "translate-x-0 opacity-100"
            : "-translate-x-full opacity-0";
    }

    return active
        ? "opacity-100"
        : "opacity-0";
}