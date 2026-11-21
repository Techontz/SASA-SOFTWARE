import { NativeTabs } from "expo-router/native-tabs";
import type { SFSymbol } from "sf-symbols-typescript";
import { materialSymbol, sfSymbol, type IconName } from "@/components/Icon";
import { useSession } from "@/lib/session";
import { useTheme } from "@/lib/theme";

interface Tab {
  name: string;
  label: string;
  icon: IconName;
  selectedSf: SFSymbol;
  permission?: string;
}

const TABS: Tab[] = [
  { name: "today", label: "Today", icon: "today", selectedSf: "sun.max.fill" },
  { name: "grievances", label: "Grievances", icon: "grievance", selectedSf: "exclamationmark.bubble.fill", permission: "grievance.view" },
  { name: "stakeholders", label: "Stakeholders", icon: "stakeholder", selectedSf: "person.2.fill", permission: "stakeholder.view" },
  { name: "engagements", label: "Engagements", icon: "engagement", selectedSf: "bubble.left.and.bubble.right.fill", permission: "engagement.view" },
  { name: "account", label: "Account", icon: "account", selectedSf: "person.crop.circle.fill" },
];

/**
 * The native tab bar. A tab the person's role cannot use is not shown at
 * all, rather than leading to a "not allowed" screen.
 */
export default function TabsLayout() {
  const { can } = useSession();
  const theme = useTheme();

  return (
    <NativeTabs tintColor={theme.accent} iconColor={theme.tertiary} backgroundColor={theme.scheme === "dark" ? theme.card : undefined}>
      {TABS.map((tab) => (
        <NativeTabs.Trigger key={tab.name} name={tab.name} hidden={tab.permission ? !can(tab.permission) : false}>
          <NativeTabs.Trigger.Icon sf={{ default: sfSymbol(tab.icon), selected: tab.selectedSf }} md={materialSymbol(tab.icon)} />
          <NativeTabs.Trigger.Label>{tab.label}</NativeTabs.Trigger.Label>
        </NativeTabs.Trigger>
      ))}
    </NativeTabs>
  );
}
