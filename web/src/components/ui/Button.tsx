"use client";

import { Loader2 } from "lucide-react";
import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from "react";
import { cn } from "@/lib/utils";

type Variant = "primary" | "secondary" | "ghost" | "danger" | "accent" | "quiet";
type Size = "sm" | "md" | "lg";

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
  iconRight?: ReactNode;
  fullWidth?: boolean;
}

/* One accent action per screen. Everything else is secondary or quiet. */
const VARIANTS: Record<Variant, string> = {
  primary:
    "bg-primary text-on-primary shadow-[var(--shadow-card)] hover:bg-primary-hover active:bg-primary-active disabled:bg-ink-200 disabled:text-ink-400 disabled:shadow-none",
  accent:
    "bg-accent-solid text-on-primary shadow-[var(--shadow-card)] hover:bg-accent-solid-hover active:bg-accent-solid-active disabled:bg-ink-200 disabled:text-ink-400 disabled:shadow-none",
  secondary:
    "border border-ink-300 bg-surface text-ink-800 hover:border-ink-400 hover:bg-ink-50 active:bg-ink-100 disabled:border-ink-200 disabled:text-ink-400",
  ghost:
    "text-ink-700 hover:bg-ink-100 active:bg-ink-200 disabled:text-ink-400",
  quiet:
    "text-brand-700 hover:bg-brand-50 hover:text-brand-800 active:bg-brand-100 disabled:text-ink-400",
  danger:
    "bg-danger-solid text-on-primary hover:bg-danger-solid-hover active:bg-danger-solid-active disabled:bg-ink-200 disabled:text-ink-400",
};

/* 44px minimum on the primary sizes — a field officer taps this with a thumb. */
const SIZES: Record<Size, string> = {
  sm: "h-9 gap-1.5 px-3 text-sm rounded-md",
  md: "h-11 gap-2 px-4 text-[0.9375rem] rounded-md",
  lg: "h-12 gap-2.5 px-5 text-base rounded-lg",
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  { variant = "secondary", size = "md", loading, icon, iconRight, fullWidth, className, children, disabled, ...props },
  ref,
) {
  return (
    <button
      ref={ref}
      disabled={disabled || loading}
      className={cn(
        "inline-flex select-none items-center justify-center font-medium transition-colors duration-[var(--duration-fast)]",
        "disabled:cursor-not-allowed",
        VARIANTS[variant],
        SIZES[size],
        fullWidth && "w-full",
        className,
      )}
      {...props}
    >
      {loading ? (
        <Loader2 className="h-4 w-4 animate-spin" aria-hidden />
      ) : icon ? (
        <span className="shrink-0" aria-hidden>{icon}</span>
      ) : null}
      {children}
      {iconRight && !loading ? <span className="shrink-0" aria-hidden>{iconRight}</span> : null}
    </button>
  );
});
