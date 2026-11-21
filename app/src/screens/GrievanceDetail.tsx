import { Stack, useLocalSearchParams } from "expo-router";
import { ScrollView, StyleSheet, View } from "react-native";
import { LoadProblem, usePullToRefresh, useRecordLinks } from "@/components/ListParts";
import { Badge, Row, Section, Skeleton, T } from "@/components/ui";
import { formatDate, formatDateTime, grievanceStatusTone, humanise, plain, severityTone, slaLabel } from "@/lib/format";
import { useQaScroll } from "@/lib/qa";
import { useGrievance } from "@/lib/queries";
import { radius, space, useTheme, type Tone } from "@/lib/theme";
import type { Grievance } from "@/lib/types";

interface Event {
  at: string;
  title: string;
  detail?: string | null;
  tone: Tone;
}

/** The case's story, newest first, from the records the API returns with it. */
function timeline(grievance: Grievance): Event[] {
  const events: Event[] = [];
  if (grievance.received_at) {
    events.push({ at: grievance.received_at, title: "Case received", detail: `Via ${grievance.channel_label.toLowerCase()}`, tone: "info" });
  }
  for (const assignment of grievance.assignments ?? []) {
    if (!assignment.assigned_at) continue;
    events.push({
      at: assignment.assigned_at,
      title: `Assigned to ${assignment.assigned_to ?? assignment.assigned_team ?? "a team"}`,
      detail: assignment.assigned_by ? `By ${assignment.assigned_by}` : null,
      tone: "accent",
    });
  }
  for (const message of grievance.communications ?? []) {
    if (!message.sent_at) continue;
    events.push({
      at: message.sent_at,
      title: `${humanise(message.template_key ?? "Message")} sent`,
      detail: [humanise(message.channel), message.sent_by ? `by ${message.sent_by}` : null].filter(Boolean).join(" "),
      tone: "accent",
    });
  }
  for (const escalation of grievance.escalations ?? []) {
    if (!escalation.escalated_at) continue;
    events.push({
      at: escalation.escalated_at,
      title: `Escalated to level ${escalation.to_level}`,
      detail: escalation.reason ?? escalation.escalated_to,
      tone: "danger",
    });
  }
  for (const followUp of grievance.follow_ups ?? []) {
    if (!followUp.created_at) continue;
    events.push({
      at: followUp.created_at,
      title: humanise(followUp.type ?? "Follow-up"),
      detail: [followUp.body, followUp.author ? `${followUp.author}` : null].filter(Boolean).join("\n"),
      tone: "neutral",
    });
  }
  for (const cycle of grievance.cycles ?? []) {
    if (cycle.cycle_number > 1 && cycle.opened_at) {
      events.push({ at: cycle.opened_at, title: "Case reopened", detail: cycle.reopen_reason, tone: "warning" });
    }
    if (cycle.resolved_at) {
      events.push({ at: cycle.resolved_at, title: "Resolution recorded", detail: cycle.resolution_summary, tone: "success" });
    }
    if (cycle.closed_at) {
      events.push({
        at: cycle.closed_at,
        title: "Case closed",
        detail: cycle.complainant_response ? `Complainant ${humanise(cycle.complainant_response).toLowerCase()} the resolution` : null,
        tone: "success",
      });
    }
  }
  return events.sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime());
}

export function Timeline({ events }: { events: Event[] }) {
  const theme = useTheme();
  return (
    <View style={[styles.card, { backgroundColor: theme.card }]}>
      {events.map((event, index) => (
        <View key={`${event.at}-${index}`} style={styles.event}>
          <View style={styles.rail}>
            <View style={[styles.dot, { backgroundColor: theme.tone[event.tone].solid }]} />
            {index < events.length - 1 ? <View style={[styles.line, { backgroundColor: theme.separator }]} /> : null}
          </View>
          <View style={{ flex: 1, paddingBottom: index < events.length - 1 ? space.xl : 0 }}>
            <View style={{ flexDirection: "row", gap: space.s }}>
              <T variant="subhead" weight="600" style={{ flex: 1 }}>
                {event.title}
              </T>
              <T variant="footnote" tone="tertiary">
                {formatDate(event.at)}
              </T>
            </View>
            {event.detail ? (
              <T variant="subhead" tone="secondary" style={{ marginTop: 2 }}>
                {event.detail}
              </T>
            ) : null}
          </View>
        </View>
      ))}
    </View>
  );
}

function DetailSkeleton() {
  return (
    <View style={{ padding: space.l, gap: space.m, marginTop: space.l }}>
      <Skeleton width={120} height={12} />
      <Skeleton width="90%" height={24} />
      <Skeleton width="60%" height={24} />
      <View style={{ flexDirection: "row", gap: 8, marginTop: 4 }}>
        <Skeleton width={70} height={20} />
        <Skeleton width={60} height={20} />
      </View>
      <Skeleton width="100%" height={200} style={{ marginTop: space.xl, borderRadius: radius.card }} />
    </View>
  );
}

export default function GrievanceDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const record = useGrievance(id);
  const refresh = usePullToRefresh(record.refetch);
  const openRecord = useRecordLinks();
  const grievance = record.data;
  const scrollRef = useQaScroll(Boolean(grievance));
  const sla = grievance ? slaLabel(grievance) : null;

  return (
    <>
      <Stack.Screen options={{ title: grievance?.reference ?? "", headerLargeTitleEnabled: false }} />
      <ScrollView ref={scrollRef} contentInsetAdjustmentBehavior="automatic" refreshControl={refresh} contentContainerStyle={{ paddingBottom: 48 }}>
        {record.isPending ? <DetailSkeleton /> : null}
        {record.isError ? <LoadProblem error={record.error} onRetry={() => void record.refetch()} /> : null}
        {grievance ? (
          <>
            <View style={styles.header}>
              <T variant="footnote" tone="secondary">
                Received {formatDateTime(grievance.received_at)}
              </T>
              <T variant="title2" style={{ marginTop: 4 }}>
                {grievance.title || grievance.description}
              </T>
              <View style={styles.badges}>
                <Badge label={grievance.status_label} tone={grievanceStatusTone(grievance.status)} />
                {grievance.severity ? <Badge label={`Level ${grievance.severity}`} tone={severityTone(grievance.severity)} /> : null}
                {sla ? <Badge label={sla.label} tone={sla.tone} icon="clock" /> : null}
                {grievance.confidentiality === "confidential" ? <Badge label="Confidential" icon="lock" tone="warning" /> : null}
                {grievance.confidentiality === "anonymous" ? <Badge label="Anonymous" icon="anonymous" tone="neutral" /> : null}
              </View>
            </View>

            <Section title="Case">
              <Row title="Status" value={grievance.status_label} />
              <Row title="Severity" value={grievance.severity_label ? plain(grievance.severity_label).replace(/^Level \d, /, "") : "Not assessed"} />
              <Row title="Category" value={grievance.category?.name ?? "Not classified"} />
              <Row title="Owner" value={grievance.assigned_to?.name ?? grievance.assigned_team ?? "No owner yet"} />
              <Row title="Acknowledged" value={grievance.acknowledged_at ? formatDate(grievance.acknowledged_at) : "Not yet"} />
              {grievance.sla.resolution_due_at ? <Row title="Resolution due" value={formatDate(grievance.sla.resolution_due_at, true)} /> : null}
            </Section>

            <Section title="Complainant">
              {grievance.is_anonymous ? (
                <Row title="Anonymous" subtitle="No identity was recorded for this case." icon="anonymous" iconTone="neutral" />
              ) : grievance.complainant ? (
                [
                  <Row key="name" title="Name" value={grievance.complainant.name ?? "Not given"} />,
                  grievance.complainant.phone ? <Row key="phone" title="Phone" value={grievance.complainant.phone} /> : null,
                  grievance.complainant.email ? <Row key="email" title="Email" value={grievance.complainant.email} /> : null,
                  grievance.preferred_contact_method ? (
                    <Row key="contact" title="Contact by" value={humanise(grievance.preferred_contact_method)} />
                  ) : null,
                  grievance.stakeholder ? (
                    <Row
                      key="stakeholder"
                      title="Stakeholder record"
                      value={grievance.stakeholder.name}
                      onPress={() => grievance.stakeholder && openRecord("stakeholder", grievance.stakeholder.id)}
                    />
                  ) : null,
                ].filter(Boolean)
              ) : (
                <Row
                  title="Identity withheld"
                  subtitle={grievance.identity_withheld_reason ?? "Only the handling group can see who raised this confidential case."}
                  icon="lock"
                  iconTone="warning"
                />
              )}
            </Section>

            <Section title="What happened">
              <View style={{ padding: space.l, gap: space.m }}>
                <T>{grievance.description}</T>
                {grievance.desired_resolution ? (
                  <View>
                    <T variant="footnote" tone="secondary">
                      What they want done
                    </T>
                    <T style={{ marginTop: 2 }}>{grievance.desired_resolution}</T>
                  </View>
                ) : null}
              </View>
              {grievance.location?.path || grievance.location_text ? (
                <Row
                  title="Where"
                  subtitle={[grievance.location_text, grievance.location?.path].filter(Boolean).join("\n")}
                  icon="location"
                  iconTone="accent"
                />
              ) : null}
            </Section>

            <Section title="Timeline" style={{ marginBottom: space.l }}>
              <Timeline events={timeline(grievance)} />
            </Section>
          </>
        ) : null}
      </ScrollView>
    </>
  );
}

const styles = StyleSheet.create({
  header: { paddingHorizontal: space.xl, paddingTop: space.l },
  badges: { flexDirection: "row", flexWrap: "wrap", gap: 6, marginTop: space.m },
  card: { padding: space.l },
  event: { flexDirection: "row", gap: space.m },
  rail: { width: 12, alignItems: "center", paddingTop: 5 },
  dot: { width: 9, height: 9, borderRadius: 5 },
  line: { width: 2, flex: 1, marginTop: 4, borderRadius: 1 },
});
