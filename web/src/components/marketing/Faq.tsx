"use client";

import { Minus, Plus } from "lucide-react";
import { useState } from "react";
import { cn } from "@/lib/utils";

export function Faq({ items }: { items: Array<{ q: string; a: string }> }) {
  const [open, setOpen] = useState(0);

  return (
    <div className="divide-y divide-chrome-line border-y border-chrome-line">
      {items.map((item, index) => {
        const isOpen = open === index;
        const panelId = `faq-${index}`;

        return (
          <div key={item.q}>
            <h3>
              <button
                type="button"
                onClick={() => setOpen(isOpen ? -1 : index)}
                aria-expanded={isOpen}
                aria-controls={panelId}
                className="flex w-full items-center justify-between gap-6 py-6 text-left text-[1.0625rem] font-medium tracking-[-0.01em] text-white md:text-lg"
              >
                {item.q}
                <span
                  className={cn(
                    "grid h-9 w-9 shrink-0 place-items-center rounded-full transition-colors",
                    isOpen ? "bg-accent-solid text-on-primary" : "bg-chrome-raised/60 text-white ring-1 ring-chrome-line",
                  )}
                >
                  {isOpen ? <Minus className="h-4 w-4" aria-hidden /> : <Plus className="h-4 w-4" aria-hidden />}
                </span>
              </button>
            </h3>
            <div
              id={panelId}
              className={cn("grid transition-all duration-300", isOpen ? "grid-rows-[1fr] pb-6" : "grid-rows-[0fr]")}
            >
              <p className="overflow-hidden pr-4 text-[0.96875rem] leading-relaxed text-chrome-muted sm:pr-14">{item.a}</p>
            </div>
          </div>
        );
      })}
    </div>
  );
}
