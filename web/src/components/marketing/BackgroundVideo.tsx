"use client";

import { Pause, Play } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import { cn } from "@/lib/utils";

/**
 * A muted, looping film behind a section. It plays only while it is on
 * screen, never for someone who has asked their device for less motion, and it
 * can always be paused: moving content that runs longer than five seconds
 * needs a way to stop it.
 */
export function BackgroundVideo({
  src,
  srcSmall,
  poster,
  eager = false,
  className,
  controlClassName,
}: {
  src: string;
  /** A lighter cut for phones, so a field officer's data is not spent on film. */
  srcSmall?: string;
  poster: string;
  /** The hero starts loading at once; anything further down waits its turn. */
  eager?: boolean;
  className?: string;
  controlClassName?: string;
}) {
  const ref = useRef<HTMLVideoElement | null>(null);
  const [playing, setPlaying] = useState(false);
  const [paused, setPaused] = useState(false);
  const [reduced, setReduced] = useState(false);
  const [onScreen, setOnScreen] = useState(false);

  useEffect(() => {
    const query = window.matchMedia("(prefers-reduced-motion: reduce)");
    const update = () => setReduced(query.matches);
    update();
    query.addEventListener("change", update);
    return () => query.removeEventListener("change", update);
  }, []);

  useEffect(() => {
    const video = ref.current;
    if (!video) return;
    const observer = new IntersectionObserver(([entry]) => setOnScreen(Boolean(entry?.isIntersecting)), {
      threshold: 0.05,
    });
    observer.observe(video);
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    const video = ref.current;
    if (!video) return;
    // Set as a property: the attribute alone does not satisfy every browser's
    // autoplay rule.
    video.muted = true;
    if (onScreen && !paused && !reduced) {
      video.play().catch(() => setPlaying(false));
    } else {
      video.pause();
    }
  }, [onScreen, paused, reduced]);

  return (
    <>
      <div className={cn("pointer-events-none absolute inset-0 -z-20 overflow-hidden", className)} aria-hidden>
        <video
          ref={ref}
          className="lp-film h-full w-full object-cover"
          poster={poster}
          muted
          loop
          playsInline
          preload={eager ? "auto" : "none"}
          onPlay={() => setPlaying(true)}
          onPause={() => setPlaying(false)}
          tabIndex={-1}
        >
          {srcSmall ? <source src={srcSmall} type="video/mp4" media="(max-width: 767px)" /> : null}
          <source src={src} type="video/mp4" />
        </video>
      </div>

      {reduced ? null : (
        <button
          type="button"
          onClick={() => setPaused((value) => !value)}
          className={cn(
            "absolute z-20 grid h-10 w-10 place-items-center rounded-full bg-chrome-deep/50 text-white ring-1 ring-chrome-line backdrop-blur-md transition-colors hover:bg-chrome-deep/80 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-accent-300/50",
            controlClassName,
          )}
          aria-label={playing ? "Pause the background video" : "Play the background video"}
          aria-pressed={!playing}
        >
          {playing ? <Pause className="h-4 w-4" aria-hidden /> : <Play className="h-4 w-4" aria-hidden />}
        </button>
      )}
    </>
  );
}
