import type { ApiError } from "@/types/api";

/**
 * The one place the backend lives.
 *
 * NEXT_PUBLIC_* is inlined at build time, so a production build made without
 * NEXT_PUBLIC_API_URL set would previously fall back to localhost and ship an
 * app that fails every request on a user's machine with no clue why. It now
 * resolves to an empty string in production, and the first request explains
 * the misconfiguration instead.
 */
export const API_BASE_URL =
  process.env.NEXT_PUBLIC_API_URL ??
  (process.env.NODE_ENV === "production" ? "" : "http://localhost:8010/api/v1");

const TOKEN_KEY = "sasa.token";
const PROJECT_KEY = "sasa.project";

/**
 * The problem the request hit, in terms a person can act on.
 * `offline` is the one the field officer sees most, and it is not a failure —
 * it is the cue that the work was kept on the device.
 */
export class ApiRequestError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code: string,
    public readonly validationErrors?: Record<string, string[]>,
    public readonly requestId?: string,
  ) {
    super(message);
    this.name = "ApiRequestError";
  }

  /**
   * The device genuinely has no connection.
   *
   * Deliberately narrower than "the request failed at the network level". A
   * browser cannot tell a CORS rejection from a dead network — both reject the
   * fetch with no detail, by design — so the only honest discriminator is
   * whether the device itself believes it is online. Telling someone with four
   * bars that they are offline sends them to look for signal instead of at the
   * thing that is actually broken.
   */
  get isOffline(): boolean {
    return this.status === 0 && this.code === "offline";
  }

  /** The device has a connection but the API did not answer. */
  get isUnreachable(): boolean {
    return this.status === 0 && this.code !== "offline";
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isNotFound(): boolean {
    return this.status === 404;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }

  /** Worth putting back on the queue rather than showing as a hard failure. */
  get isRetryable(): boolean {
    return this.status === 0 || this.status === 429 || this.status >= 500;
  }
}

export const tokenStore = {
  get(): string | null {
    if (typeof window === "undefined") return null;
    return window.localStorage.getItem(TOKEN_KEY);
  },
  set(token: string) {
    window.localStorage.setItem(TOKEN_KEY, token);
  },
  clear() {
    window.localStorage.removeItem(TOKEN_KEY);
  },
};

export const projectStore = {
  get(): number | null {
    if (typeof window === "undefined") return null;
    const value = window.localStorage.getItem(PROJECT_KEY);
    return value ? Number(value) : null;
  },
  set(projectId: number) {
    window.localStorage.setItem(PROJECT_KEY, String(projectId));
  },
  clear() {
    window.localStorage.removeItem(PROJECT_KEY);
  },
};

type Query = Record<string, string | number | boolean | null | undefined | Array<string | number>>;

export interface RequestOptions {
  method?: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
  body?: unknown;
  query?: Query;
  /** Skip the project header — used by /auth and /projects. */
  withoutProject?: boolean;
  signal?: AbortSignal;
  /** Multipart, for attachment uploads. */
  formData?: FormData;
  headers?: Record<string, string>;
  /**
   * Keep a 401 local to this call instead of tearing the session down.
   *
   * The live API answers a wrong password with 401 `invalid_credentials`,
   * which is indistinguishable by status alone from an expired token. Without
   * this, mistyping a password on the sign-in screen fires the global
   * unauthenticated handler, which clears state and navigates — throwing away
   * the very error message the person needs to read.
   */
  allowUnauthenticated?: boolean;
}

export function buildUrl(path: string, query?: Query): string {
  const url = new URL(
    path.startsWith("http") ? path : `${API_BASE_URL}${path.startsWith("/") ? path : `/${path}`}`,
  );

  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value === null || value === undefined || value === "") continue;
      if (Array.isArray(value)) {
        value.forEach((item) => url.searchParams.append(`${key}[]`, String(item)));
      } else {
        url.searchParams.set(key, String(value));
      }
    }
  }

  return url.toString();
}

let unauthenticatedHandler: (() => void) | null = null;

/** The app registers what to do when a token stops being accepted. */
export function onUnauthenticated(handler: () => void) {
  unauthenticatedHandler = handler;
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const {
    method = "GET",
    body,
    query,
    withoutProject,
    signal,
    formData,
    headers = {},
    allowUnauthenticated = false,
  } = options;

  if (!API_BASE_URL) {
    throw new ApiRequestError(
      "This build has no API address configured. NEXT_PUBLIC_API_URL must be set when the application is built.",
      0,
      "api_url_missing",
    );
  }

  const requestHeaders: Record<string, string> = {
    Accept: "application/json",
    "X-Sasa-Device-Id": deviceIdentifier(),
    ...headers,
  };

  const token = tokenStore.get();
  if (token) requestHeaders.Authorization = `Bearer ${token}`;

  const projectId = projectStore.get();
  if (projectId && !withoutProject) requestHeaders["X-Sasa-Project"] = String(projectId);

  if (!formData && body !== undefined) {
    requestHeaders["Content-Type"] = "application/json";
  }

  let response: Response;

  try {
    response = await fetch(buildUrl(path, query), {
      method,
      headers: requestHeaders,
      body: formData ?? (body !== undefined ? JSON.stringify(body) : undefined),
      signal,
      credentials: "omit",
    });
  } catch {
    /*
     * fetch rejects without detail for every network-level failure: no signal,
     * DNS, TLS, a blocked CORS preflight. navigator.onLine is the one thing
     * that separates "this device has no connection" from "the server did not
     * answer", and they need different words — and send the reader to
     * different places.
     *
     * Both keep status 0, so both stay retryable and the sync queue holds on
     * to the work either way.
     */
    const deviceIsOffline = typeof navigator !== "undefined" && !navigator.onLine;

    throw new ApiRequestError(
      deviceIsOffline
        ? "You appear to be offline. Your work is saved on this device and will sync when the connection returns."
        : "We could not reach the SASA server. Your device has a connection, so this is a problem at the server end rather than with you.",
      0,
      deviceIsOffline ? "offline" : "unreachable",
    );
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get("content-type") ?? "";
  const isJson = contentType.includes("application/json");
  const payload = isJson ? await response.json().catch(() => null) : null;

  if (!response.ok) {
    const error = (payload ?? {}) as ApiError;

    if (response.status === 401 && !allowUnauthenticated) {
      unauthenticatedHandler?.();
    }

    throw new ApiRequestError(
      error.message ?? "Something went wrong. Please try again.",
      response.status,
      error.error ?? "request_failed",
      error.errors,
      error.request_id,
    );
  }

  return payload as T;
}

function deviceIdentifier(): string {
  if (typeof window === "undefined") return "server";
  const key = "sasa.device-id";
  let value = window.localStorage.getItem(key);
  if (!value) {
    value = `web-${crypto.randomUUID()}`;
    window.localStorage.setItem(key, value);
  }
  return value;
}

/** Fetch a file with the auth header and hand the browser a blob to save. */
export async function downloadFile(path: string, filename: string, query?: Query): Promise<void> {
  const token = tokenStore.get();
  const projectId = projectStore.get();

  let response: Response;

  try {
    response = await fetch(buildUrl(path, query), {
      headers: {
        Accept: "*/*",
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(projectId ? { "X-Sasa-Project": String(projectId) } : {}),
      },
      credentials: "omit",
    });
  } catch {
    throw new ApiRequestError(
      "You appear to be offline. Exports need a connection, because they are built on the server.",
      0,
      "offline",
    );
  }

  if (response.status === 401) {
    unauthenticatedHandler?.();
  }

  if (!response.ok) {
    throw new ApiRequestError(
      response.status === 403
        ? "You do not have permission to export this."
        : "We could not prepare that download.",
      response.status,
      "download_failed",
    );
  }

  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
