import type { ComponentType } from "react";

export type SectionFieldPanel =
    | "content"
    | "background"
    | "typography"
    | "alignment"
    | "spacing"
    | "layout"
    | "responsive"
    | "developer";

export type SectionFieldGroup =
    | "content"
    | "style"
    | "advanced";


export type SectionType =
    | "hero"
    | "about"
    | "services"
    | "gallery"
    | "video-gallery"
    | "page-banner"
    | "cta"
    | "message"
    | "contact"
    | "values"
    | "footer"
    | "floating-contact"
    | "team"
    | "map";

export type SectionSettings = Record<string, unknown>;

export type FieldCondition = {
    field: string;
    equals: unknown;
};

export type BaseSectionField = {
    key: string;
    label: string;
    placeholder?: string;

    group?: SectionFieldGroup;
    panel?: SectionFieldPanel;
    maxLength?: number;
    visibleWhen?: FieldCondition;
};

export type SectionField =
    | (BaseSectionField & {
          type: "text" | "url" | "number" | "color" | "image";
      })
    | (BaseSectionField & {
          type: "textarea";
          rows?: number;
      })

    | (BaseSectionField & {
            type: "icon";
        })
        
    | (BaseSectionField & {
          type: "select";
          options: {
              label: string;
              value: string;
          }[];
      })
    | (BaseSectionField & {
          type: "checkbox";
      })
    | (BaseSectionField & {
        type: "repeater";

        min?: number;
        max?: number;

        itemLabel?: string;

        defaultItem?: Record<string, unknown>;

        fields: SectionField[];
    });

export type SectionDesign = {
    id: string;
    label: string;
    description?: string;

    component: ComponentType<{
        settings: SectionSettings;
        preview?: boolean;
    }>;

    defaultSettings: SectionSettings;

    fields: SectionField[];
};

export type SectionGroup = {
    type: SectionType;
    label: string;
    designs: SectionDesign[];
};

export type BuilderSection = {
    id: number;
    page_id: number;
    type: SectionType;
    name: string | null;
    variant: string;
    settings: SectionSettings;
    sort_order: number;
    is_active: boolean;
};