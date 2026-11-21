import * as Haptics from "expo-haptics";
import { useNetworkState } from "expo-network";
import { Stack, useRouter, type Href } from "expo-router";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { ActivityIndicator, Alert, KeyboardAvoidingView, Platform, Pressable, ScrollView, View } from "react-native";
import { ApiError } from "@/lib/api";
import { useQa, useQaScroll } from "@/lib/qa";
import { space, useTheme } from "@/lib/theme";
import { Icon } from "./Icon";
import { Button, Notice, T } from "./ui";

export interface Saved {
  title: string;
  message: string;
  href: Href;
  viewLabel: string;
}

interface CreateSheetProps {
  what: string;
  dirty: boolean;
  /** Development only: the form has filled itself for a QA run and is ready to save. */
  qaReady?: boolean;
  /** Returns the missing-field count, or the saved record. */
  submit: () => Promise<{ missing: number } | { saved: Saved }>;
  children: ReactNode;
}

/**
 * The frame every create flow shares: Cancel and Save in the sheet's header,
 * an honest account of where the work went, and nothing lost to a swipe.
 *
 * There is no on-phone queue here, so the sheet never says "saved" unless the
 * server said so. When the request cannot go, it says plainly that nothing
 * was saved and keeps everything that was typed.
 */
export function CreateSheet({ what, dirty, submit, children, qaReady }: CreateSheetProps) {
  const theme = useTheme();
  const router = useRouter();
  const network = useNetworkState();
  const scroll = useQaScroll(true);
  const qa = useQa();
  const [saving, setSaving] = useState(false);
  const [problem, setProblem] = useState<{ title: string; message?: string; tone: "danger" | "warning" } | null>(null);
  const [saved, setSaved] = useState<Saved | null>(null);

  const offline = network.isConnected === false || network.isInternetReachable === false;

  const cancel = () => {
    if (!dirty || saved) return router.back();
    Alert.alert(`Discard this ${what}?`, "What you have typed will be lost.", [
      { text: "Keep editing", style: "cancel" },
      { text: "Discard", style: "destructive", onPress: () => router.back() },
    ]);
  };

  const save = async () => {
    setSaving(true);
    setProblem(null);
    try {
      const result = await submit();
      if ("missing" in result) {
        void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning);
        setProblem({
          tone: "warning",
          title: result.missing === 1 ? "One thing is still needed" : `${result.missing} things are still needed`,
          message: "They are marked in red below.",
        });
        scroll.current?.scrollTo({ y: 0, animated: true });
        return;
      }
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
      setSaved(result.saved);
    } catch (error) {
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
      if (error instanceof ApiError && (error.isOffline || error.isUnreachable)) {
        const title = error.isOffline ? "Not saved. You are offline." : "Not saved. SASA did not answer.";
        const message = `Nothing was sent, and this ${what} is not stored on this phone. Everything you typed is still here: try again when you have a connection.`;
        setProblem({ tone: "danger", title, message });
        Alert.alert(title, message);
      } else if (error instanceof ApiError && error.isValidation) {
        setProblem({ tone: "warning", title: "Some answers need another look", message: error.message });
      } else {
        setProblem({ tone: "danger", title: "Not saved", message: error instanceof Error ? error.message : "Please try again." });
      }
      scroll.current?.scrollTo({ y: 0, animated: true });
    } finally {
      setSaving(false);
    }
  };

  // Development only: a scripted run saves once the form has filled itself.
  const qaSaved = useRef(false);
  useEffect(() => {
    if (!__DEV__ || qaSaved.current || !qaReady || (qa.mode !== "save" && qa.mode !== "empty")) return;
    qaSaved.current = true;
    const timer = setTimeout(() => void save(), 600);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qaReady, qa.mode]);

  if (saved) {
    return (
      <>
        <Stack.Screen options={{ headerLeft: () => null, headerRight: () => null, gestureEnabled: true }} />
        <View style={{ flex: 1, alignItems: "center", justifyContent: "center", padding: space.xxl, backgroundColor: theme.canvas }}>
          <Icon name="success" size={64} color={theme.tone.success.solid} weight="regular" />
          <T variant="title2" align="center" style={{ marginTop: space.xl }}>
            {saved.title}
          </T>
          <T tone="secondary" align="center" style={{ marginTop: space.s, maxWidth: 320 }}>
            {saved.message}
          </T>
          <Button
            title={saved.viewLabel}
            onPress={() => {
              router.back();
              setTimeout(() => router.push(saved.href), 350);
            }}
            style={{ marginTop: space.xxl, alignSelf: "stretch" }}
          />
          <Button title="Done" variant="plain" onPress={() => router.back()} style={{ marginTop: space.s, alignSelf: "stretch" }} />
        </View>
      </>
    );
  }

  return (
    <>
      <Stack.Screen
        options={{
          gestureEnabled: !dirty,
          headerLeft: () => (
            <Pressable onPress={cancel} hitSlop={10} accessibilityRole="button">
              <T tone="accent">Cancel</T>
            </Pressable>
          ),
          headerRight: () =>
            saving ? (
              <ActivityIndicator />
            ) : (
              <Pressable onPress={() => void save()} hitSlop={10} accessibilityRole="button" accessibilityLabel={`Save ${what}`}>
                <T tone="accent" weight="600">
                  Save
                </T>
              </Pressable>
            ),
        }}
      />
      <KeyboardAvoidingView style={{ flex: 1 }} behavior={Platform.OS === "ios" ? "padding" : undefined} keyboardVerticalOffset={56}>
        <ScrollView
          ref={scroll}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="interactive"
          contentContainerStyle={{ paddingBottom: 64 }}
          style={{ backgroundColor: theme.canvas }}
        >
          {offline && !problem ? (
            <Notice
              tone="warning"
              icon="offline"
              title="You are offline"
              message={`You can fill this in, but the ${what} can only be saved once you are back online.`}
            />
          ) : null}
          {problem ? <Notice tone={problem.tone} icon={problem.tone === "danger" ? "error" : "warning"} title={problem.title} message={problem.message} /> : null}
          {children}
          <View style={{ paddingHorizontal: space.l, marginTop: space.xxl }}>
            <Button title={`Save ${what}`} onPress={() => void save()} loading={saving} />
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </>
  );
}
