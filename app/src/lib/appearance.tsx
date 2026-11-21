import * as SecureStore from "expo-secure-store";
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { Appearance } from "react-native";

export type AppearancePreference = "system" | "light" | "dark";

const KEY = "sasa.appearance";

interface AppearanceValue {
  preference: AppearancePreference;
  setPreference: (value: AppearancePreference) => void;
}

const AppearanceContext = createContext<AppearanceValue | null>(null);

/**
 * Follows the phone's light or dark setting unless the person chooses one in
 * Account. The override goes through Appearance itself, so native headers,
 * the tab bar and the date pickers flip with the rest of the app.
 */
function applyToNative(value: AppearancePreference) {
  Appearance.setColorScheme(value === "system" ? "unspecified" : value);
}

export function AppearanceProvider({ children }: { children: ReactNode }) {
  const [preference, setState] = useState<AppearancePreference>("system");

  useEffect(() => {
    void SecureStore.getItemAsync(KEY).then((stored) => {
      if (stored === "light" || stored === "dark") {
        applyToNative(stored);
        setState(stored);
      }
    });
  }, []);

  const setPreference = useCallback((value: AppearancePreference) => {
    applyToNative(value);
    setState(value);
    void SecureStore.setItemAsync(KEY, value);
  }, []);

  const value = useMemo(() => ({ preference, setPreference }), [preference, setPreference]);
  return <AppearanceContext.Provider value={value}>{children}</AppearanceContext.Provider>;
}

export function useAppearance(): AppearanceValue {
  const value = useContext(AppearanceContext);
  if (!value) throw new Error("useAppearance must be used inside AppearanceProvider.");
  return value;
}
