import { Stack, useLocalSearchParams } from "expo-router";
import { ScrollView, StyleSheet, View } from "react-native";
import { LoadProblem, usePullToRefresh, useRecordLinks } from "@/components/ListParts";
import { Badge, Row, Section, Skeleton, T } from "@/components/ui";
import { formatDateTime, humanise } from "@/lib/format";
import { useEngagement } from "@/lib/queries";
import { useQaScroll } from "@/lib/qa";
import { space, useTheme } from "@/lib/theme";

function Figure({ value, label }: { value: number | null; label: string }) {
  return (
    <View style={{ width: "33.33%", alignItems: "center", paddingVertical: space.m }}>
      <T variant="title2">{value ?? "–"}</T>
      <T variant="footnote" tone="secondary" align="center">
        {label}
      </T>
    </View>
  );
}

function Prose({ label, text }: { label: string; text: string | null }) {
  if (!text) return null;
  return (
    <View style={{ padding: space.l }}>
      <T variant="footnote" tone="secondary">
        {label}
      </T>
      <T style={{ marginTop: 2 }}>{text}</T>
    </View>
  );
}

export default function EngagementDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const theme = useTheme();
  const record = useEngagement(id);
  const refresh = usePullToRefresh(record.refetch);
  const openRecord = useRecordLinks();
  const engagement = record.data;
  const scrollRef = useQaScroll(Boolean(engagement));

  return (
    <>
      <Stack.Screen options={{ title: engagement?.reference ?? "", headerLargeTitleEnabled: false }} />
      <ScrollView ref={scrollRef} contentInsetAdjustmentBehavior="automatic" refreshControl={refresh} contentContainerStyle={{ paddingBottom: 48 }}>
        {record.isPending ? (
          <View style={{ padding: space.xl, gap: space.m }}>
            <Skeleton width={140} height={12} />
            <Skeleton width="85%" height={24} />
            <Skeleton width="100%" height={120} style={{ marginTop: space.l }} />
          </View>
        ) : null}
        {record.isError ? <LoadProblem error={record.error} onRetry={() => void record.refetch()} /> : null}
        {engagement ? (
          <>
            <View style={styles.header}>
              <T variant="footnote" tone="secondary">
                {formatDateTime(engagement.held_at)}
              </T>
              <T variant="title2" style={{ marginTop: 4 }}>
                {engagement.topic}
              </T>
              <View style={styles.badges}>
                {engagement.method ? <Badge label={humanise(engagement.method)} tone="accent" /> : null}
                {engagement.planned_vs_actual_label ? <Badge label={engagement.planned_vs_actual_label} /> : null}
                <Badge label={humanise(engagement.status)} tone={engagement.status === "verified" ? "success" : "neutral"} />
              </View>
            </View>

            <Section title="Attendance">
              <View style={[styles.figures, { backgroundColor: theme.card }]}>
                <Figure value={engagement.attendance.total} label="Total" />
                <Figure value={engagement.attendance.female} label="Women" />
                <Figure value={engagement.attendance.male} label="Men" />
                <Figure value={engagement.attendance.youth} label="Young people" />
                <Figure value={engagement.attendance.elderly} label="Elderly" />
                <Figure value={engagement.attendance.disability} label="With a disability" />
              </View>
            </Section>

            <Section title="Details">
              <Row title="Phase" value={humanise(engagement.project_phase) || "Not recorded"} />
              <Row title="Facilitated by" value={engagement.facilitator?.name ?? "Not recorded"} />
              {engagement.organised_by ? <Row title="Organised by" value={engagement.organised_by} /> : null}
              {engagement.plan ? <Row title="Plan" value={engagement.plan.reference} /> : null}
              {engagement.venue ? <Row title="Venue" subtitle={engagement.venue} /> : null}
              {engagement.location?.path ? <Row title="Location" subtitle={engagement.location.path} /> : null}
            </Section>

            {engagement.aim || engagement.discussion_points || engagement.outcomes ? (
              <Section title="Record">
                <Prose label="Aim" text={engagement.aim} />
                <Prose label="Discussion" text={engagement.discussion_points} />
                <Prose label="Outcomes" text={engagement.outcomes} />
              </Section>
            ) : null}

            {engagement.stakeholders && engagement.stakeholders.length > 0 ? (
              <Section title="Stakeholders present">
                {engagement.stakeholders.map((stakeholder) => (
                  <Row
                    key={stakeholder.id}
                    title={stakeholder.name}
                    subtitle={stakeholder.reference}
                    onPress={() => openRecord("stakeholder", stakeholder.id)}
                  />
                ))}
              </Section>
            ) : null}
          </>
        ) : null}
      </ScrollView>
    </>
  );
}

const styles = StyleSheet.create({
  header: { paddingHorizontal: space.xl, paddingTop: space.l },
  badges: { flexDirection: "row", flexWrap: "wrap", gap: 6, marginTop: space.m },
  figures: { flexDirection: "row", flexWrap: "wrap", paddingVertical: space.s },
});
