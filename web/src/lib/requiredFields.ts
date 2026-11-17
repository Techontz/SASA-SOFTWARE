import { useMemo } from "react";
import type { FieldErrors, FieldValues, Resolver } from "react-hook-form";
import { useBootstrap } from "@/lib/api/hooks";
import defaults from "./requiredFields.defaults.json";

/**
 * The fields each staff form must have filled in before it saves.
 *
 * The list comes from the project's configuration with the device bootstrap,
 * so a form asks for exactly what the API will insist on. Until the bootstrap
 * has arrived — or on a phone that has never been online — the defaults
 * shipped with the app apply; a backend test keeps that file identical to
 * config/sasa.php.
 */
export type RequiredForm = keyof typeof defaults;

export const REQUIRED_MESSAGE = "This is needed before the record can be saved.";

export function isBlank(value: unknown): boolean {
  if (value === undefined || value === null) return true;
  if (typeof value === "string") return value.trim() === "";
  if (Array.isArray(value)) return value.length === 0;
  return false;
}

interface Options<T extends FieldValues> {
  /** API field name → the form's own field name, where they differ. */
  fieldNames?: Partial<Record<string, keyof T & string>>;
  /** True when a field does not apply to these values — identity on an anonymous case. */
  skip?: (field: string, values: T) => boolean;
  /** A better sentence than the shared one, per API field. */
  messages?: Partial<Record<string, string>>;
}

export function useRequiredFields<T extends FieldValues>(form: RequiredForm, options: Options<T> = {}) {
  const { data } = useBootstrap();
  const list: string[] = data?.data.required_fields?.[form] ?? defaults[form];
  const { fieldNames, skip, messages } = options;

  const required = useMemo(() => new Set(list), [list]);

  /** Spread onto a <Field>: a required marker, or the word "optional". */
  const need = (field: string) => (required.has(field) ? { required: true } : { optional: true });

  /**
   * Wraps the form's own resolver. Every missing field is reported in the
   * same pass as the form's other problems, so nobody fixes one batch of
   * errors only to be shown the next.
   */
  const withRequired = useMemo(
    () =>
      (base: Resolver<T>): Resolver<T> =>
      async (values, context, resolverOptions) => {
        const result = await base(values, context, resolverOptions);
        const missing: Record<string, { type: string; message: string }> = {};

        for (const field of required) {
          const name = fieldNames?.[field] ?? field;
          // Checked whether or not the key is present: a draft saved before
          // a field became mandatory must not slip past it when restored.
          if (skip?.(field, values)) continue;
          if (isBlank(values[name])) {
            missing[name] = { type: "required", message: messages?.[field] ?? REQUIRED_MESSAGE };
          }
        }

        if (Object.keys(missing).length === 0) return result;

        return { values: {}, errors: { ...missing, ...result.errors } as FieldErrors<T> };
      },
    [required, fieldNames, skip, messages],
  );

  return { required, need, withRequired };
}
