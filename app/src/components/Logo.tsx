import { View } from "react-native";
import Svg, { Path } from "react-native-svg";
import { useTheme } from "@/lib/theme";
import { T } from "./ui";

const TOP =
  "M670 215H1080L960 362H665C632 362 604 378 572 405C541 432 512 462 490 492C474 462 468 436 468 410C468 302 560 215 670 215Z";
const FOLD =
  "M654 390V483L912 572V636C912 643 906 648 898 648H840L620 597C571 585 535 561 507 517C530 489 560 458 588 434C612 413 632 398 654 390Z";
const BOTTOM =
  "M680 388H713L930 432C1018 450 1080 528 1080 612C1080 718 994 806 895 806H455L566 662H905C925 662 936 651 936 636V553L680 464Z";

/** The SASA mark: navy S with the blue inner fold, reversed on dark. */
export function Mark({ size = 48 }: { size?: number }) {
  const theme = useTheme();
  return (
    <Svg width={size} height={size} viewBox="447 190 640 640" accessibilityLabel="SASA">
      <Path d={TOP} fill={theme.brandInk} />
      <Path d={FOLD} fill={theme.brandFold} />
      <Path d={BOTTOM} fill={theme.brandInk} />
    </Svg>
  );
}

/** The mark with the wordmark beside it. */
export function Wordmark({ size = 40 }: { size?: number }) {
  const theme = useTheme();
  return (
    <View style={{ flexDirection: "row", alignItems: "center", gap: size * 0.28 }} accessible accessibilityLabel="SASA Compliance">
      <Mark size={size} />
      <View>
        <T style={{ fontSize: size * 0.56, lineHeight: size * 0.62, fontWeight: "800", color: theme.brandInk, letterSpacing: 0.5 }}>
          SASA
        </T>
        <T
          style={{
            fontSize: size * 0.2,
            lineHeight: size * 0.26,
            fontWeight: "600",
            color: theme.secondary,
            letterSpacing: size * 0.06,
          }}
        >
          COMPLIANCE
        </T>
      </View>
    </View>
  );
}
