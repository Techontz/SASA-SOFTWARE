"use client";

import { useRouter } from "next/navigation";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { apiRequest, onUnauthenticated, projectStore, tokenStore } from "@/lib/api/client";
import { localStore } from "@/lib/offline/dexieStore";
import { syncEngine } from "@/lib/offline/syncEngine";
import type { CurrentUser, LoginResponse, ProjectMembershipSummary } from "@/types/api";

interface SessionValue {
  user: CurrentUser | null;
  project: ProjectMembershipSummary | null;
  projects: ProjectMembershipSummary[];
  permissions: string[];
  loading: boolean;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  switchProject: (projectId: number) => Promise<void>;
  refresh: () => Promise<void>;
  can: (permission: string) => boolean;
  canAny: (...permissions: string[]) => boolean;
}

const SessionContext = createContext<SessionValue | null>(null);

const USER_CACHE_KEY = "sasa.user";

/**
 * The cached profile is what lets the app open at all when the officer starts
 * it with no signal. It is a convenience copy, never the authority: the API
 * re-checks every permission on every request.
 */
function readCachedUser(): CurrentUser | null {
  if (typeof window === "undefined") return null;

  const raw = window.localStorage.getItem(USER_CACHE_KEY);
  if (!raw) return null;

  try {
    return JSON.parse(raw) as CurrentUser;
  } catch {
    return null;
  }
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const router = useRouter();
  /*
   * None of these are seeded from localStorage during the first render. The
   * server has no access to it, so a seeded value would not match the
   * server-rendered HTML and React would discard the tree as a hydration
   * mismatch. The session is restored in the mount effect instead, which is
   * why `loading` starts true.
   */
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [projectId, setProjectId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);

  const cacheUser = (value: CurrentUser | null) => {
    if (typeof window === "undefined") return;
    if (value) {
      window.localStorage.setItem(USER_CACHE_KEY, JSON.stringify(value));
    } else {
      window.localStorage.removeItem(USER_CACHE_KEY);
    }
  };

  const applyProject = useCallback(async (value: CurrentUser, preferred: number | null) => {
    const chosen =
      value.projects.find((project) => project.id === preferred) ??
      value.projects.find((project) => project.is_default) ??
      value.projects[0] ??
      null;

    if (chosen) {
      projectStore.set(chosen.id);
      // Start the engine before touching React state, so nothing in this
      // provider sets state synchronously inside the mount effect.
      await syncEngine().start(chosen.id);
      setProjectId(chosen.id);
      void syncEngine().cacheReferenceData();
    } else {
      projectStore.clear();
      await Promise.resolve();
      setProjectId(null);
    }
  }, []);

  const load = useCallback(async () => {
    const token = tokenStore.get();
    const cached = token ? readCachedUser() : null;

    /*
     * Yield before touching React state. This runs from the mount effect, and
     * a synchronous update there would cascade an extra render.
     */
    await Promise.resolve();

    if (!token) {
      setLoading(false);
      return;
    }

    // Paint the shell from the cached profile first, so a field officer with
    // no signal sees their work rather than a spinner.
    if (cached) {
      setUser(cached);
      await applyProject(cached, projectStore.get());
    }

    try {
      const response = await apiRequest<{ data: CurrentUser }>("/auth/me", { withoutProject: true });
      setUser(response.data);
      cacheUser(response.data);
      await applyProject(response.data, projectStore.get());
    } catch {
      // Offline with a cached profile: keep working. Offline with none: the
      // sign-in screen will explain that a connection is needed the first time.
      if (!cached) setUser(null);
    } finally {
      setLoading(false);
    }
  }, [applyProject]);

  useEffect(() => {
    onUnauthenticated(() => {
      tokenStore.clear();
      cacheUser(null);
      setUser(null);
      router.replace("/sign-in");
    });

    /*
     * Restoring the session is the "subscribe to an external system" case the
     * rule's own guidance allows: it reads the token store, then the API, and
     * every setState inside it happens after an await. The linter cannot see
     * across the await boundary, so the exception is documented here rather
     * than the code being contorted to hide it.
     */
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [load, router]);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const response = await apiRequest<{ data: LoginResponse }>("/auth/login", {
        method: "POST",
        withoutProject: true,
        body: { email, password, device_name: navigator.userAgent.slice(0, 100) },
      });

      tokenStore.set(response.data.token);
      setUser(response.data.user);
      cacheUser(response.data.user);
      await applyProject(response.data.user, null);
      setLoading(false);
    },
    [applyProject],
  );

  const signOut = useCallback(async () => {
    try {
      await apiRequest("/auth/logout", { method: "POST", withoutProject: true });
    } catch {
      // Signing out locally must work even with no connection.
    }

    syncEngine().stop();
    tokenStore.clear();
    projectStore.clear();
    cacheUser(null);
    // Cached project data is cleared on sign-out so a shared field device does
    // not leave one officer's records readable by the next.
    await localStore().clearEverything().catch(() => undefined);
    setUser(null);
    setProjectId(null);
    router.replace("/sign-in");
  }, [router]);

  const switchProject = useCallback(
    async (nextProjectId: number) => {
      if (!user) return;
      await applyProject(user, nextProjectId);
      router.refresh();
    },
    [applyProject, router, user],
  );

  const project = useMemo(
    () => user?.projects.find((candidate) => candidate.id === projectId) ?? null,
    [projectId, user],
  );

  const permissions = useMemo(() => project?.permissions ?? [], [project]);

  const can = useCallback(
    (permission: string) =>
      Boolean(user?.is_system_admin) || permissions.includes(permission),
    [permissions, user],
  );

  const canAny = useCallback(
    (...candidates: string[]) => candidates.some((permission) => can(permission)),
    [can],
  );

  const value = useMemo<SessionValue>(
    () => ({
      user,
      project,
      projects: user?.projects ?? [],
      permissions,
      loading,
      signIn,
      signOut,
      switchProject,
      refresh: load,
      can,
      canAny,
    }),
    [can, canAny, load, loading, permissions, project, signIn, signOut, switchProject, user],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const context = useContext(SessionContext);

  if (!context) {
    throw new Error("useSession must be used inside a SessionProvider.");
  }

  return context;
}
