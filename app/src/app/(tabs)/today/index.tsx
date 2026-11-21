import * as Haptics from "expo-haptics";
import { Stack, useRouter, type Href } from "expo-router";
import { Pressable, ScrollView, StyleSheet, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { Icon, type IconName } from "@/components/Icon";
import { LoadProblem, usePullToRefresh } from "@/components/ListParts";
import { Row, Section, Skeleton, T } from "@/components/ui";
import { greeting, longDate } from "@/lib/format";
import { useLanding } from "@/lib/queries";
import { firstName, useSession } from "@/lib/session";
import { radius, space, useTheme, type Tone } from "@/lib/theme";
import type { CriticalAction, Kpi } from "@/lib/types";
import { Avatar } from "@/screens/StakeholderList";

interface Attention {
  title: string;
  icon: IconName;
  tone: Tone;
  href?: Href;
  permission?: string;
}

/** What each item on the landing list is called here, and where it leads. */
const ATTENTION: Record<string, Attention> = {
  sla_breach: { title: "Past the resolution deadline", icon: "clock", tone: "danger", href: "/today/cases?filter=overdue", permission: "grievance.view" },
  critical_case: { title: "Open Level 5 cases", icon: "warning", tone: "danger", href: "/today/cases?filter=critical", permission: "grievance.view" },
  unassigned: { title: "Cases with no owner", icon: "personMissing", tone: "warning", href: "/today/cases?filter=unassigned", permission: "grievance.view" },
  unacknowledged: { title: "Waiting for acknowledgement", icon: "hourglass", tone: "warning", href: "/today/cases?filter=unacknowledged", permission: "grievance.view" },
  overdue_commitment: { title: "Overdue commitments", icon: "handshake", tone: "warning", href: "/today/commitments", permission: "commitment.view" },
  missed_engagement: { title: "Planned engagements missed", icon: "calendar", tone: "info" },
  register_review: { title: "Register entries to review", icon: "review", tone: "info", href: "/today/register?filter=review", permission: "stakeholder.view" },
};

interface QuickAction {
  title: string;
  icon: IconName;
  tone: Tone;
  href: Href;
  permission: string;
}

const QUICK: QuickAction[] = [
  { title: "Log a grievance", icon: "grievance", tone: "warning", href: "/new-grievance", permission: "grievance.create" },
  { title: "Log an engagement", icon: "engagement", tone: "accent", href: "/new-engagement", permission: "engagement.log" },
  { title: "Add a stakeholder", icon: "personAdd", tone: "info", href: "/new-stakeholder", permission: "stakeholder.create" },
];

function QuickCard({ action }: { action: QuickAction }) {
  const theme = useTheme();
  const router = useRouter();
  return (
    <Pressable
      onPress={() => {
        void Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light);
        router.push(action.href);
      }}
      accessibilityRole="button"
      accessibilityLabel={action.title}
      style={({ pressed }) => [styles.quick, { backgroundColor: pressed ? theme.pressed : theme.card }]}
    >
      <View style={[styles.quickIcon, { backgroundColor: theme.tone[action.tone].bg }]}>
        <Icon name={action.icon} size={20} color={theme.tone[action.tone].fg} weight="semibold" />
      </View>
      <T variant="subhead" weight="600" numberOfLines={2}>
        {action.title}
      </T>
    </Pressable>
  );
}

function Figures({ kpis }: { kpis: Kpi[] }) {
  const theme = useTheme();
  const { can } = useSession();
  const pick = (key: string) => kpis.find((kpi) => kpi.key === key);
  const figures = [
    can("grievance.view") ? { kpi: pick("grievances_open"), label: "Open cases" } : null,
    can("engagement.view") ? { kpi: pick("engagements_completed"), label: "Engagements" } : null,
    can("stakeholder.view") ? { kpi: pick("total_stakeholders"), label: "Stakeholders" } : null,
  ].filter((item): item is { kpi: Kpi; label: string } => Boolean(item?.kpi));
  if (figures.length === 0) return null;
  return (
    <Section title="Last 90 days" footer="Engagements held in the period. Open cases and stakeholders are counted as of now.">
      <View style={{ flexDirection: "row", paddingVertical: space.l }}>
        {figures.map((figure, index) => (
          <View
            key={figure.kpi.key}
            style={[
              { flex: 1, paddingHorizontal: space.l },
              index > 0 ? { borderLeftWidth: StyleSheet.hairlineWidth, borderLeftColor: theme.separator } : null,
            ]}
          >
            <T variant="title1" weight="600">
              {figure.kpi.value ?? "–"}
            </T>
            <T variant="footnote" tone="secondary">
              {figure.label}
            </T>
          </View>
        ))}
      </View>
    </Section>
  );
}

function AttentionRow({ action }: { action: CriticalAction }) {
  const router = useRouter();
  const { can } = useSession();
  const known = ATTENTION[action.kind];
  const href = known?.href && (!known.permission || can(known.permission)) ? known.href : undefined;
  return (
    <Row
      icon={known?.icon ?? "warning"}
      iconTone={known?.tone ?? (action.severity === "danger" ? "danger" : "warning")}
      title={known?.title ?? action.label}
      value={action.count}
      onPress={href ? () => router.push(href) : undefined}
      accessibilityLabel={action.label}
    />
  );
}

export default function Today() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const router = useRouter();
  const { user, project, can, projects } = useSession();
  const landing = useLanding(can("dashboard.view"));
  const refresh = usePullToRefresh(landing.refetch);
  const quick = QUICK.filter((action) => can(action.permission));
  const actions = landing.data?.critical_actions ?? [];

  return (
    <>
      <Stack.Screen options={{ headerShown: false, title: "Today" }} />
      <ScrollView
        refreshControl={refresh}
        contentContainerStyle={{ paddingTop: insets.top + space.s, paddingBottom: 48 }}
        style={{ backgroundColor: theme.canvas }}
      >
        <View style={styles.header}>
          <View style={{ flex: 1 }}>
            <T variant="subhead" tone="secondary" weight="600">
              {longDate()}
            </T>
            <T variant="largeTitle" style={{ marginTop: 2 }} accessibilityRole="header">
              {greeting()}, {firstName(user?.name)}
            </T>
          </View>
          <Pressable onPress={() => router.navigate("/account")} accessibilityRole="button" accessibilityLabel="Account" hitSlop={8}>
            <Avatar name={user?.name ?? ""} size={40} />
          </Pressable>
        </View>
        <Pressable
          disabled={projects.length < 2}
          onPress={() => router.navigate("/account")}
          style={styles.project}
          accessibilityRole={projects.length > 1 ? "button" : "text"}
          accessibilityLabel={`Project: ${project?.name ?? ""}`}
        >
          <Icon name="project" size={14} color={theme.accent} weight="semibold" />
          <T variant="subhead" tone="secondary" numberOfLines={1} style={{ flexShrink: 1 }}>
            {project?.name}
          </T>
        </Pressable>

        {quick.length > 0 ? <View style={styles.quickRow}>{quick.map((action) => <QuickCard key={action.title} action={action} />)}</View> : null}

        {can("dashboard.view") ? (
          <>
            {landing.isPending ? (
              <Section title="Needs attention">
                {[0, 1, 2].map((index) => (
                  <View key={index} style={{ flexDirection: "row", alignItems: "center", gap: space.m, padding: space.l }}>
                    <Skeleton width={30} height={30} style={{ borderRadius: 8 }} />
                    <Skeleton width={`${60 - index * 8}%`} height={14} />
                  </View>
                ))}
              </Section>
            ) : landing.isError ? (
              <LoadProblem error={landing.error} onRetry={() => void landing.refetch()} />
            ) : (
              <Section title="Needs attention" inset={58}>
                {actions.length === 0 ? (
                  <Row icon="success" iconTone="success" title="Nothing needs you right now" subtitle="New items will appear here as they come up." />
                ) : (
                  actions.map((action) => <AttentionRow key={action.kind} action={action} />)
                )}
              </Section>
            )}
            {landing.data ? <Figures kpis={landing.data.kpis} /> : null}
          </>
        ) : null}
      </ScrollView>
    </>
  );
}

const styles = StyleSheet.create({
  header: { flexDirection: "row", alignItems: "flex-end", gap: space.m, paddingHorizontal: space.xl },
  project: { flexDirection: "row", alignItems: "center", gap: 6, paddingHorizontal: space.xl, marginTop: space.s, alignSelf: "flex-start" },
  quickRow: { flexDirection: "row", gap: space.m, paddingHorizontal: space.l, marginTop: space.xxl },
  quick: { flex: 1, borderRadius: radius.card, padding: space.m, paddingVertical: space.l, gap: space.m, minHeight: 112, borderCurve: "continuous" },
  quickIcon: { width: 36, height: 36, borderRadius: 18, alignItems: "center", justifyContent: "center" },
});
