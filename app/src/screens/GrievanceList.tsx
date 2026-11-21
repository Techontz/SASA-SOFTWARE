import { Stack, useLocalSearchParams, useRouter } from "expo-router";
import { useMemo, useState } from "react";
import { FlatList, View } from "react-native";
import { searchBarOptions, CardRow, HeaderButton, LoadingMore, LoadProblem, Segments, usePullToRefresh, useRecordLinks } from "@/components/ListParts";
import { Badge, Message, SkeletonList, T } from "@/components/ui";
import { useDebounced } from "@/lib/hooks";
import { grievanceStatusTone, relativeDay, severityTone, slaLabel } from "@/lib/format";
import { useGrievances } from "@/lib/queries";
import { useSession } from "@/lib/session";
import { space, useTheme } from "@/lib/theme";
import type { Grievance } from "@/lib/types";
import type { Query } from "@/lib/api";

type Scope = "open" | "overdue" | "all";

/** Lists opened from Today arrive with one of these, and keep it. */
const PRESETS: Record<string, { title: string; query: Query; empty: string }> = {
  unassigned: { title: "No owner yet", query: { status: "open", assignee: "none" }, empty: "Every open case has an owner." },
  unacknowledged: {
    title: "Not acknowledged",
    query: { status: "open", acknowledged: 0 },
    empty: "Every complainant has been acknowledged.",
  },
  critical: { title: "Level 5 cases", query: { status: "open", severity: 5 }, empty: "No open case is at Level 5." },
  overdue: { title: "Overdue cases", query: { status: "open", sla: "breached" }, empty: "No case is past its deadline." },
};

export function GrievanceSummary({ grievance }: { grievance: Grievance }) {
  const sla = slaLabel(grievance);
  const theme = useTheme();
  return (
    <View style={{ gap: 4 }}>
      <View style={{ flexDirection: "row", alignItems: "center", gap: space.s }}>
        <T variant="footnote" tone="secondary" style={{ flex: 1 }}>
          {grievance.reference} · {relativeDay(grievance.received_at)}
        </T>
        {sla ? (
          <T variant="footnote" weight="600" style={{ color: sla.tone === "neutral" ? theme.secondary : theme.tone[sla.tone].fg }}>
            {sla.label}
          </T>
        ) : null}
      </View>
      <T variant="headline" numberOfLines={2}>
        {grievance.title || grievance.description}
      </T>
      <View style={{ flexDirection: "row", flexWrap: "wrap", gap: 6, marginTop: 4 }}>
        <Badge label={grievance.status_label} tone={grievanceStatusTone(grievance.status)} />
        {grievance.severity ? <Badge label={`Level ${grievance.severity}`} tone={severityTone(grievance.severity)} /> : null}
        {grievance.confidentiality === "confidential" ? <Badge label="Confidential" icon="lock" tone="warning" /> : null}
        {grievance.confidentiality === "anonymous" ? <Badge label="Anonymous" icon="anonymous" tone="neutral" /> : null}
      </View>
    </View>
  );
}

export default function GrievanceList() {
  const router = useRouter();
  const { can } = useSession();
  const openRecord = useRecordLinks();
  const theme = useTheme();
  const { filter } = useLocalSearchParams<{ filter?: string }>();
  const preset = filter ? PRESETS[filter] : undefined;
  const [scope, setScope] = useState<Scope>("open");
  const [search, setSearch] = useState("");
  const term = useDebounced(search, 300);

  const query = useMemo<Query>(() => {
    if (preset) return { ...preset.query, search: term };
    if (scope === "open") return { status: "open", search: term };
    if (scope === "overdue") return { status: "open", sla: "breached", search: term };
    return { search: term };
  }, [preset, scope, term]);

  const list = useGrievances(query);
  const rows = list.data?.pages.flatMap((page) => page.data) ?? [];
  const total = list.data?.pages[0]?.meta.total;
  const refresh = usePullToRefresh(list.refetch);

  return (
    <>
      <Stack.Screen
        options={{
          title: preset?.title ?? "Grievances",
          headerLargeTitleEnabled: !preset,
          headerSearchBarOptions: searchBarOptions(theme, "Search cases", setSearch),
          headerRight: can("grievance.create")
            ? () => <HeaderButton icon="plus" label="Log a grievance" onPress={() => router.push("/new-grievance")} />
            : undefined,
        }}
      />
      <FlatList
        data={rows}
        keyExtractor={(item) => String(item.id)}
        contentInsetAdjustmentBehavior="automatic"
        keyboardDismissMode="on-drag"
        refreshControl={refresh}
        onEndReachedThreshold={0.6}
        onEndReached={() => {
          if (list.hasNextPage && !list.isFetchingNextPage) void list.fetchNextPage();
        }}
        ListHeaderComponent={
          <View>
            {!preset ? (
              <Segments<Scope>
                value={scope}
                onChange={setScope}
                options={[
                  { value: "open", label: "Open" },
                  { value: "overdue", label: "Overdue" },
                  { value: "all", label: "All" },
                ]}
              />
            ) : (
              <View style={{ height: space.l }} />
            )}
            {list.isPending ? <SkeletonList rows={5} /> : null}
          </View>
        }
        ListEmptyComponent={
          list.isPending ? undefined : list.isError ? (
            <LoadProblem error={list.error} onRetry={() => void list.refetch()} />
          ) : (
            <Message
              icon="grievance"
              title={term ? "No matching cases" : "Nothing here"}
              message={term ? `Nothing matches "${term}".` : (preset?.empty ?? (scope === "overdue" ? "No open case is past its deadline." : "No cases to show."))}
            />
          )
        }
        ListFooterComponent={
          rows.length > 0 ? (
            <View>
              <LoadingMore visible={list.isFetchingNextPage} />
              {!list.hasNextPage && total !== undefined ? (
                <T variant="footnote" tone="tertiary" align="center" style={{ marginTop: -40, marginBottom: 40 }}>
                  {total === 1 ? "1 case" : `${total} cases`}
                </T>
              ) : null}
            </View>
          ) : undefined
        }
        renderItem={({ item, index }) => (
          <CardRow
            first={index === 0}
            last={index === rows.length - 1}
            onPress={() => openRecord("grievance", item.id)}
            accessibilityLabel={`${item.reference}, ${item.title ?? ""}, ${item.status_label}`}
          >
            <GrievanceSummary grievance={item} />
          </CardRow>
        )}
      />
    </>
  );
}
