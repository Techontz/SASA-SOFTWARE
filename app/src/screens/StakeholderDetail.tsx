import { Stack, useLocalSearchParams } from "expo-router";
import { Linking, ScrollView, StyleSheet, View } from "react-native";
import { LoadProblem, usePullToRefresh, useRecordLinks } from "@/components/ListParts";
import { Badge, Row, Section, Skeleton, T } from "@/components/ui";
import { formatDate, humanise, priorityTone } from "@/lib/format";
import { useStakeholder, useStakeholderTimeline } from "@/lib/queries";
import { useQaScroll } from "@/lib/qa";
import { space, useTheme } from "@/lib/theme";
import type { IconName } from "@/components/Icon";
import type { Tone } from "@/lib/theme";
import { Avatar } from "./StakeholderList";

const TIMELINE_ICON: Record<string, { icon: IconName; tone: Tone }> = {
  engagement: { icon: "engagement", tone: "accent" },
  grievance: { icon: "grievance", tone: "warning" },
  commitment: { icon: "handshake", tone: "info" },
  concern: { icon: "megaphone", tone: "neutral" },
};

function Stat({ value, label }: { value: number; label: string }) {
  return (
    <View style={{ flex: 1, alignItems: "center", paddingVertical: space.m }}>
      <T variant="title2">{value}</T>
      <T variant="footnote" tone="secondary">
        {label}
      </T>
    </View>
  );
}

export default function StakeholderDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const theme = useTheme();
  const record = useStakeholder(id);
  const history = useStakeholderTimeline(id);
  const openRecord = useRecordLinks();
  const refresh = usePullToRefresh(async () => Promise.all([record.refetch(), history.refetch()]));
  const stakeholder = record.data;
  const scrollRef = useQaScroll(Boolean(stakeholder));
  const assessment = stakeholder?.assessment;
  const priority = assessment?.stored_priority ?? assessment?.calculated_priority;

  return (
    <>
      <Stack.Screen options={{ title: stakeholder?.reference ?? "", headerLargeTitleEnabled: false }} />
      <ScrollView ref={scrollRef} contentInsetAdjustmentBehavior="automatic" refreshControl={refresh} contentContainerStyle={{ paddingBottom: 48 }}>
        {record.isPending ? (
          <View style={{ alignItems: "center", gap: space.m, marginTop: space.xxl }}>
            <Skeleton width={72} height={72} style={{ borderRadius: 36 }} />
            <Skeleton width={180} height={22} />
            <Skeleton width={120} height={14} />
          </View>
        ) : null}
        {record.isError ? <LoadProblem error={record.error} onRetry={() => void record.refetch()} /> : null}
        {stakeholder ? (
          <>
            <View style={styles.header}>
              <Avatar name={stakeholder.name} size={72} />
              <T variant="title2" align="center" style={{ marginTop: space.m }}>
                {stakeholder.name}
              </T>
              <T tone="secondary" align="center" style={{ marginTop: 2 }}>
                {[stakeholder.type_label, stakeholder.organisation_name].filter(Boolean).join(" · ")}
              </T>
              <View style={styles.badges}>
                {priority ? <Badge label={`${humanise(priority)} priority`} tone={priorityTone(priority)} /> : null}
                {stakeholder.is_vulnerable ? <Badge label="Vulnerable" tone="info" /> : null}
                {stakeholder.review_due ? <Badge label="Review due" tone="warning" /> : null}
                {stakeholder.status !== "active" ? <Badge label={humanise(stakeholder.status)} /> : null}
              </View>
            </View>

            {stakeholder.counts ? (
              <View style={[styles.stats, { backgroundColor: theme.card }]}>
                <Stat value={stakeholder.counts.engagements} label="Engagements" />
                <View style={[styles.divider, { backgroundColor: theme.separator }]} />
                <Stat value={stakeholder.counts.grievances} label="Grievances" />
                <View style={[styles.divider, { backgroundColor: theme.separator }]} />
                <Stat value={stakeholder.counts.commitments} label="Commitments" />
              </View>
            ) : null}

            <Section title="Contact">
              {stakeholder.phone ? (
                <Row title="Phone" value={stakeholder.phone} accent onPress={() => void Linking.openURL(`tel:${stakeholder.phone}`)} chevron={false} />
              ) : (
                <Row title="Phone" value="Not recorded" />
              )}
              {stakeholder.email ? (
                <Row title="Email" value={stakeholder.email} accent onPress={() => void Linking.openURL(`mailto:${stakeholder.email}`)} chevron={false} />
              ) : null}
              <Row title="Language" value={humanise(stakeholder.preferred_language) || "Not recorded"} />
              <Row title="Contact by" value={humanise(stakeholder.preferred_contact_method) || "Not recorded"} />
              {stakeholder.location?.path ? <Row title="Where" subtitle={stakeholder.location.path} /> : null}
              {stakeholder.physical_address ? <Row title="Address" subtitle={stakeholder.physical_address} /> : null}
            </Section>

            {assessment ? (
              <Section
                title="Assessment"
                footer={stakeholder.engagement_strategy ? `Strategy: ${stakeholder.engagement_strategy}. ${stakeholder.communication_frequency ? `Contact ${stakeholder.communication_frequency.toLowerCase()}.` : ""}` : undefined}
              >
                <Row title="Influence" value={humanise(assessment.influence) || "Not assessed"} />
                <Row title="Interest" value={humanise(assessment.interest) || "Not assessed"} />
                <Row title="Power" value={humanise(assessment.power) || "Not assessed"} />
                <Row title="Impact" value={humanise(assessment.impact) || "Not assessed"} />
                {assessment.score !== null ? <Row title="Score" value={String(assessment.score)} /> : null}
              </Section>
            ) : null}

            {stakeholder.concerns_expectations ? (
              <Section title="Concerns and expectations">
                <View style={{ padding: space.l }}>
                  <T>{stakeholder.concerns_expectations}</T>
                </View>
              </Section>
            ) : null}

            <Section title="History">
              {history.isPending ? (
                <View style={{ padding: space.l, gap: space.s }}>
                  <Skeleton width="70%" height={14} />
                  <Skeleton width="50%" height={12} />
                </View>
              ) : (history.data ?? []).length === 0 ? (
                <Row title="Nothing recorded yet" subtitle="Engagements, cases and commitments will appear here." />
              ) : (
                (history.data ?? []).slice(0, 12).map((item) => {
                  const style = TIMELINE_ICON[item.type] ?? { icon: "info" as IconName, tone: "neutral" as Tone };
                  const linkable = item.type === "engagement" || item.type === "grievance";
                  return (
                    <Row
                      key={`${item.type}-${item.id}`}
                      icon={style.icon}
                      iconTone={style.tone}
                      title={item.title}
                      subtitle={[item.reference, formatDate(item.at)].filter(Boolean).join(" · ")}
                      onPress={linkable ? () => openRecord(item.type as "engagement" | "grievance", item.id) : undefined}
                    />
                  );
                })
              )}
            </Section>
          </>
        ) : null}
      </ScrollView>
    </>
  );
}

const styles = StyleSheet.create({
  header: { alignItems: "center", paddingHorizontal: space.xl, paddingTop: space.l },
  badges: { flexDirection: "row", flexWrap: "wrap", justifyContent: "center", gap: 6, marginTop: space.m },
  stats: { flexDirection: "row", alignItems: "center", marginHorizontal: space.l, marginTop: space.xl, borderRadius: 14, borderCurve: "continuous" },
  divider: { width: StyleSheet.hairlineWidth, height: 32 },
});
