import { useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { REQUIRED_MESSAGE } from "@/lib/forms";
import { useDebounced } from "@/lib/hooks";
import { useStakeholders } from "@/lib/queries";
import { space, useTheme } from "@/lib/theme";
import { OptionSheet, type Option } from "./Form";
import { Icon } from "./Icon";
import { T } from "./ui";

interface Props {
  label: string;
  required?: boolean;
  error?: string;
  multiple?: boolean;
  /** Chosen stakeholders, with their names so the row can show them. */
  value: Option[];
  onChange: (value: Option[]) => void;
}

/** Choose from the register, searching on the server as the person types. */
export function StakeholderPicker({ label, required, error, multiple, value, onChange }: Props) {
  const theme = useTheme();
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const term = useDebounced(search, 250);
  const list = useStakeholders({ search: term }, open);
  const found: Option[] = (list.data?.pages.flatMap((page) => page.data) ?? []).map((stakeholder) => ({
    value: String(stakeholder.id),
    label: stakeholder.name,
    subtitle: [stakeholder.reference, stakeholder.type_label].join(" · "),
  }));
  // Keep what is already chosen at the top, so it can be unticked.
  const options = [...value, ...found.filter((option) => !value.some((chosen) => chosen.value === option.value))];

  const summary =
    value.length === 0 ? (required ? "Required" : "None") : value.length === 1 ? (value[0]?.label ?? "") : `${value.length} chosen`;

  return (
    <View>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={`${label}, ${summary}`}
        style={({ pressed }) => [styles.row, pressed ? { backgroundColor: theme.pressed } : null]}
      >
        <T tone={error ? "danger" : "primary"} style={{ width: 128 }}>
          {label}
        </T>
        <T numberOfLines={1} align="right" style={{ flex: 1, color: value.length ? theme.secondary : error ? theme.tone.danger.fg : theme.placeholder }}>
          {summary}
        </T>
        <Icon name="chevron" size={13} color={theme.tertiary} weight="semibold" />
      </Pressable>
      {error && error !== REQUIRED_MESSAGE ? (
        <T variant="footnote" tone="danger" style={{ paddingHorizontal: space.l, paddingBottom: 10, marginTop: -4 }}>
          {error}
        </T>
      ) : null}
      <OptionSheet
        visible={open}
        title={label}
        options={options}
        selected={value.map((option) => option.value)}
        multiple={multiple}
        searchable
        onSearch={setSearch}
        onClose={() => setOpen(false)}
        onSelect={(id) => {
          const option = options.find((candidate) => candidate.value === id);
          if (!option) return;
          if (!multiple) {
            onChange([option]);
            setOpen(false);
            return;
          }
          onChange(value.some((chosen) => chosen.value === id) ? value.filter((chosen) => chosen.value !== id) : [...value, option]);
        }}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.m, paddingHorizontal: space.l, minHeight: 48 },
});
