"use client";

import { useQueryClient } from "@tanstack/react-query";
import { Bookmark, BookmarkPlus, Check, Trash2 } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Field, Input, Select } from "@/components/ui/Form";
import { Drawer } from "@/components/ui/Drawer";
import { apiRequest } from "@/lib/api/client";
import { useSavedViews } from "@/lib/api/hooks";
import { cn } from "@/lib/utils";
import { useToast } from "@/providers/ToastProvider";
import { useSession } from "@/providers/SessionProvider";

/**
 * Saved views are what stop the reporting engine being used for daily
 * operational questions: "my open Level 4–5" is a filter, not a report.
 */
export function SavedViewBar({
  entity,
  filters,
  onApply,
}: {
  entity: string;
  filters: Record<string, string>;
  onApply: (filters: Record<string, string | number | boolean>) => void;
}) {
  const { data } = useSavedViews(entity);
  const queryClient = useQueryClient();
  const toast = useToast();
  const { user } = useSession();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [visibility, setVisibility] = useState<"private" | "project">("private");
  const [saving, setSaving] = useState(false);

  const views = data?.data ?? [];
  const activeFilterCount = Object.values(filters).filter(Boolean).length;

  const save = async () => {
    if (!name.trim()) return;
    setSaving(true);

    try {
      await apiRequest("/saved-views", {
        method: "POST",
        body: { entity, name: name.trim(), filters, visibility, is_pinned: true },
      });
      await queryClient.invalidateQueries({ queryKey: ["saved-views"] });
      toast.success("View saved", "You will find it above the list next time.");
      setOpen(false);
      setName("");
    } catch {
      toast.error("We could not save that view", "Please try again.");
    } finally {
      setSaving(false);
    }
  };

  const remove = async (id: number) => {
    try {
      await apiRequest(`/saved-views/${id}`, { method: "DELETE" });
      await queryClient.invalidateQueries({ queryKey: ["saved-views"] });
    } catch {
      toast.error("We could not remove that view");
    }
  };

  if (views.length === 0 && activeFilterCount === 0) return null;

  return (
    <>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        {views.map((view) => (
          <span key={view.id} className="group inline-flex items-center">
            <button
              type="button"
              onClick={() => onApply(view.filters)}
              className="inline-flex items-center gap-1.5 rounded-l-full border border-r-0 border-ink-200 bg-surface py-1.5 pl-3 pr-2.5 text-sm font-medium text-ink-700 transition hover:border-brand-300 hover:text-brand-800"
            >
              <Bookmark className="h-3.5 w-3.5 text-brand-600" aria-hidden />
              {view.name}
              {view.visibility !== "private" ? (
                <span className="rounded bg-brand-50 px-1 text-[0.625rem] text-brand-700">shared</span>
              ) : null}
            </button>
            {view.user?.id === user?.id ? (
              <button
                type="button"
                onClick={() => void remove(view.id)}
                aria-label={`Remove the view ${view.name}`}
                className="rounded-r-full border border-ink-200 bg-surface py-1.5 pl-1.5 pr-2.5 text-ink-400 transition hover:border-danger-300 hover:text-danger-600"
              >
                <Trash2 className="h-3.5 w-3.5" aria-hidden />
              </button>
            ) : (
              <span className="rounded-r-full border border-ink-200 bg-surface py-1.5 pr-2" />
            )}
          </span>
        ))}

        {activeFilterCount > 0 ? (
          <Button size="sm" variant="quiet" icon={<BookmarkPlus className="h-4 w-4" />} onClick={() => setOpen(true)}>
            Save this view
          </Button>
        ) : null}
      </div>

      <Drawer
        open={open}
        onClose={() => setOpen(false)}
        title="Save this view"
        description="Give the filter combination a name so you can come back to it in one click."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button variant="primary" loading={saving} onClick={() => void save()} icon={<Check className="h-4 w-4" />}>
              Save view
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="Name" required htmlFor="view-name" hint="For example: My open Level 4–5.">
            <Input
              id="view-name"
              value={name}
              onChange={(event) => setName(event.target.value)}
              placeholder="Overdue commitments — Northern district"
              autoFocus
            />
          </Field>

          <Field label="Who can see it" htmlFor="view-visibility">
            <Select
              id="view-visibility"
              value={visibility}
              onChange={(event) => setVisibility(event.target.value as "private" | "project")}
            >
              <option value="private">Only me</option>
              <option value="project">Everyone on this project</option>
            </Select>
          </Field>

          <div className="rounded-lg bg-surface-sunken p-4">
            <p className="sasa-eyebrow mb-2">Filters in this view</p>
            <ul className="space-y-1 text-sm text-ink-700">
              {Object.entries(filters)
                .filter(([, value]) => Boolean(value))
                .map(([key, value]) => (
                  <li key={key} className="flex justify-between gap-3">
                    <span className="text-ink-500">{key.replace(/_/g, " ")}</span>
                    <span className={cn("font-medium")}>{value}</span>
                  </li>
                ))}
            </ul>
          </div>
        </div>
      </Drawer>
    </>
  );
}
