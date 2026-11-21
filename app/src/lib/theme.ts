import { useColorScheme } from "react-native";

/**
 * Every colour the app uses, in both themes. Components read these through
 * `useTheme()` and never hard-code a value, so a screen is correct in light
 * and dark without being touched.
 *
 * Dark surfaces are the brand navy, never black.
 */
export type Tone = "neutral" | "accent" | "success" | "warning" | "danger" | "info";

export interface Palette {
  scheme: "light" | "dark";
  canvas: string;
  card: string;
  raised: string;
  pressed: string;
  separator: string;
  text: string;
  secondary: string;
  tertiary: string;
  placeholder: string;
  accent: string;
  action: string;
  actionPressed: string;
  onAction: string;
  brandInk: string;
  brandFold: string;
  fill: string;
  segmentTrack: string;
  segmentThumb: string;
  tone: Record<Tone, { fg: string; bg: string; solid: string }>;
}

const light: Palette = {
  scheme: "light",
  canvas: "#f5f5f7",
  card: "#ffffff",
  raised: "#ffffff",
  pressed: "#e9ebee",
  separator: "#dfe3e8",
  text: "#0f1d29",
  secondary: "#5a6e81",
  tertiary: "#8494a5",
  placeholder: "#9aa7b4",
  accent: "#2a8291",
  action: "#bd591d",
  actionPressed: "#a14a16",
  onAction: "#ffffff",
  brandInk: "#15243a",
  brandFold: "#47709b",
  fill: "#eef0f3",
  segmentTrack: "#e6e8ec",
  segmentThumb: "#ffffff",
  tone: {
    neutral: { fg: "#4b5d6f", bg: "#eef0f3", solid: "#7b8a99" },
    accent: { fg: "#1f6a77", bg: "#e3f1f3", solid: "#2a8291" },
    success: { fg: "#10714d", bg: "#e5f3ec", solid: "#10714d" },
    warning: { fg: "#93540a", bg: "#fbf0df", solid: "#c27310" },
    danger: { fg: "#b42318", bg: "#fdeceb", solid: "#b42318" },
    info: { fg: "#175cd3", bg: "#e8f0fd", solid: "#175cd3" },
  },
};

const dark: Palette = {
  scheme: "dark",
  canvas: "#162236",
  card: "#1e2c43",
  raised: "#25354f",
  pressed: "#2a3a54",
  separator: "#2a3a54",
  text: "#f5f7fa",
  secondary: "#9aa9bd",
  tertiary: "#7586a0",
  placeholder: "#6f809a",
  accent: "#64b2c0",
  action: "#bd591d",
  actionPressed: "#a14a16",
  onAction: "#ffffff",
  brandInk: "#ffffff",
  brandFold: "#7aa5d2",
  fill: "#25354f",
  segmentTrack: "#1e2c43",
  segmentThumb: "#3a4e70",
  tone: {
    neutral: { fg: "#c3cedb", bg: "#2a3a54", solid: "#7586a0" },
    accent: { fg: "#7cc4d1", bg: "#1f3d4d", solid: "#2a8291" },
    success: { fg: "#6fd3a8", bg: "#183f3b", solid: "#178a5f" },
    warning: { fg: "#f2b766", bg: "#3d3630", solid: "#c27310" },
    danger: { fg: "#ff958c", bg: "#43283a", solid: "#c9372b" },
    info: { fg: "#8fb4ff", bg: "#223661", solid: "#2b6fe0" },
  },
};

export function useTheme(): Palette {
  return useColorScheme() === "dark" ? dark : light;
}

/** Apple's text styles, at the default Dynamic Type size. */
export const type = {
  largeTitle: { fontSize: 34, lineHeight: 41, fontWeight: "700" },
  title1: { fontSize: 28, lineHeight: 34, fontWeight: "700" },
  title2: { fontSize: 22, lineHeight: 28, fontWeight: "700" },
  title3: { fontSize: 20, lineHeight: 25, fontWeight: "600" },
  headline: { fontSize: 17, lineHeight: 22, fontWeight: "600" },
  body: { fontSize: 17, lineHeight: 22, fontWeight: "400" },
  callout: { fontSize: 16, lineHeight: 21, fontWeight: "400" },
  subhead: { fontSize: 15, lineHeight: 20, fontWeight: "400" },
  footnote: { fontSize: 13, lineHeight: 18, fontWeight: "400" },
  caption: { fontSize: 12, lineHeight: 16, fontWeight: "400" },
} as const;

export type TypeVariant = keyof typeof type;

export const space = { xs: 4, s: 8, m: 12, l: 16, xl: 20, xxl: 28, xxxl: 36 } as const;
export const radius = { card: 14, row: 10, tile: 8, capsule: 999 } as const;
