import { useCallback, useMemo, useState } from "react";
import { ApiError } from "./api";
import { useBootstrap } from "./queries";
import defaults from "./requiredFields.defaults.json";

/**
 * The fields a form must have before it saves. The list comes from the
 * project's configuration with the bootstrap, so the phone asks for exactly
 * what the API will insist on. Until that arrives, the defaults shipped with
 * the web app (identical to config/sasa.php) apply.
 */
export type RequiredForm = keyof typeof defaults;

export const REQUIRED_MESSAGE = "This is needed before the record can be saved.";

export function useRequiredFields(form: RequiredForm): Set<string> {
  const { data } = useBootstrap();
  const list = data?.required_fields[form] ?? defaults[form];
  return useMemo(() => new Set(list), [list]);
}

export function isBlank(value: unknown): boolean {
  if (value === undefined || value === null) return true;
  if (typeof value === "string") return value.trim() === "";
  if (Array.isArray(value)) return value.length === 0;
  return false;
}

/** Form values plus the errors for each, with a setter that clears a field's error. */
export function useFormState<T extends object>(initial: T) {
  const [values, setValues] = useState<T>(initial);
  const [errors, setErrors] = useState<Partial<Record<keyof T | string, string>>>({});
  const [dirty, setDirty] = useState(false);

  const set = useCallback(<K extends keyof T>(key: K, value: T[K]) => {
    setValues((current) => ({ ...current, [key]: value }));
    setErrors((current) => (current[key] ? { ...current, [key]: undefined } : current));
    setDirty(true);
  }, []);

  return { values, set, errors, setErrors, dirty, setDirty };
}

/** The API's 422 errors, keyed by the form's own field names. */
export function fieldErrorsFrom(error: unknown, rename: Record<string, string> = {}): Record<string, string> {
  if (!(error instanceof ApiError) || !error.isValidation) return {};
  const result: Record<string, string> = {};
  for (const [field, messages] of Object.entries(error.fieldErrors)) {
    const key = rename[field] ?? field.split(".")[0] ?? field;
    if (!result[key] && messages[0]) result[key] = messages[0];
  }
  return result;
}

/** Turns "" into null and digit strings into numbers, for the request body. */
export function toNumber(value: string): number | null {
  return value.trim() === "" ? null : Number(value);
}

export function toText(value: string): string | null {
  return value.trim() === "" ? null : value.trim();
}
