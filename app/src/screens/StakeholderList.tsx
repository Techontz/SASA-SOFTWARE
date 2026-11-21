import { Stack, useLocalSearchParams, useRouter } from "expo-router";
import { useMemo, useState } from "react";
import { FlatList, View } from "react-native";
import { searchBarOptions, CardRow, HeaderButton, LoadingMore, LoadProblem, Segments, usePullToRefresh, useRecordLinks } from "@/components/ListParts";
import { Badge, Message, SkeletonList, T } from "@/components/ui";
import type { Query } from "@/lib/api";
import { humanise, initials, priorityTone } from "@/lib/format";
import { useDebounced } from "@/lib/hooks";
import { useStakeholders } from "@/lib/queries";
import { useSession } from "@/lib/session";
import { space, useTheme } from "@/lib/theme";
import type { Stakeholder } from "@/lib/types";

type Scope = "all" | "high" | "vulnerable";

export function Avatar({ name, size = 40 }: { name: string; size?: number }) {
  const theme = useTheme();
  return (
    <View
      style={{
        width: size,
        height: size,
        borderRadius: size / 2,
        backgroundColor: theme.tone.accent.bg,
        alignItems: "center",
        justifyContent: "center",
      }}
    >
      <T style={{ fontSize: size * 0.38, fontWeight: "600", color: theme.tone.accent.fg }}>{initials(name)}</T>
    </View>
  );
}

function StakeholderSummary({ stakeholder }: { stakeholder: Stakeholder }) {
  const priority = stakeholder.assessment?.stored_priority ?? stakeholder.assessment?.calculated_priority;
  const place = stakeholder.location?.path?.split(" / ").pop();
  return (
    <View style={{ flexDirection: "row", alignItems: "center", gap: space.m }}>
      <Avatar name={stakeholder.name} />
      <View style={{ flex: 1, gap: 2 }}>
        <T variant="headline" numberOfLines={1}>
          {stakeholder.name}
        </T>
        <T variant="subhead" tone="secondary" numberOfLines={1}>
          {[stakeholder.type_label, place].filter(Boolean).join(" · ")}
        </T>
        <View style={{ flexDirection: "row", gap: 6, marginTop: 4, flexWrap: "wrap" }}>
          {priority ? <Badge label={`${humanise(priority)} priority`} tone={priorityTone(priority)} /> : null}
          {stakeholder.is_vulnerable ? <Badge label="Vulnerable" tone="info" /> : null}
          {stakeholder.review_due ? <Badge label="Review due" tone="warning" /> : null}
        </View>
      </View>
    </View>
  );
}

export default function StakeholderList() {
  const router = useRouter();
  const { can } = useSession();
  const openRecord = useRecordLinks();
  const theme = useTheme();
  const { filter } = useLocalSearchParams<{ filter?: string }>();
  const reviewOnly = filter === "review";
  const [scope, setScope] = useState<Scope>("all");
  const [search, setSearch] = useState("");
  const term = useDebounced(search, 300);

  const query = useMemo<Query>(() => {
    if (reviewOnly) return { review: "due", search: term };
    if (scope === "high") return { priority: "high", search: term };
    if (scope === "vulnerable") return { is_vulnerable: 1, search: term };
    return { search: term };
  }, [reviewOnly, scope, term]);

  const list = useStakeholders(query);
  const rows = list.data?.pages.flatMap((page) => page.data) ?? [];
  const total = list.data?.pages[0]?.meta.total;
  const refresh = usePullToRefresh(list.refetch);

  return (
    <>
      <Stack.Screen
        options={{
          title: reviewOnly ? "Due for review" : "Stakeholders",
          headerLargeTitleEnabled: !reviewOnly,
          headerSearchBarOptions: searchBarOptions(theme, "Search by name, phone or reference", setSearch),
          headerRight: can("stakeholder.create")
            ? () => <HeaderButton icon="plus" label="Add a stakeholder" onPress={() => router.push("/new-stakeholder")} />
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
            {!reviewOnly ? (
              <Segments<Scope>
                value={scope}
                onChange={setScope}
                options={[
                  { value: "all", label: "All" },
                  { value: "high", label: "High priority" },
                  { value: "vulnerable", label: "Vulnerable" },
                ]}
              />
            ) : (
              <View style={{ height: space.l }} />
            )}
            {list.isPending ? <SkeletonList rows={6} title={false} /> : null}
          </View>
        }
        ListEmptyComponent={
          list.isPending ? undefined : list.isError ? (
            <LoadProblem error={list.error} onRetry={() => void list.refetch()} />
          ) : (
            <Message
              icon="stakeholder"
              title={term ? "No matching stakeholders" : "Nothing here"}
              message={term ? `Nothing matches "${term}".` : reviewOnly ? "No register entry is due for review." : "No stakeholders to show."}
            />
          )
        }
        ListFooterComponent={
          rows.length > 0 ? (
            <View>
              <LoadingMore visible={list.isFetchingNextPage} />
              {!list.hasNextPage && total !== undefined ? (
                <T variant="footnote" tone="tertiary" align="center" style={{ marginTop: -40, marginBottom: 40 }}>
                  {total === 1 ? "1 stakeholder" : `${total} stakeholders`}
                </T>
              ) : null}
            </View>
          ) : undefined
        }
        renderItem={({ item, index }) => (
          <CardRow inset={68} first={index === 0} last={index === rows.length - 1} onPress={() => openRecord("stakeholder", item.id)} accessibilityLabel={`${item.name}, ${item.type_label}`}>
            <StakeholderSummary stakeholder={item} />
          </CardRow>
        )}
      />
    </>
  );
}
