import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { Stack } from "expo-router";
import * as SplashScreen from "expo-splash-screen";
import { StatusBar } from "expo-status-bar";
import * as SystemUI from "expo-system-ui";
import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api";
import { AppearanceProvider } from "@/lib/appearance";
import { SessionProvider, useSession } from "@/lib/session";
import { useTheme } from "@/lib/theme";

void SplashScreen.preventAutoHideAsync();
SplashScreen.setOptions({ fade: true, duration: 250 });

function makeQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        // A rejected request is not worth repeating; a dropped one is, twice.
        retry: (count, error) => error instanceof ApiError && error.status === 0 && count < 2,
      },
    },
  });
}

function RootStack() {
  const { restoring, user } = useSession();
  const theme = useTheme();

  useEffect(() => {
    void SystemUI.setBackgroundColorAsync(theme.canvas);
  }, [theme.canvas]);

  useEffect(() => {
    if (!restoring) void SplashScreen.hideAsync();
  }, [restoring]);

  if (restoring) return null;

  const sheet = {
    presentation: "modal" as const,
    headerShown: true,
    headerStyle: { backgroundColor: theme.canvas },
    headerTitleStyle: { color: theme.text },
    headerShadowVisible: false,
    contentStyle: { backgroundColor: theme.canvas },
  };

  return (
    <>
      <StatusBar style={theme.scheme === "dark" ? "light" : "dark"} />
      <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: theme.canvas } }}>
        <Stack.Protected guard={Boolean(user)}>
          <Stack.Screen name="index" />
          <Stack.Screen name="(tabs)" options={{ animation: "fade" }} />
          <Stack.Screen name="new-grievance" options={{ ...sheet, title: "Log a grievance" }} />
          <Stack.Screen name="new-engagement" options={{ ...sheet, title: "Log an engagement" }} />
          <Stack.Screen name="new-stakeholder" options={{ ...sheet, title: "Add a stakeholder" }} />
        </Stack.Protected>
        <Stack.Protected guard={!user}>
          <Stack.Screen name="sign-in" options={{ animation: "fade" }} />
        </Stack.Protected>
      </Stack>
    </>
  );
}

export default function RootLayout() {
  const [queryClient] = useState(makeQueryClient);
  return (
    <QueryClientProvider client={queryClient}>
      <AppearanceProvider>
        <SessionProvider>
          <RootStack />
        </SessionProvider>
      </AppearanceProvider>
    </QueryClientProvider>
  );
}
