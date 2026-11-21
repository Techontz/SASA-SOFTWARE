import { useQueryClient } from "@tanstack/react-query";
import * as SecureStore from "expo-secure-store";
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from "react";
import { api, credentials, onUnauthenticated } from "./api";
import type { CurrentUser, LoginResponse, ProjectMembership } from "./types";

const USER_KEY = "sasa.user";
const PROJECT_KEY = "sasa.project";

interface SessionValue {
  /** True until the stored session has been read on launch. */
  restoring: boolean;
  user: CurrentUser | null;
  project: ProjectMembership | null;
  projects: ProjectMembership[];
  can: (permission: string) => boolean;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  switchProject: (id: number) => Promise<void>;
}

const SessionContext = createContext<SessionValue | null>(null);

function choose(user: CurrentUser, preferred: number | null): ProjectMembership | null {
  return (
    user.projects.find((project) => project.id === preferred) ??
    user.projects.find((project) => project.is_default) ??
    user.projects[0] ??
    null
  );
}

async function readCachedUser(): Promise<CurrentUser | null> {
  const raw = await SecureStore.getItemAsync(USER_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as CurrentUser;
  } catch {
    return null;
  }
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [restoring, setRestoring] = useState(true);
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [projectId, setProjectId] = useState<number | null>(null);

  const apply = useCallback(async (next: CurrentUser, preferred: number | null) => {
    const chosen = choose(next, preferred);
    credentials.setProject(chosen?.id ?? null);
    if (chosen) await SecureStore.setItemAsync(PROJECT_KEY, String(chosen.id));
    // The profile is a convenience copy so the app opens with no signal. The
    // API re-checks every permission on every request.
    await SecureStore.setItemAsync(USER_KEY, JSON.stringify(next));
    setUser(next);
    setProjectId(chosen?.id ?? null);
  }, []);

  const clearLocal = useCallback(async () => {
    await credentials.setToken(null);
    credentials.setProject(null);
    await SecureStore.deleteItemAsync(USER_KEY);
    queryClient.clear();
    setUser(null);
    setProjectId(null);
  }, [queryClient]);

  useEffect(() => {
    onUnauthenticated(() => {
      void clearLocal();
    });

    let cancelled = false;
    void (async () => {
      const token = await credentials.load();
      if (!token) {
        if (!cancelled) setRestoring(false);
        return;
      }
      const storedProject = Number(await SecureStore.getItemAsync(PROJECT_KEY)) || null;
      const cached = await readCachedUser();
      if (cached && !cancelled) await apply(cached, storedProject);
      if (!cancelled) setRestoring(false);

      try {
        const fresh = await api<{ data: CurrentUser }>("/auth/me", { withoutProject: true });
        if (!cancelled) await apply(fresh.data, storedProject);
      } catch {
        // Offline with a cached profile: carry on. A rejected token has
        // already cleared the session through the 401 handler.
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [apply, clearLocal]);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const response = await api<{ data: LoginResponse }>("/auth/login", {
        method: "POST",
        withoutProject: true,
        allowUnauthenticated: true,
        body: { email, password, device_name: credentials.deviceName() },
      });
      await credentials.setToken(response.data.token);
      queryClient.clear();
      await apply(response.data.user, null);
    },
    [apply, queryClient],
  );

  const signOut = useCallback(async () => {
    try {
      await api("/auth/logout", { method: "POST", withoutProject: true, allowUnauthenticated: true });
    } catch {
      // Signing out on the phone must work with no connection.
    }
    await clearLocal();
  }, [clearLocal]);

  const switchProject = useCallback(
    async (id: number) => {
      if (!user) return;
      queryClient.clear();
      await apply(user, id);
    },
    [apply, queryClient, user],
  );

  const project = useMemo(() => user?.projects.find((candidate) => candidate.id === projectId) ?? null, [projectId, user]);

  const can = useCallback(
    (permission: string) => Boolean(user?.is_system_admin) || Boolean(project?.permissions.includes(permission)),
    [project, user],
  );

  const value = useMemo<SessionValue>(
    () => ({ restoring, user, project, projects: user?.projects ?? [], can, signIn, signOut, switchProject }),
    [can, project, restoring, signIn, signOut, switchProject, user],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (!value) throw new Error("useSession must be used inside SessionProvider.");
  return value;
}

export function firstName(name: string | undefined | null): string {
  return (name ?? "").trim().split(/\s+/)[0] ?? "";
}
