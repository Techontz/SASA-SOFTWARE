import * as Haptics from "expo-haptics";
import { Children, Fragment, isValidElement, useEffect, useState, type ReactNode } from "react";
import {
  ActivityIndicator,
  Animated,
  Pressable,
  StyleSheet,
  Text as RNText,
  View,
  type StyleProp,
  type TextProps,
  type TextStyle,
  type ViewStyle,
} from "react-native";
import { radius, space, type, useTheme, type Tone, type TypeVariant } from "@/lib/theme";
import { Icon, type IconName } from "./Icon";

// ------------------------------------------------------------------ text

type TextTone = "primary" | "secondary" | "tertiary" | "accent" | "danger" | "onAction";

interface TProps extends TextProps {
  variant?: TypeVariant;
  tone?: TextTone;
  weight?: TextStyle["fontWeight"];
  align?: TextStyle["textAlign"];
}

export function T({ variant = "body", tone = "primary", weight, align, style, ...rest }: TProps) {
  const theme = useTheme();
  const color = {
    primary: theme.text,
    secondary: theme.secondary,
    tertiary: theme.tertiary,
    accent: theme.accent,
    danger: theme.tone.danger.fg,
    onAction: theme.onAction,
  }[tone];
  return (
    <RNText
      {...rest}
      maxFontSizeMultiplier={1.6}
      style={[type[variant], { color }, weight ? { fontWeight: weight } : null, align ? { textAlign: align } : null, style]}
    />
  );
}

// ------------------------------------------------------------- grouped list

interface SectionProps {
  title?: string;
  footer?: ReactNode;
  children: ReactNode;
  /** Separators start after the leading icon, as in Settings. */
  inset?: number;
  style?: StyleProp<ViewStyle>;
  action?: ReactNode;
}

/** An inset grouped section: an optional header, a rounded card of rows, a footer. */
export function Section({ title, footer, children, inset = space.l, style, action }: SectionProps) {
  const theme = useTheme();
  const rows = Children.toArray(children).filter(isValidElement);
  return (
    <View style={[styles.section, style]}>
      {title ? (
        <View style={styles.sectionHeader}>
          <T variant="title3" weight="700" style={{ flex: 1 }} accessibilityRole="header">
            {title}
          </T>
          {action}
        </View>
      ) : null}
      <View style={[styles.card, { backgroundColor: theme.card }]}>
        {rows.map((row, index) => (
          <Fragment key={row.key ?? index}>
            {index > 0 ? (
              <View style={[styles.separator, { backgroundColor: theme.separator, marginLeft: inset }]} />
            ) : null}
            {row}
          </Fragment>
        ))}
      </View>
      {footer ? (
        <View style={styles.sectionFooter}>
          {typeof footer === "string" ? (
            <T variant="footnote" tone="secondary">
              {footer}
            </T>
          ) : (
            footer
          )}
        </View>
      ) : null}
    </View>
  );
}

/** A rounded tile behind an icon, tinted by tone. */
export function IconTile({ name, tone = "accent", size = 30 }: { name: IconName; tone?: Tone; size?: number }) {
  const theme = useTheme();
  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size * 0.26,
        backgroundColor: theme.tone[tone].solid,
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      <Icon name={name} size={size * 0.56} color="#ffffff" weight="semibold" />
    </View>
  );
}

interface RowProps {
  title: string;
  subtitle?: string | null;
  value?: string | number | null;
  icon?: IconName;
  iconTone?: Tone;
  onPress?: () => void;
  chevron?: boolean;
  destructive?: boolean;
  accent?: boolean;
  checked?: boolean;
  right?: ReactNode;
  titleLines?: number;
  accessibilityLabel?: string;
}

export function Row({
  title,
  subtitle,
  value,
  icon,
  iconTone,
  onPress,
  chevron = Boolean(onPress),
  destructive,
  accent,
  checked,
  right,
  titleLines = 2,
  accessibilityLabel,
}: RowProps) {
  const theme = useTheme();
  const content = (
    <View style={styles.row}>
      {icon ? <IconTile name={icon} tone={iconTone} /> : null}
      <View style={{ flex: 1, paddingVertical: subtitle ? 10 : 12 }}>
        <T numberOfLines={titleLines} style={destructive ? { color: theme.tone.danger.fg } : null}>
          {title}
        </T>
        {subtitle ? (
          <T variant="subhead" tone="secondary" numberOfLines={2} style={{ marginTop: 1 }}>
            {subtitle}
          </T>
        ) : null}
      </View>
      {value !== undefined && value !== null && value !== "" ? (
        <T tone={accent ? "accent" : "secondary"} numberOfLines={1} style={{ maxWidth: "60%" }}>
          {String(value)}
        </T>
      ) : null}
      {right}
      {checked ? <Icon name="check" size={17} color={theme.accent} weight="semibold" /> : null}
      {chevron ? <Icon name="chevron" size={13} color={theme.tertiary} weight="semibold" /> : null}
    </View>
  );
  if (!onPress) return content;
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? [title, value].filter(Boolean).join(", ")}
      style={({ pressed }) => (pressed ? { backgroundColor: theme.pressed } : null)}
    >
      {content}
    </Pressable>
  );
}

// ------------------------------------------------------------------ badges

/** Status is never colour alone: every badge carries a word. */
export function Badge({ label, tone = "neutral", icon }: { label: string; tone?: Tone; icon?: IconName }) {
  const theme = useTheme();
  const colours = theme.tone[tone];
  return (
    <View style={[styles.badge, { backgroundColor: colours.bg }]}>
      {icon ? <Icon name={icon} size={11} color={colours.fg} weight="bold" /> : null}
      <RNText maxFontSizeMultiplier={1.4} style={[type.caption, { color: colours.fg, fontWeight: "600" }]}>
        {label}
      </RNText>
    </View>
  );
}

// ----------------------------------------------------------------- buttons

interface ButtonProps {
  title: string;
  onPress: () => void;
  variant?: "action" | "secondary" | "plain";
  loading?: boolean;
  disabled?: boolean;
  icon?: IconName;
  style?: StyleProp<ViewStyle>;
}

/** A capsule. The warm action colour is kept for the one main call to action. */
export function Button({ title, onPress, variant = "action", loading, disabled, icon, style }: ButtonProps) {
  const theme = useTheme();
  const background = (pressed: boolean) =>
    variant === "action"
      ? pressed
        ? theme.actionPressed
        : theme.action
      : variant === "secondary"
        ? pressed
          ? theme.pressed
          : theme.fill
        : "transparent";
  const color = variant === "action" ? theme.onAction : theme.accent;
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: Boolean(disabled || loading), busy: Boolean(loading) }}
      disabled={disabled || loading}
      onPress={() => {
        void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
        onPress();
      }}
      style={({ pressed }) => [
        styles.button,
        { backgroundColor: background(pressed), opacity: disabled ? 0.45 : 1 },
        variant === "plain" && pressed ? { opacity: 0.5 } : null,
        style,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={color} />
      ) : (
        <View style={{ flexDirection: "row", alignItems: "center", gap: 8 }}>
          {icon ? <Icon name={icon} size={17} color={color} weight="semibold" /> : null}
          <T variant="headline" style={{ color }}>
            {title}
          </T>
        </View>
      )}
    </Pressable>
  );
}

// --------------------------------------------------------------- skeletons

/** A soft, breathing placeholder in the shape of what is coming. */
export function Skeleton({ width, height = 14, style }: { width: ViewStyle["width"]; height?: number; style?: StyleProp<ViewStyle> }) {
  const theme = useTheme();
  const [opacity] = useState(() => new Animated.Value(0.55));
  useEffect(() => {
    const loop = Animated.loop(
      Animated.sequence([
        Animated.timing(opacity, { toValue: 1, duration: 750, useNativeDriver: true }),
        Animated.timing(opacity, { toValue: 0.55, duration: 750, useNativeDriver: true }),
      ]),
    );
    loop.start();
    return () => loop.stop();
  }, [opacity]);
  return <Animated.View style={[{ width, height, borderRadius: height / 2.4, backgroundColor: theme.fill, opacity }, style]} />;
}

export function SkeletonList({ rows = 6, title = true }: { rows?: number; title?: boolean }) {
  const theme = useTheme();
  return (
    <View style={styles.section} accessibilityLabel="Loading" accessible>
      <View style={[styles.card, { backgroundColor: theme.card }]}>
        {Array.from({ length: rows }).map((_, index) => (
          <View key={index}>
            {index > 0 ? <View style={[styles.separator, { backgroundColor: theme.separator, marginLeft: space.l }]} /> : null}
            <View style={{ padding: space.l, gap: 9 }}>
              <Skeleton width={80} height={11} />
              {title ? <Skeleton width={`${85 - ((index * 13) % 30)}%`} height={15} /> : null}
              <Skeleton width={140} height={11} />
            </View>
          </View>
        ))}
      </View>
    </View>
  );
}

// ------------------------------------------------------------------ states

interface MessageProps {
  icon: IconName;
  title: string;
  message?: string;
  actionLabel?: string;
  onAction?: () => void;
}

/** Empty, offline and error states share one calm layout. */
export function Message({ icon, title, message, actionLabel, onAction }: MessageProps) {
  const theme = useTheme();
  return (
    <View style={styles.message}>
      <Icon name={icon} size={40} color={theme.tertiary} weight="regular" />
      <T variant="title3" align="center" style={{ marginTop: space.m }}>
        {title}
      </T>
      {message ? (
        <T tone="secondary" align="center" variant="subhead" style={{ marginTop: 6, maxWidth: 300 }}>
          {message}
        </T>
      ) : null}
      {actionLabel && onAction ? (
        <Button title={actionLabel} variant="secondary" onPress={onAction} style={{ marginTop: space.xl, paddingHorizontal: 28 }} />
      ) : null}
    </View>
  );
}

/** A slim notice inside a screen: offline, or what still needs doing. */
export function Notice({ tone, icon, title, message }: { tone: Tone; icon: IconName; title: string; message?: string }) {
  const theme = useTheme();
  const colours = theme.tone[tone];
  return (
    <View style={[styles.notice, { backgroundColor: colours.bg }]} accessibilityRole="alert">
      <Icon name={icon} size={18} color={colours.fg} weight="semibold" />
      <View style={{ flex: 1 }}>
        <T variant="subhead" weight="600" style={{ color: colours.fg }}>
          {title}
        </T>
        {message ? (
          <T variant="footnote" style={{ color: colours.fg, marginTop: 2 }}>
            {message}
          </T>
        ) : null}
      </View>
    </View>
  );
}

export const styles = StyleSheet.create({
  section: { marginHorizontal: space.l, marginTop: space.xxl },
  sectionHeader: { flexDirection: "row", alignItems: "center", paddingHorizontal: 4, marginBottom: space.s },
  sectionFooter: { paddingHorizontal: space.l, paddingTop: space.s },
  card: { borderRadius: radius.card, overflow: "hidden", borderCurve: "continuous" },
  separator: { height: StyleSheet.hairlineWidth },
  row: { flexDirection: "row", alignItems: "center", gap: space.m, paddingHorizontal: space.l, minHeight: 48 },
  badge: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: radius.capsule,
    alignSelf: "flex-start",
  },
  button: {
    height: 52,
    borderRadius: radius.capsule,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: space.xl,
  },
  message: { alignItems: "center", paddingHorizontal: space.xxxl, paddingVertical: 64 },
  notice: {
    flexDirection: "row",
    gap: space.m,
    padding: space.m,
    paddingHorizontal: space.l,
    borderRadius: radius.row + 2,
    marginHorizontal: space.l,
    marginTop: space.l,
    borderCurve: "continuous",
  },
});
