import {
  Activity,
  BarChart3,
  Bell,
  Building2,
  CalendarClock,
  ClipboardCheck,
  FileText,
  FolderKanban,
  Handshake,
  LayoutDashboard,
  MessageSquareWarning,
  Mic,
  RefreshCcw,
  ScrollText,
  Settings,
  ShieldCheck,
  Users,
  type LucideIcon,
} from "lucide-react";

export interface NavItem {
  href: string;
  label: string;
  icon: LucideIcon;
  /** Any one of these grants the item. Empty means everyone signed in. */
  permissions?: string[];
  description?: string;
}

export interface NavGroup {
  label: string | null;
  items: NavItem[];
}

/**
 * Menu items exist because a user goes there, not because a table exists.
 * Eight primary destinations; administration is a second, quieter group.
 */
export const PRIMARY_NAVIGATION: NavGroup[] = [
  {
    label: null,
    items: [
      { href: "/", label: "Dashboard", icon: LayoutDashboard, description: "What needs attention today" },
      { href: "/stakeholders", label: "Stakeholders", icon: Users, permissions: ["stakeholder.view"], description: "The register" },
      { href: "/engagements", label: "Engagements", icon: CalendarClock, permissions: ["engagement.view"], description: "Planned and logged" },
      { href: "/concerns", label: "Concerns", icon: MessageSquareWarning, permissions: ["concern.view"], description: "Raised in engagement" },
      { href: "/grievances", label: "Grievances", icon: ClipboardCheck, permissions: ["grievance.view"], description: "Cases and their SLA" },
      { href: "/commitments", label: "Commitments", icon: Handshake, permissions: ["commitment.view"], description: "What was promised" },
      { href: "/reports", label: "Reports", icon: FileText, permissions: ["report.generate"], description: "Generated from live data" },
    ],
  },
  {
    label: "Administration",
    items: [
      { href: "/analytics", label: "Analytics", icon: BarChart3, permissions: ["dashboard.view"] },
      { href: "/sync", label: "Sync & offline", icon: RefreshCcw },
      { href: "/ai", label: "AI & voice", icon: Mic, permissions: ["ai.review", "grievance.view"] },
      { href: "/people", label: "People", icon: Building2, permissions: ["user.view"] },
      { href: "/audit", label: "Audit trail", icon: ScrollText, permissions: ["audit.view"] },
      { href: "/configuration", label: "Configuration", icon: Settings, permissions: ["configuration.view"] },
    ],
  },
];

/** The five destinations a field officer needs within thumb reach. */
export const MOBILE_NAVIGATION: NavItem[] = [
  { href: "/", label: "Today", icon: LayoutDashboard },
  { href: "/stakeholders", label: "Register", icon: Users, permissions: ["stakeholder.view"] },
  { href: "/engagements", label: "Engage", icon: CalendarClock, permissions: ["engagement.view"] },
  { href: "/grievances", label: "Cases", icon: ClipboardCheck, permissions: ["grievance.view"] },
];

export const SECONDARY_LINKS: NavItem[] = [
  { href: "/notifications", label: "Notifications", icon: Bell },
  { href: "/activity", label: "Recent activity", icon: Activity, permissions: ["audit.view"] },
  { href: "/projects", label: "Projects", icon: FolderKanban },
  { href: "/account", label: "My account", icon: ShieldCheck },
];

/** Quick actions, so the six things people do daily are one tap away. */
export interface QuickAction {
  href: string;
  label: string;
  description: string;
  icon: LucideIcon;
  permissions?: string[];
  tone?: "brand" | "accent";
}

export const QUICK_ACTIONS: QuickAction[] = [
  {
    href: "/grievances/new",
    label: "Log a grievance",
    description: "Record a concern raised through any channel",
    icon: ClipboardCheck,
    permissions: ["grievance.create"],
    tone: "accent",
  },
  {
    href: "/engagements/log",
    label: "Log an engagement",
    description: "Record a meeting, its attendance and what was said",
    icon: CalendarClock,
    permissions: ["engagement.log"],
    tone: "brand",
  },
  {
    href: "/stakeholders/new",
    label: "Add a stakeholder",
    description: "Add a person, household, group or authority to the register",
    icon: Users,
    permissions: ["stakeholder.create"],
    tone: "brand",
  },
  {
    href: "/commitments/new",
    label: "Add a commitment",
    description: "Record a promise, its owner and its due date",
    icon: Handshake,
    permissions: ["commitment.manage"],
    tone: "brand",
  },
];

export function isActivePath(pathname: string, href: string): boolean {
  if (href === "/") return pathname === "/";
  return pathname === href || pathname.startsWith(`${href}/`);
}
