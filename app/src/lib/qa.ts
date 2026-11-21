import { useLocalSearchParams } from "expo-router";
import { useEffect, useRef, type ComponentRef } from "react";
import type { ScrollView } from "react-native";

/**
 * Simulator QA only. A script cannot tap the simulator, so in development a
 * screen may read a `qa` deep-link parameter and fill or submit itself:
 *
 *   xcrun simctl openurl booted "exp://127.0.0.1:8081/--/new-grievance?qa=save"
 *
 * `__DEV__` is false in every release build, so the bundler strips all of
 * this out of the app people install.
 */
export type QaMode = "fill" | "save" | "empty" | null;

export function useQa(): { mode: QaMode; scroll: number; email?: string; password?: string } {
  const params = useLocalSearchParams<{ qa?: string; qa_scroll?: string; qa_email?: string; qa_password?: string }>();
  if (!__DEV__) return { mode: null, scroll: 0 };
  const mode = params.qa === "fill" || params.qa === "save" || params.qa === "empty" ? params.qa : null;
  return { mode, scroll: Number(params.qa_scroll ?? 0) || 0, email: params.qa_email, password: params.qa_password };
}

/** Development only: scroll a screen to `qa_scroll` once its content is there. */
export function useQaScroll(ready: boolean) {
  const ref = useRef<ComponentRef<typeof ScrollView>>(null);
  const { scroll } = useQa();
  useEffect(() => {
    if (!__DEV__ || !ready || !scroll) return;
    const timer = setTimeout(() => ref.current?.scrollTo({ y: scroll, animated: false }), 400);
    return () => clearTimeout(timer);
  }, [ready, scroll]);
  return ref;
}
