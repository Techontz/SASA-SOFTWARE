import { Stack } from "expo-router";
import { useTheme } from "@/lib/theme";

/**
 * Every tab is a native stack with a large title that collapses on scroll,
 * on the app's own canvas colour rather than the system default.
 */
export function TabStack() {
  const theme = useTheme();
  return (
    <Stack
      screenOptions={{
        headerLargeTitleEnabled: true,
        headerLargeTitleShadowVisible: false,
        headerShadowVisible: true,
        headerStyle: { backgroundColor: theme.canvas },
        headerLargeStyle: { backgroundColor: theme.canvas },
        headerTitleStyle: { color: theme.text },
        headerLargeTitleStyle: { color: theme.text },
        headerTintColor: theme.accent,
        headerBackButtonDisplayMode: "minimal",
        contentStyle: { backgroundColor: theme.canvas },
      }}
    />
  );
}

