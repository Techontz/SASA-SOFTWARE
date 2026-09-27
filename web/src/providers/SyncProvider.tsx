"use client";

import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { syncEngine, type SyncState } from "@/lib/offline/syncEngine";

interface SyncValue extends SyncState {
  flush: () => Promise<void>;
  retryFailed: () => Promise<void>;
}

const SyncContext = createContext<SyncValue | null>(null);

export function SyncProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<SyncState>(() => syncEngine().getState());

  useEffect(() => syncEngine().subscribe(setState), []);

  const value = useMemo<SyncValue>(
    () => ({
      ...state,
      flush: () => syncEngine().flush(),
      retryFailed: () => syncEngine().retryFailed(),
    }),
    [state],
  );

  return <SyncContext.Provider value={value}>{children}</SyncContext.Provider>;
}

export function useSync(): SyncValue {
  const context = useContext(SyncContext);

  if (!context) {
    throw new Error("useSync must be used inside a SyncProvider.");
  }

  return context;
}
