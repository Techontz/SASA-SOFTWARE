import * as Network from "expo-network";
import * as Crypto from "expo-crypto";
import * as SecureStore from "expo-secure-store";
import { Platform } from "react-native";

/**
 * The one place the backend lives. Inlined at build time from
 * EXPO_PUBLIC_API_URL; the default is the local QA API, which the iOS
 * simulator reaches as localhost.
 */
export const API_BASE_URL = (process.env.EXPO_PUBLIC_API_URL ?? "http://localhost:8031/api/v1").replace(/\/$/, "");

const TOKEN_KEY = "sasa.token";
const DEVICE_KEY = "sasa.device";

/** A request that did not succeed, in terms a person can act on. */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code: string,
    public readonly fieldErrors: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** The phone itself has no connection. */
  get isOffline(): boolean {
    return this.code === "offline";
  }

  /** The phone has a connection but the server did not answer. */
  get isUnreachable(): boolean {
    return this.status === 0 && this.code !== "offline";
  }

  get isValidation(): boolean {
    return this.status === 422;
  }
}

/*
 * The token and the chosen project are held in memory for every request and
 * persisted in the keychain, so a restart restores the session without the
 * token ever touching plain storage.
 */
let token: string | null = null;
let projectId: number | null = null;
let deviceId: string | null = null;
let unauthenticatedHandler: (() => void) | null = null;

export const credentials = {
  async load(): Promise<string | null> {
    token = await SecureStore.getItemAsync(TOKEN_KEY);
    deviceId = await SecureStore.getItemAsync(DEVICE_KEY);
    if (!deviceId) {
      deviceId = `${Platform.OS}-${Crypto.randomUUID()}`;
      await SecureStore.setItemAsync(DEVICE_KEY, deviceId);
    }
    return token;
  },
  async setToken(value: string | null) {
    token = value;
    if (value) await SecureStore.setItemAsync(TOKEN_KEY, value);
    else await SecureStore.deleteItemAsync(TOKEN_KEY);
  },
  setProject(value: number | null) {
    projectId = value;
  },
  deviceName(): string {
    return `SASA ${Platform.OS === "ios" ? "iPhone" : "Android"} app`;
  },
};

export function onUnauthenticated(handler: () => void) {
  unauthenticatedHandler = handler;
}

type QueryValue = string | number | boolean | null | undefined;
export type Query = Record<string, QueryValue>;

export interface RequestOptions {
  method?: "GET" | "POST" | "PATCH" | "DELETE";
  body?: unknown;
  query?: Query;
  /** Skip the project header: /auth calls are not project-scoped. */
  withoutProject?: boolean;
  /** Keep a 401 local, so a wrong password does not end the session. */
  allowUnauthenticated?: boolean;
  signal?: AbortSignal;
}

function buildUrl(path: string, query?: Query): string {
  const params = Object.entries(query ?? {})
    .filter(([, value]) => value !== undefined && value !== null && value !== "")
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join("&");
  return `${API_BASE_URL}${path}${params ? `?${params}` : ""}`;
}

interface ErrorPayload {
  message?: string;
  error?: string;
  errors?: Record<string, string[]>;
}

export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = "GET", body, query, withoutProject, allowUnauthenticated, signal } = options;

  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Sasa-Device-Id": deviceId ?? "mobile",
  };
  if (token) headers.Authorization = `Bearer ${token}`;
  if (projectId && !withoutProject) headers["X-Sasa-Project"] = String(projectId);
  if (body !== undefined) headers["Content-Type"] = "application/json";

  let response: Response;
  try {
    response = await fetch(buildUrl(path, query), {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal,
    });
  } catch (error) {
    if (signal?.aborted) throw error;
    // fetch fails without detail. Whether the phone believes it has a
    // connection is the honest way to tell "no signal" from "server down".
    const state = await Network.getNetworkStateAsync().catch(() => null);
    const offline = state ? state.isConnected === false || state.isInternetReachable === false : false;
    throw new ApiError(
      offline
        ? "You are offline. Nothing was sent to SASA."
        : "SASA did not answer. Your phone has a connection, so the problem is at the server end.",
      0,
      offline ? "offline" : "unreachable",
    );
  }

  if (response.status === 204) return undefined as T;

  const isJson = (response.headers.get("content-type") ?? "").includes("application/json");
  const payload: unknown = isJson ? await response.json().catch(() => null) : null;

  if (!response.ok) {
    const error = (payload ?? {}) as ErrorPayload;
    if (response.status === 401 && !allowUnauthenticated) unauthenticatedHandler?.();
    throw new ApiError(
      error.message ?? "Something went wrong. Please try again.",
      response.status,
      error.error ?? "request_failed",
      error.errors ?? {},
    );
  }

  return payload as T;
}

export function newUuid(): string {
  return Crypto.randomUUID();
}
