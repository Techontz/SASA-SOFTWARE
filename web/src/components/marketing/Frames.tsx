import Image from "next/image";
import type { ReactNode } from "react";
import { cn } from "@/lib/utils";

/*
 * Real screens of SASA, captured from the running application with its
 * demonstration data, framed as a browser window or a phone.
 */

export function BrowserFrame({
  src,
  alt,
  width = 2000,
  height = 1250,
  url = "sasacompliance.com",
  tone = "dark",
  priority = false,
  sizes = "(min-width: 1280px) 1100px, 100vw",
  className,
  children,
}: {
  src?: string;
  alt?: string;
  width?: number;
  height?: number;
  url?: string;
  tone?: "dark" | "light";
  priority?: boolean;
  sizes?: string;
  className?: string;
  /** Replaces the single image, for screens that cross-fade. */
  children?: ReactNode;
}) {
  const dark = tone === "dark";

  return (
    <div
      className={cn(
        "overflow-hidden rounded-[1.125rem] p-1.5 shadow-[var(--shadow-showcase)] ring-1",
        dark ? "bg-chrome ring-chrome-line" : "bg-surface ring-hairline",
        className,
      )}
    >
      <div className="flex items-center gap-3 px-3 pb-2.5 pt-1.5">
        <span className="flex gap-1.5" aria-hidden>
          {[0, 1, 2].map((dot) => (
            <span
              key={dot}
              className={cn(
                "h-2.5 w-2.5 rounded-full",
                dark ? "bg-chrome-line" : "bg-ink-200",
              )}
            />
          ))}
        </span>
        <span
          className={cn(
            "mx-auto hidden max-w-[18rem] flex-1 truncate rounded-md px-3 py-1 text-center text-[0.6875rem] sm:block",
            dark
              ? "bg-chrome-deep text-chrome-muted"
              : "bg-surface-sunken text-ink-500",
          )}
        >
          {url}
        </span>
        <span className="hidden w-[2.625rem] sm:block" aria-hidden />
      </div>
      <div className="relative overflow-hidden rounded-[0.75rem]">
        {children ??
          (src ? (
            <Image
              src={src}
              alt={alt ?? ""}
              width={width}
              height={height}
              sizes={sizes}
              priority={priority}
              className="block h-auto w-full"
            />
          ) : null)}
      </div>
    </div>
  );
}

export function PhoneFrame({
  src,
  alt,
  className,
  priority = false,
}: {
  src: string;
  alt: string;
  className?: string;
  priority?: boolean;
}) {
  return (
    <div className={cn("sasa-phone", className)}>
      {/* Action button and volume on the left, the side button on the right. */}
      <span
        className="sasa-phone-button left-[-0.7cqw] top-[22cqw] h-[6.5cqw]"
        aria-hidden
      />
      <span
        className="sasa-phone-button left-[-0.7cqw] top-[34cqw] h-[12cqw]"
        aria-hidden
      />
      <span
        className="sasa-phone-button left-[-0.7cqw] top-[49cqw] h-[12cqw]"
        aria-hidden
      />
      <span
        className="sasa-phone-button right-[-0.7cqw] top-[38cqw] h-[19cqw]"
        aria-hidden
      />

      <div className="sasa-phone-body">
        <div className="sasa-phone-bezel">
          <div className="sasa-phone-screen">
            <div className="sasa-phone-status" aria-hidden>
              <span>9:41</span>
              <span className="flex items-center gap-[1.4cqw]">
                <svg viewBox="0 0 18 12" fill="currentColor">
                  <rect x="0" y="8" width="3" height="4" rx="0.8" />
                  <rect x="5" y="5.5" width="3" height="6.5" rx="0.8" />
                  <rect x="10" y="3" width="3" height="9" rx="0.8" />
                  <rect x="15" y="0" width="3" height="12" rx="0.8" />
                </svg>
                <svg viewBox="0 0 16 12" fill="currentColor">
                  <path d="M8 2.4c2.3 0 4.4.9 6 2.4l1.3-1.4A10.6 10.6 0 0 0 8 .4 10.6 10.6 0 0 0 .7 3.4L2 4.8a8.7 8.7 0 0 1 6-2.4Z" />
                  <path d="M8 6c1.3 0 2.5.5 3.4 1.3l1.3-1.4A6.8 6.8 0 0 0 8 4a6.8 6.8 0 0 0-4.7 1.9l1.3 1.4C5.5 6.5 6.7 6 8 6Z" />
                  <path d="M8 9.6c.5 0 1 .2 1.3.5L8 11.6 6.7 10.1c.3-.3.8-.5 1.3-.5Z" />
                </svg>
                <svg viewBox="0 0 27 12" fill="none">
                  <rect
                    x="0.5"
                    y="0.5"
                    width="23"
                    height="11"
                    rx="3.2"
                    stroke="currentColor"
                    strokeOpacity="0.4"
                  />
                  <rect
                    x="2"
                    y="2"
                    width="20"
                    height="8"
                    rx="2"
                    fill="currentColor"
                  />
                  <path
                    d="M25 4v4c.8-.3 1.4-1.1 1.4-2s-.6-1.7-1.4-2Z"
                    fill="currentColor"
                    fillOpacity="0.45"
                  />
                </svg>
              </span>
            </div>
            <span className="sasa-phone-island" aria-hidden />
            <Image
              src={src}
              alt={alt}
              width={780}
              height={1688}
              sizes="(min-width: 1024px) 300px, 60vw"
              priority={priority}
              className="block h-auto w-full"
            />
            <span className="sasa-phone-home" aria-hidden />
            <span className="sasa-phone-glare" aria-hidden />
          </div>
        </div>
      </div>
    </div>
  );
}
