import * as Haptics from "expo-haptics";
import { useRouter, useSegments, type Href } from "expo-router";
import { useCallback, useState, type ReactNode } from "react";
import { ActivityIndicator, Pressable, RefreshControl, StyleSheet, View } from "react-native";
import { ApiError } from "@/lib/api";
import { radius, space, useTheme, type Palette } from "@/lib/theme";
import { Icon, type IconName } from "./Icon";
import { Message, T } from "./ui";

/** One row of a long list, drawn as part of a single rounded card. */
export function CardRow({
  first,
  last,
  onPress,
  children,
  accessibilityLabel,
  inset = space.l,
}: {
  inset?: number;
  first: boolean;
  last: boolean;
  onPress?: () => void;
  children: ReactNode;
  accessibilityLabel?: string;
}) {
  const theme = useTheme();
  return (
    <Pressable
      onPress={onPress}
      disabled={!onPress}
      accessibilityRole={onPress ? "button" : undefined}
      accessibilityLabel={accessibilityLabel}
      style={({ pressed }) => [
        styles.cardRow,
        { backgroundColor: pressed ? theme.pressed : theme.card },
        first ? styles.first : null,
        last ? styles.last : null,
      ]}
    >
      <View style={{ flex: 1 }}>{children}</View>
      {onPress ? <Icon name="chevron" size={13} color={theme.tertiary} weight="semibold" /> : null}
      {!last ? <View style={[styles.separator, { backgroundColor: theme.separator, left: inset }]} /> : null}
    </Pressable>
  );
}

/** A full-width segmented control for a list's filters. */
export function Segments<V extends string>({ value, options, onChange }: { value: V; options: { value: V; label: string }[]; onChange: (value: V) => void }) {
  const theme = useTheme();
  return (
    <View style={[styles.segments, { backgroundColor: theme.segmentTrack }]} accessibilityRole="tablist">
      {options.map((option) => {
        const selected = option.value === value;
        return (
          <Pressable
            key={option.value}
            accessibilityRole="tab"
            accessibilityState={{ selected }}
            onPress={() => {
              if (selected) return;
              void Haptics.selectionAsync();
              onChange(option.value);
            }}
            style={[styles.segment, selected ? [styles.segmentSelected, { backgroundColor: theme.segmentThumb }] : null]}
          >
            <T variant="footnote" weight={selected ? "600" : "500"} tone={selected ? "primary" : "secondary"}>
              {option.label}
            </T>
          </Pressable>
        );
      })}
    </View>
  );
}

export function HeaderButton({ icon, onPress, label }: { icon: IconName; onPress: () => void; label: string }) {
  const theme = useTheme();
  return (
    <Pressable
      onPress={() => {
        void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
        onPress();
      }}
      hitSlop={10}
      accessibilityRole="button"
      accessibilityLabel={label}
      style={({ pressed }) => ({ opacity: pressed ? 0.5 : 1, paddingHorizontal: 4 })}
    >
      <Icon name={icon} size={22} color={theme.accent} weight="medium" />
    </Pressable>
  );
}

export function LoadingMore({ visible }: { visible: boolean }) {
  return <View style={{ height: 64, justifyContent: "center" }}>{visible ? <ActivityIndicator /> : null}</View>;
}

/** Pull to refresh that does not flicker when a background refetch runs. */
export function usePullToRefresh(refetch: () => Promise<unknown>) {
  const theme = useTheme();
  const [refreshing, setRefreshing] = useState(false);
  const onRefresh = useCallback(async () => {
    setRefreshing(true);
    try {
      await refetch();
    } finally {
      setRefreshing(false);
    }
  }, [refetch]);
  return <RefreshControl refreshing={refreshing} onRefresh={() => void onRefresh()} tintColor={theme.secondary} />;
}

/** The empty, offline or error state for a list or record. */
export function LoadProblem({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  if (error instanceof ApiError && error.isOffline) {
    return <Message icon="offline" title="You are offline" message="This needs a connection. Pull down to try again once you have signal." actionLabel="Try again" onAction={onRetry} />;
  }
  if (error instanceof ApiError && error.status === 404) {
    return <Message icon="info" title="Not found" message="This record does not exist on this project, or it has been archived." />;
  }
  return (
    <Message
      icon="error"
      title="This did not load"
      message={error instanceof Error ? error.message : "Something went wrong."}
      actionLabel="Try again"
      onAction={onRetry}
    />
  );
}

/** Links to a record inside the tab the person is already in, so Back returns there. */
export function useRecordLinks() {
  const router = useRouter();
  const segments = useSegments() as string[];
  const tab = segments[1] ?? "today";
  return useCallback(
    (kind: "grievance" | "stakeholder" | "engagement", id: number) => {
      router.push(`/${tab}/${kind}/${id}` as Href);
    },
    [router, tab],
  );
}

const styles = StyleSheet.create({
  cardRow: { flexDirection: "row", alignItems: "center", gap: space.m, paddingHorizontal: space.l, paddingVertical: 13, marginHorizontal: space.l },
  first: { borderTopLeftRadius: radius.card, borderTopRightRadius: radius.card },
  last: { borderBottomLeftRadius: radius.card, borderBottomRightRadius: radius.card },
  separator: { position: "absolute", left: space.l, right: 0, bottom: 0, height: StyleSheet.hairlineWidth },
  segments: { flexDirection: "row", borderRadius: 9, padding: 2, marginHorizontal: space.l, marginTop: space.s, marginBottom: space.l },
  segment: { flex: 1, alignItems: "center", paddingVertical: 7, borderRadius: 7 },
  segmentSelected: { shadowColor: "#000", shadowOpacity: 0.12, shadowRadius: 3, shadowOffset: { width: 0, height: 1 }, elevation: 1 },
});

/** The native search field in the header, tinted for the current theme. */
export function searchBarOptions(theme: Palette, placeholder: string, onSearch: (text: string) => void) {
  return {
    placeholder,
    onChangeText: (event: { nativeEvent: { text: string } }) => onSearch(event.nativeEvent.text),
    onCancelButtonPress: () => onSearch(""),
    hideWhenScrolling: false,
    tintColor: theme.accent,
    textColor: theme.text,
  };
}
