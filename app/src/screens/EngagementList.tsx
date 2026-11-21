import { Stack, useRouter } from "expo-router";
import { useMemo, useState } from "react";
import { FlatList, View } from "react-native";
import { searchBarOptions, CardRow, HeaderButton, LoadingMore, LoadProblem, Segments, usePullToRefresh, useRecordLinks } from "@/components/ListParts";
import { Badge, Message, SkeletonList, T } from "@/components/ui";
import type { Query } from "@/lib/api";
import { formatDate, humanise } from "@/lib/format";
import { useDebounced } from "@/lib/hooks";
import { useEngagements } from "@/lib/queries";
import { useSession } from "@/lib/session";
import { space, useTheme } from "@/lib/theme";
import type { Engagement } from "@/lib/types";

type Scope = "all" | "month" | "unplanned";

function DateBlock({ iso }: { iso: string | null }) {
  const theme = useTheme();
  const date = iso ? new Date(iso) : null;
  return (
    <View style={{ width: 44, alignItems: "center", paddingVertical: 4, borderRadius: 10, backgroundColor: theme.fill }}>
      <T variant="caption" weight="600" style={{ color: theme.tone.danger.fg }}>
        {date ? date.toLocaleDateString("en-GB", { month: "short" }) : ""}
      </T>
      <T variant="title3" weight="600" style={{ lineHeight: 24 }}>
        {date ? date.getDate() : "?"}
      </T>
    </View>
  );
}

export function EngagementSummary({ engagement }: { engagement: Engagement }) {
  const place = engagement.location?.path?.split(" / ").pop() ?? engagement.venue;
  return (
    <View style={{ flexDirection: "row", gap: space.m, alignItems: "flex-start" }}>
      <DateBlock iso={engagement.held_at} />
      <View style={{ flex: 1, gap: 2 }}>
        <T variant="headline" numberOfLines={2}>
          {engagement.topic}
        </T>
        <T variant="subhead" tone="secondary" numberOfLines={1}>
          {[humanise(engagement.method), place].filter(Boolean).join(" · ")}
        </T>
        <View style={{ flexDirection: "row", gap: 6, marginTop: 4, flexWrap: "wrap" }}>
          {engagement.attendance.total !== null ? <Badge label={`${engagement.attendance.total} attended`} tone="neutral" icon="people" /> : null}
          {engagement.planned_vs_actual_label ? (
            <Badge label={engagement.planned_vs_actual_label} tone={engagement.planned_vs_actual === "unplanned" ? "neutral" : "accent"} />
          ) : null}
        </View>
      </View>
    </View>
  );
}

export default function EngagementList() {
  const router = useRouter();
  const { can } = useSession();
  const openRecord = useRecordLinks();
  const theme = useTheme();
  const [scope, setScope] = useState<Scope>("all");
  const [search, setSearch] = useState("");
  const term = useDebounced(search, 300);

  const query = useMemo<Query>(() => {
    if (scope === "unplanned") return { unplanned: 1, search: term };
    if (scope === "month") {
      const now = new Date();
      const first = new Date(now.getFullYear(), now.getMonth(), 1);
      return { from: `${first.getFullYear()}-${String(first.getMonth() + 1).padStart(2, "0")}-01`, search: term };
    }
    return { search: term };
  }, [scope, term]);

  const list = useEngagements(query);
  const rows = list.data?.pages.flatMap((page) => page.data) ?? [];
  const refresh = usePullToRefresh(list.refetch);

  return (
    <>
      <Stack.Screen
        options={{
          title: "Engagements",
          headerSearchBarOptions: searchBarOptions(theme, "Search engagements", setSearch),
          headerRight: can("engagement.log")
            ? () => <HeaderButton icon="plus" label="Log an engagement" onPress={() => router.push("/new-engagement")} />
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
            <Segments<Scope>
              value={scope}
              onChange={setScope}
              options={[
                { value: "all", label: "All" },
                { value: "month", label: "This month" },
                { value: "unplanned", label: "Unplanned" },
              ]}
            />
            {list.isPending ? <SkeletonList rows={5} /> : null}
          </View>
        }
        ListEmptyComponent={
          list.isPending ? undefined : list.isError ? (
            <LoadProblem error={list.error} onRetry={() => void list.refetch()} />
          ) : (
            <Message
              icon="engagement"
              title={term ? "No matching engagements" : "No engagements yet"}
              message={term ? `Nothing matches "${term}".` : "Meetings and visits you log will appear here."}
            />
          )
        }
        ListFooterComponent={rows.length > 0 ? <LoadingMore visible={list.isFetchingNextPage} /> : undefined}
        renderItem={({ item, index }) => (
          <CardRow
            inset={72}
            first={index === 0}
            last={index === rows.length - 1}
            onPress={() => openRecord("engagement", item.id)}
            accessibilityLabel={`${item.topic}, ${formatDate(item.held_at)}`}
          >
            <EngagementSummary engagement={item} />
          </CardRow>
        )}
      />
    </>
  );
}
