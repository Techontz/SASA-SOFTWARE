import { Stack } from "expo-router";
import { FlatList, View } from "react-native";
import { CardRow, LoadingMore, LoadProblem, usePullToRefresh } from "@/components/ListParts";
import { Badge, Message, SkeletonList, T } from "@/components/ui";
import { formatDate, humanise, priorityTone } from "@/lib/format";
import { useCommitments } from "@/lib/queries";
import { space } from "@/lib/theme";

/** Overdue commitments, opened from Today. Read-only on the phone. */
export default function CommitmentList() {
  const list = useCommitments({ overdue: 1 });
  const rows = list.data?.pages.flatMap((page) => page.data) ?? [];
  const refresh = usePullToRefresh(list.refetch);

  return (
    <>
      <Stack.Screen options={{ title: "Overdue commitments", headerLargeTitleEnabled: false }} />
      <FlatList
        data={rows}
        keyExtractor={(item) => String(item.id)}
        contentInsetAdjustmentBehavior="automatic"
        refreshControl={refresh}
        onEndReached={() => {
          if (list.hasNextPage && !list.isFetchingNextPage) void list.fetchNextPage();
        }}
        ListHeaderComponent={<View style={{ height: space.l }}>{list.isPending ? <SkeletonList rows={4} /> : null}</View>}
        ListEmptyComponent={
          list.isPending ? undefined : list.isError ? (
            <LoadProblem error={list.error} onRetry={() => void list.refetch()} />
          ) : (
            <Message icon="handshake" title="Nothing overdue" message="Every open commitment is still within its due date." />
          )
        }
        ListFooterComponent={rows.length > 0 ? <LoadingMore visible={list.isFetchingNextPage} /> : undefined}
        renderItem={({ item, index }) => {
          const late = item.days_until_due !== null ? Math.abs(item.days_until_due) : null;
          return (
            <CardRow first={index === 0} last={index === rows.length - 1}>
              <View style={{ gap: 4 }}>
                <View style={{ flexDirection: "row" }}>
                  <T variant="footnote" tone="secondary" style={{ flex: 1 }}>
                    {item.reference} · due {formatDate(item.due_date)}
                  </T>
                  {late !== null ? (
                    <T variant="footnote" weight="600" tone="danger">
                      {late === 1 ? "1 day late" : `${late} days late`}
                    </T>
                  ) : null}
                </View>
                <T variant="headline">{item.commitment_text}</T>
                <T variant="subhead" tone="secondary">
                  {item.owner?.name ? `Owned by ${item.owner.name}` : "No owner"}
                  {item.stakeholders[0] ? ` · ${item.stakeholders[0].name}` : ""}
                </T>
                <View style={{ flexDirection: "row", gap: 6, marginTop: 2 }}>
                  <Badge label="Overdue" tone="danger" />
                  {item.risk_level ? <Badge label={`${humanise(item.risk_level)} risk`} tone={priorityTone(item.risk_level)} /> : null}
                </View>
              </View>
            </CardRow>
          );
        }}
      />
    </>
  );
}
