"use client";

import { AlertTriangle, CheckCircle2, Info, WifiOff, X } from "lucide-react";
import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import { cn } from "@/lib/utils";

type ToastTone = "success" | "error" | "info" | "offline";

interface Toast {
  id: string;
  tone: ToastTone;
  title: string;
  description?: string;
}

interface ToastValue {
  toast: (toast: Omit<Toast, "id">) => void;
  success: (title: string, description?: string) => void;
  error: (title: string, description?: string) => void;
  info: (title: string, description?: string) => void;
  savedOnDevice: (what: string) => void;
}

const ToastContext = createContext<ToastValue | null>(null);

const TONE_STYLES: Record<ToastTone, { icon: typeof Info; className: string; iconClass: string }> = {
  success: { icon: CheckCircle2, className: "border-success-500/25 bg-success-50", iconClass: "text-success-600" },
  error: { icon: AlertTriangle, className: "border-danger-500/25 bg-danger-50", iconClass: "text-danger-600" },
  info: { icon: Info, className: "border-brand-500/25 bg-brand-50", iconClass: "text-brand-600" },
  offline: { icon: WifiOff, className: "border-warning-500/25 bg-warning-50", iconClass: "text-warning-600" },
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);

  const dismiss = useCallback((id: string) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
  }, []);

  const toast = useCallback(
    (input: Omit<Toast, "id">) => {
      const id = `${Date.now()}-${Math.random().toString(16).slice(2)}`;
      setToasts((current) => [...current.slice(-3), { ...input, id }]);
      setTimeout(() => dismiss(id), input.tone === "error" ? 8000 : 5000);
    },
    [dismiss],
  );

  const value = useMemo<ToastValue>(
    () => ({
      toast,
      success: (title, description) => toast({ tone: "success", title, description }),
      error: (title, description) => toast({ tone: "error", title, description }),
      info: (title, description) => toast({ tone: "info", title, description }),
      /* The honest message when a record has not reached the server yet. */
      savedOnDevice: (what) =>
        toast({
          tone: "offline",
          title: `${what} saved on this device`,
          description: "It will sync automatically when the connection returns. You can keep working.",
        }),
    }),
    [toast],
  );

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div
        aria-live="polite"
        aria-atomic="false"
        className="pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-center gap-2 p-4 sm:bottom-auto sm:right-0 sm:top-0 sm:items-end"
      >
        {toasts.map((item) => {
          const tone = TONE_STYLES[item.tone];
          const Icon = tone.icon;

          return (
            <div
              key={item.id}
              role="status"
              className={cn(
                "animate-fade-up pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-lg border p-4 shadow-[var(--shadow-raised)]",
                tone.className,
              )}
            >
              <Icon className={cn("mt-0.5 h-5 w-5 shrink-0", tone.iconClass)} aria-hidden />
              <div className="min-w-0 flex-1">
                <p className="font-semibold text-ink-900">{item.title}</p>
                {item.description ? (
                  <p className="mt-0.5 text-sm text-ink-600">{item.description}</p>
                ) : null}
              </div>
              <button
                type="button"
                onClick={() => dismiss(item.id)}
                className="-m-1 rounded p-1 text-ink-500 transition hover:bg-ink-200 hover:text-ink-900"
                aria-label="Dismiss"
              >
                <X className="h-4 w-4" aria-hidden />
              </button>
            </div>
          );
        })}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastValue {
  const context = useContext(ToastContext);

  if (!context) {
    throw new Error("useToast must be used inside a ToastProvider.");
  }

  return context;
}
