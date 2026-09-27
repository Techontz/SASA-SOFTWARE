"use client";

import { useQueryClient } from "@tanstack/react-query";
import {
  CheckCircle2,
  MessageSquare,
  RotateCcw,
  Send,
  ShieldAlert,
  Stethoscope,
  UserPlus,
  XCircle,
} from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/Button";
import { Drawer } from "@/components/ui/Drawer";
import { Field, Select, Textarea } from "@/components/ui/Form";
import { apiRequest } from "@/lib/api/client";
import { useProjectMembers } from "@/lib/api/hooks";
import { useSession } from "@/providers/SessionProvider";
import { useToast } from "@/providers/ToastProvider";
import type { Grievance } from "@/types/api";

type ActionKey =
  | "assign"
  | "acknowledge"
  | "investigate"
  | "resolve"
  | "response"
  | "close"
  | "reopen"
  | "escalate"
  | "note"
  | "message"
  | "withdraw";

/**
 * The lifecycle actions, in the order the case moves through them. Only the
 * ones that make sense for the current status and this user's permissions are
 * shown — the officer never has to work out which of eleven buttons applies.
 */
export function GrievanceActions({ grievance }: { grievance: Grievance }) {
  const { can } = useSession();
  const toast = useToast();
  const queryClient = useQueryClient();
  const { data: members } = useProjectMembers();

  const [action, setAction] = useState<ActionKey | null>(null);
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState<Record<string, string>>({});

  const set = (key: string, value: string) => setForm((current) => ({ ...current, [key]: value }));

  const run = async (path: string, body: Record<string, unknown>, successTitle: string, successBody?: string) => {
    setBusy(true);
    try {
      await apiRequest(`/grievances/${grievance.id}${path}`, { method: "POST", body });
      await queryClient.invalidateQueries({ queryKey: ["grievance", grievance.id] });
      await queryClient.invalidateQueries({ queryKey: ["grievances"] });
      await queryClient.invalidateQueries({ queryKey: ["dashboard"] });
      toast.success(successTitle, successBody);
      setAction(null);
      setForm({});
    } catch (error) {
      toast.error("That did not go through", error instanceof Error ? error.message : undefined);
    } finally {
      setBusy(false);
    }
  };

  const isOpen = !["closed", "rejected", "withdrawn"].includes(grievance.status);
  const isResolved = grievance.status === "awaiting_confirmation" || Boolean(grievance.resolved_at);

  const buttons: Array<{ key: ActionKey; label: string; icon: typeof UserPlus; variant?: "primary" | "accent" | "secondary" | "ghost"; show: boolean }> = [
    {
      key: "assign",
      label: grievance.assigned_to ? "Reassign" : "Assign an owner",
      icon: UserPlus,
      variant: grievance.assigned_to ? "secondary" : "accent",
      show: isOpen && can("grievance.assign"),
    },
    {
      key: "acknowledge",
      label: "Acknowledge to the complainant",
      icon: Send,
      variant: "accent",
      show: isOpen && !grievance.acknowledged_at && grievance.acknowledgement_possible && can("grievance.acknowledge"),
    },
    {
      key: "investigate",
      label: grievance.investigation_started_at ? "Update the investigation" : "Start investigating",
      icon: Stethoscope,
      variant: "secondary",
      show: isOpen && !isResolved && can("grievance.investigate"),
    },
    {
      key: "resolve",
      label: "Record the resolution",
      icon: CheckCircle2,
      variant: "primary",
      show: isOpen && !isResolved && can("grievance.resolve"),
    },
    {
      key: "response",
      label: "Record the complainant's answer",
      icon: MessageSquare,
      variant: "primary",
      show: isResolved && !grievance.complainant_response && can("grievance.resolve"),
    },
    {
      key: "close",
      label: "Close the case",
      icon: CheckCircle2,
      variant: "primary",
      show: isResolved && can("grievance.close"),
    },
    {
      key: "reopen",
      label: "Reopen",
      icon: RotateCcw,
      variant: "secondary",
      show: (!isOpen || grievance.status === "awaiting_confirmation") && can("grievance.reopen"),
    },
    { key: "escalate", label: "Escalate", icon: ShieldAlert, variant: "secondary", show: isOpen && can("grievance.escalate") },
    { key: "note", label: "Add a note", icon: MessageSquare, variant: "ghost", show: can("grievance.investigate") },
    { key: "message", label: "Message the complainant", icon: Send, variant: "ghost", show: isOpen && can("grievance.acknowledge") && !grievance.is_anonymous },
    { key: "withdraw", label: "Mark withdrawn", icon: XCircle, variant: "ghost", show: isOpen && can("grievance.close") },
  ];

  return (
    <>
      <div className="flex flex-wrap gap-2">
        {buttons
          .filter((button) => button.show)
          .map((button) => {
            const Icon = button.icon;

            return (
              <Button
                key={button.key}
                variant={button.variant ?? "secondary"}
                icon={<Icon className="h-4 w-4" />}
                onClick={() => {
                  setForm({});
                  setAction(button.key);
                }}
              >
                {button.label}
              </Button>
            );
          })}
      </div>

      {/* ------------------------------- assign ------------------------- */}
      <Drawer
        open={action === "assign"}
        onClose={() => setAction(null)}
        title="Assign this case"
        description="The owner is the person accountable for investigating and resolving it. The previous owner stays on the record."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              onClick={() =>
                void run(
                  "/assign",
                  { assigned_to_id: form.assigned_to_id ? Number(form.assigned_to_id) : null, reason: form.reason },
                  "Case assigned",
                  "They have been notified and the acknowledgement deadline is on their dashboard.",
                )
              }
            >
              Assign
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="Owner" required htmlFor="assignee">
            <Select id="assignee" value={form.assigned_to_id ?? ""} onChange={(event) => set("assigned_to_id", event.target.value)}>
              <option value="">Unassigned</option>
              {(members?.data ?? []).map((member) => (
                <option key={member.user.id} value={member.user.id}>
                  {member.user.name} — {member.role.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Why" optional htmlFor="assign-reason" hint="Helpful when a case moves between teams.">
            <Textarea id="assign-reason" rows={3} value={form.reason ?? ""} onChange={(event) => set("reason", event.target.value)} />
          </Field>
          {grievance.is_restricted ? (
            <p className="rounded-lg bg-danger-50 p-3 text-sm text-danger-800">
              This case is restricted. Only members of its handling group can be assigned to it.
            </p>
          ) : null}
        </div>
      </Drawer>

      {/* ---------------------------- acknowledge ------------------------ */}
      <Drawer
        open={action === "acknowledge"}
        onClose={() => setAction(null)}
        title="Acknowledge to the complainant"
        description="Tell them the case is open and give them the case number. This stops the acknowledgement clock and is logged against the case."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              onClick={() =>
                void run(
                  "/acknowledge",
                  { method: form.method ?? "phone", message: form.message },
                  "Acknowledgement recorded",
                  "The acknowledgement clock has stopped and the message is on the case.",
                )
              }
            >
              Record it
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="How you told them" required htmlFor="ack-method">
            <Select id="ack-method" value={form.method ?? "phone"} onChange={(event) => set("method", event.target.value)}>
              <option value="phone">Phone call</option>
              <option value="sms">SMS</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="in_person">In person</option>
              <option value="letter">Letter</option>
              <option value="through_leader">Through a local leader</option>
            </Select>
          </Field>
          <Field
            label="What you told them"
            optional
            htmlFor="ack-message"
            hint="Leave blank to use the project's standard wording, which includes the case number and the timeframe."
          >
            <Textarea
              id="ack-message"
              rows={5}
              value={form.message ?? ""}
              onChange={(event) => set("message", event.target.value)}
              placeholder={`Thank you for raising this with us. Your case reference is ${grievance.reference}. We aim to come back to you within the project's standard timeframe.`}
            />
          </Field>
        </div>
      </Drawer>

      {/* ---------------------------- investigation ---------------------- */}
      <Drawer
        open={action === "investigate"}
        onClose={() => setAction(null)}
        title="Investigation"
        description="What you found, and what is being done about it. This is human work — the AI never writes findings."
        width="lg"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              onClick={async () => {
                if (!grievance.investigation_started_at) {
                  await apiRequest(`/grievances/${grievance.id}/investigation/start`, { method: "POST", body: {} });
                }
                await run(
                  "/investigation",
                  {
                    investigation_summary: form.summary,
                    investigation_findings: form.findings,
                    corrective_action: form.corrective_action,
                    corrective_action_due: form.corrective_action_due || null,
                    completed: form.completed === "yes",
                  },
                  "Investigation updated",
                );
              }}
            >
              Save
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="What you did" optional htmlFor="inv-summary" hint="Site visits, people spoken to, records checked.">
            <Textarea
              id="inv-summary"
              rows={4}
              value={form.summary ?? grievance.investigation_summary ?? ""}
              onChange={(event) => set("summary", event.target.value)}
            />
          </Field>
          <Field label="What you found" optional htmlFor="inv-findings" hint="Whether the complaint is substantiated, and why.">
            <Textarea
              id="inv-findings"
              rows={4}
              value={form.findings ?? grievance.investigation_findings ?? ""}
              onChange={(event) => set("findings", event.target.value)}
            />
          </Field>
          <Field label="Corrective action" optional htmlFor="inv-action">
            <Textarea
              id="inv-action"
              rows={3}
              value={form.corrective_action ?? grievance.corrective_action ?? ""}
              onChange={(event) => set("corrective_action", event.target.value)}
            />
          </Field>
          <Field label="Action due by" optional htmlFor="inv-due">
            <input
              id="inv-due"
              type="date"
              className="sasa-field"
              value={form.corrective_action_due ?? grievance.corrective_action_due ?? ""}
              onChange={(event) => set("corrective_action_due", event.target.value)}
            />
          </Field>
          <Field label="Is the investigation finished?" htmlFor="inv-complete">
            <Select id="inv-complete" value={form.completed ?? "no"} onChange={(event) => set("completed", event.target.value)}>
              <option value="no">Still in progress</option>
              <option value="yes">Yes — findings are complete</option>
            </Select>
          </Field>
        </div>
      </Drawer>

      {/* ------------------------------- resolve -------------------------- */}
      <Drawer
        open={action === "resolve"}
        onClose={() => setAction(null)}
        title="Record the resolution"
        description="Describe what was actually done. The complainant is told next, and their answer decides whether the case can close."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              disabled={(form.resolution_summary ?? "").trim().length < 10}
              onClick={() =>
                void run(
                  "/resolve",
                  { resolution_summary: form.resolution_summary, corrective_action: form.corrective_action },
                  "Resolution recorded",
                  "The case is now awaiting the complainant's confirmation.",
                )
              }
            >
              Record resolution
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field
            label="How it was resolved"
            required
            htmlFor="resolution"
            hint="Written for the complainant and for an auditor. A few words is not enough."
          >
            <Textarea
              id="resolution"
              rows={6}
              value={form.resolution_summary ?? ""}
              onChange={(event) => set("resolution_summary", event.target.value)}
              placeholder="The corrective action was carried out and confirmed on site. The complainant was visited, shown what had changed, and asked whether they were satisfied."
            />
          </Field>
          <Field label="Corrective action taken" optional htmlFor="resolve-action">
            <Textarea
              id="resolve-action"
              rows={3}
              value={form.corrective_action ?? grievance.corrective_action ?? ""}
              onChange={(event) => set("corrective_action", event.target.value)}
            />
          </Field>
        </div>
      </Drawer>

      {/* ------------------------- complainant response -------------------- */}
      <Drawer
        open={action === "response"}
        onClose={() => setAction(null)}
        title="What did the complainant say?"
        description="Their answer is recorded as its own act. If they did not accept it, reopen the case rather than closing it — that is what keeps the statistics honest."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              onClick={() =>
                void run(
                  "/complainant-response",
                  { response: form.response ?? "accepted", note: form.note },
                  "Response recorded",
                )
              }
            >
              Record it
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="Their answer" required htmlFor="response">
            <Select id="response" value={form.response ?? "accepted"} onChange={(event) => set("response", event.target.value)}>
              <option value="accepted">Accepted — they are satisfied</option>
              <option value="rejected">Not accepted — they are not satisfied</option>
              <option value="no_response">No response after several attempts</option>
              <option value="not_contactable">Could not be contacted</option>
            </Select>
          </Field>
          <Field label="Note" optional htmlFor="response-note">
            <Textarea id="response-note" rows={3} value={form.note ?? ""} onChange={(event) => set("note", event.target.value)} />
          </Field>
          {form.response === "rejected" ? (
            <p className="rounded-lg bg-warning-50 p-3 text-sm text-warning-800">
              Record this, then use <strong>Reopen</strong>. The case keeps its number and starts a second
              resolution cycle, so the record shows what really happened.
            </p>
          ) : null}
        </div>
      </Drawer>

      {/* -------------------------------- close ---------------------------- */}
      <Drawer
        open={action === "close"}
        onClose={() => setAction(null)}
        title="Close this case"
        description="Close it once the complainant has been told the outcome and the corrective action is verified."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button variant="primary" loading={busy} onClick={() => void run("/close", { notes: form.notes }, "Case closed")}>
              Close the case
            </Button>
          </>
        }
      >
        <Field label="Closing note" optional htmlFor="close-notes">
          <Textarea id="close-notes" rows={4} value={form.notes ?? ""} onChange={(event) => set("notes", event.target.value)} />
        </Field>
      </Drawer>

      {/* -------------------------------- reopen --------------------------- */}
      <Drawer
        open={action === "reopen"}
        onClose={() => setAction(null)}
        title="Reopen this case"
        description="The case keeps its number. A second resolution cycle starts, with a fresh clock, and both cycles stay on the record."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              disabled={(form.reason ?? "").trim().length < 5}
              onClick={() => void run("/reopen", { reason: form.reason }, `${grievance.reference} reopened`, "Cycle 2 has started.")}
            >
              Reopen
            </Button>
          </>
        }
      >
        <Field label="Why is it being reopened?" required htmlFor="reopen-reason" hint="The complainant's own words are best.">
          <Textarea
            id="reopen-reason"
            rows={4}
            value={form.reason ?? ""}
            onChange={(event) => set("reason", event.target.value)}
            placeholder="The complainant says the road was watered for three days and then stopped. The dust is back."
          />
        </Field>
      </Drawer>

      {/* ------------------------------- escalate --------------------------- */}
      <Drawer
        open={action === "escalate"}
        onClose={() => setAction(null)}
        title="Escalate this case"
        description="Raise it to the next level. Management is notified and the escalation is on the record with your reason."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="danger"
              loading={busy}
              disabled={!form.reason}
              onClick={() =>
                void run(
                  "/escalate",
                  { reason: form.reason, escalated_to_id: form.escalated_to_id ? Number(form.escalated_to_id) : null },
                  "Case escalated",
                )
              }
            >
              Escalate
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="Why" required htmlFor="escalate-reason">
            <Textarea id="escalate-reason" rows={4} value={form.reason ?? ""} onChange={(event) => set("reason", event.target.value)} />
          </Field>
          <Field label="To whom" optional htmlFor="escalate-to">
            <Select id="escalate-to" value={form.escalated_to_id ?? ""} onChange={(event) => set("escalated_to_id", event.target.value)}>
              <option value="">Whoever the escalation rules name</option>
              {(members?.data ?? []).map((member) => (
                <option key={member.user.id} value={member.user.id}>
                  {member.user.name} — {member.role.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>
      </Drawer>

      {/* --------------------------------- note ----------------------------- */}
      <Drawer
        open={action === "note"}
        onClose={() => setAction(null)}
        title="Add to the case history"
        description="Site visits, calls, decisions — anything the next person needs to understand what happened."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              disabled={!form.body}
              onClick={() => void run("/follow-ups", { type: form.type ?? "note", body: form.body }, "Added to the case history")}
            >
              Add
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="What kind of entry" htmlFor="note-type">
            <Select id="note-type" value={form.type ?? "note"} onChange={(event) => set("type", event.target.value)}>
              <option value="note">Note</option>
              <option value="investigation_step">Investigation step</option>
              <option value="contact">Contact with the complainant</option>
              <option value="site_visit">Site visit</option>
              <option value="decision">Decision</option>
            </Select>
          </Field>
          <Field label="What happened" required htmlFor="note-body">
            <Textarea id="note-body" rows={6} value={form.body ?? ""} onChange={(event) => set("body", event.target.value)} />
          </Field>
        </div>
      </Drawer>

      {/* -------------------------------- message ---------------------------- */}
      <Drawer
        open={action === "message"}
        onClose={() => setAction(null)}
        title="Message the complainant"
        description="Everything sent to a complainant is recorded against the case, so what was said and when is provable."
        width="md"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button
              variant="primary"
              loading={busy}
              disabled={!form.body}
              onClick={() =>
                void run(
                  "/communications",
                  { channel: form.channel ?? "phone", body: form.body, subject: form.subject },
                  "Message logged against the case",
                )
              }
            >
              Log the message
            </Button>
          </>
        }
      >
        <div className="space-y-5">
          <Field label="How" required htmlFor="msg-channel">
            <Select id="msg-channel" value={form.channel ?? "phone"} onChange={(event) => set("channel", event.target.value)}>
              <option value="phone">Phone call</option>
              <option value="sms">SMS</option>
              <option value="whatsapp">WhatsApp</option>
              <option value="in_person">In person</option>
              <option value="letter">Letter</option>
              <option value="email">Email</option>
            </Select>
          </Field>
          <Field label="What was said" required htmlFor="msg-body">
            <Textarea id="msg-body" rows={5} value={form.body ?? ""} onChange={(event) => set("body", event.target.value)} />
          </Field>
        </div>
      </Drawer>

      {/* -------------------------------- withdraw ---------------------------- */}
      <Drawer
        open={action === "withdraw"}
        onClose={() => setAction(null)}
        title="Mark this case withdrawn"
        description="Use this when the complainant no longer wishes to pursue it. The case and its history are kept."
        width="sm"
        footer={
          <>
            <Button variant="secondary" onClick={() => setAction(null)}>Cancel</Button>
            <Button variant="danger" loading={busy} disabled={!form.reason} onClick={() => void run("/withdraw", { reason: form.reason }, "Case marked withdrawn")}>
              Mark withdrawn
            </Button>
          </>
        }
      >
        <Field label="Why" required htmlFor="withdraw-reason">
          <Textarea id="withdraw-reason" rows={4} value={form.reason ?? ""} onChange={(event) => set("reason", event.target.value)} />
        </Field>
      </Drawer>
    </>
  );
}
