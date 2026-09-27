import { type ReactNode } from "react";
import { cn } from "@/lib/utils";

export function Card({
  children,
  className,
  as: Component = "section",
}: {
  children: ReactNode;
  className?: string;
  as?: "section" | "div" | "article";
}) {
  return <Component className={cn("sasa-card", className)}>{children}</Component>;
}

export function CardHeader({
  title,
  description,
  action,
  className,
  eyebrow,
  icon,
}: {
  title: ReactNode;
  description?: ReactNode;
  action?: ReactNode;
  className?: string;
  eyebrow?: string;
  /**
   * A quiet mark that helps the eye find a card in a grid of them. It is
   * decorative — the title still says what the card is — so it is hidden from
   * screen readers rather than read out twice.
   */
  icon?: ReactNode;
}) {
  return (
    <div
      className={cn(
        "flex flex-col gap-3 border-b border-hairline px-5 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-6 sm:py-5",
        className,
      )}
    >
      <div className="flex min-w-0 items-start gap-3">
        {icon ? (
          <span
            className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700"
            aria-hidden
          >
            {icon}
          </span>
        ) : null}
        <div className="min-w-0">
          {eyebrow ? <p className="sasa-eyebrow mb-1">{eyebrow}</p> : null}
          <h2 className="text-base font-semibold text-ink-900 sm:text-[1.0625rem]">{title}</h2>
          {description ? <p className="mt-1 text-sm text-ink-600">{description}</p> : null}
        </div>
      </div>
      {action ? <div className="flex shrink-0 items-center gap-2">{action}</div> : null}
    </div>
  );
}

export function CardBody({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn("px-5 py-5 sm:px-6", className)}>{children}</div>;
}

export function CardFooter({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <div className={cn("flex flex-wrap items-center gap-3 border-t border-hairline bg-surface-sunken px-5 py-4 sm:px-6", className)}>
      {children}
    </div>
  );
}
