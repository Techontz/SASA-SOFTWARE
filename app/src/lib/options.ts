import type { Option } from "@/components/Form";
import { humanise } from "./format";
import { useBootstrap } from "./queries";

export const LEVELS: Option[] = [
  { value: "high", label: "High" },
  { value: "medium", label: "Medium" },
  { value: "low", label: "Low" },
];

export const CONTACT_METHODS: Option[] = [
  { value: "in_person", label: "In person" },
  { value: "phone", label: "Phone call" },
  { value: "sms", label: "SMS" },
  { value: "whatsapp", label: "WhatsApp" },
  { value: "email", label: "Email" },
  { value: "through_leader", label: "Through a local leader" },
];

export const COMPLAINANT_TYPES: Option[] = [
  { value: "community_member", label: "Community member" },
  { value: "worker", label: "Project worker" },
  { value: "contractor", label: "Contractor staff" },
  { value: "leader", label: "Local or traditional leader" },
  { value: "cso", label: "CSO or NGO" },
  { value: "government", label: "Government" },
  { value: "other", label: "Other" },
];

export const GENDERS: Option[] = [
  { value: "female", label: "Female" },
  { value: "male", label: "Male" },
  { value: "other", label: "Other" },
  { value: "prefer_not_to_say", label: "Prefer not to say" },
];

/** The project's places, with the parent path as a hint under each name. */
export function useLocationOptions(): Option[] {
  const { data } = useBootstrap();
  return (data?.locations ?? []).map((location) => ({
    value: String(location.id),
    label: location.name,
    subtitle: location.path.split(" / ").slice(0, -1).join(" / ") || humanise(location.level),
  }));
}

export function useLanguageOptions(): Option[] {
  const { data } = useBootstrap();
  return (data?.lists.languages ?? ["sw", "en"]).map((code) => ({ value: code, label: humanise(code) }));
}

export function useListOptions(list: "stakeholder_types" | "engagement_methods" | "project_phases"): Option[] {
  const { data } = useBootstrap();
  return (data?.lists[list] ?? []).map((value) => ({ value, label: humanise(value) }));
}

export function useMemberOptions(): Option[] {
  const { data } = useBootstrap();
  return (data?.members ?? []).map((member) => ({ value: String(member.id), label: member.name, subtitle: member.role }));
}
