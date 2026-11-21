import DateTimePicker, { DateTimePickerAndroid } from "@react-native-community/datetimepicker";
import * as Haptics from "expo-haptics";
import { useMemo, useState } from "react";
import {
  FlatList,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  Switch,
  TextInput,
  View,
  type KeyboardTypeOptions,
  type TextInputProps,
} from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { formatDate, formatDateTime } from "@/lib/format";
import { REQUIRED_MESSAGE } from "@/lib/forms";
import { radius, space, type, useTheme } from "@/lib/theme";
import { Icon } from "./Icon";
import { T } from "./ui";

/*
 * Form rows for grouped sections, in the iOS style: the label on the left,
 * the value on the right, and a mandatory field says "Required" where the
 * value will go rather than shouting with asterisks.
 */

interface FieldBase {
  label: string;
  required?: boolean;
  error?: string;
}

/*
 * A plain "required" problem is shown by the field itself: the label and the
 * word "Required" turn red. Only a specific message gets a line of its own.
 */
function FieldError({ error }: { error?: string }) {
  if (!error || error === REQUIRED_MESSAGE) return null;
  return (
    <T variant="footnote" tone="danger" style={{ paddingHorizontal: space.l, paddingBottom: 10, marginTop: -4 }}>
      {error}
    </T>
  );
}

function Label({ label, error, flex }: { label: string; error?: string; flex?: boolean }) {
  return (
    <T tone={error ? "danger" : "primary"} style={flex ? { flex: 1 } : { width: 128 }} numberOfLines={2}>
      {label}
    </T>
  );
}

// -------------------------------------------------------------------- text

interface TextFieldProps extends FieldBase {
  value: string;
  onChange: (value: string) => void;
  keyboardType?: KeyboardTypeOptions;
  autoCapitalize?: TextInputProps["autoCapitalize"];
  autoComplete?: TextInputProps["autoComplete"];
  placeholder?: string;
  editable?: boolean;
}

export function TextField({
  label,
  required,
  error,
  value,
  onChange,
  keyboardType,
  autoCapitalize = "sentences",
  autoComplete,
  placeholder,
  editable = true,
}: TextFieldProps) {
  const theme = useTheme();
  return (
    <View>
      <View style={styles.row}>
        <Label label={label} error={error} />
        <TextInput
          value={value}
          onChangeText={onChange}
          placeholder={required ? "Required" : (placeholder ?? "Optional")}
          placeholderTextColor={error ? theme.tone.danger.fg : theme.placeholder}
          keyboardType={keyboardType}
          autoCapitalize={autoCapitalize}
          autoComplete={autoComplete}
          editable={editable}
          accessibilityLabel={label}
          style={[type.body, styles.input, { color: editable ? theme.text : theme.tertiary }]}
          selectionColor={theme.accent}
        />
      </View>
      <FieldError error={error} />
    </View>
  );
}

export function NumberField(props: Omit<TextFieldProps, "keyboardType" | "autoCapitalize">) {
  return (
    <TextField
      {...props}
      keyboardType="number-pad"
      autoCapitalize="none"
      onChange={(value) => props.onChange(value.replace(/[^0-9]/g, ""))}
    />
  );
}

/** Several lines of prose: the label sits above the text. */
export function TextAreaField({ label, required, error, value, onChange, placeholder }: TextFieldProps) {
  const theme = useTheme();
  return (
    <View style={{ paddingHorizontal: space.l, paddingTop: 12, paddingBottom: error && error !== REQUIRED_MESSAGE ? 0 : 12 }}>
      <View style={{ flexDirection: "row", alignItems: "baseline" }}>
        <T tone={error ? "danger" : "primary"} style={{ flex: 1 }}>
          {label}
        </T>
        <T variant="footnote" style={{ color: error ? theme.tone.danger.fg : theme.tertiary }}>
          {required ? "Required" : "Optional"}
        </T>
      </View>
      <TextInput
        value={value}
        onChangeText={onChange}
        multiline
        placeholder={placeholder}
        placeholderTextColor={theme.placeholder}
        accessibilityLabel={label}
        style={[type.body, { color: theme.text, minHeight: 66, marginTop: 6, textAlignVertical: "top", padding: 0 }]}
        selectionColor={theme.accent}
        scrollEnabled={false}
      />
      {error && error !== REQUIRED_MESSAGE ? (
        <T variant="footnote" tone="danger" style={{ paddingBottom: 10, marginTop: 4 }}>
          {error}
        </T>
      ) : null}
    </View>
  );
}

// ------------------------------------------------------------------ toggle

export function ToggleField({ label, value, onChange, subtitle }: { label: string; value: boolean; onChange: (value: boolean) => void; subtitle?: string }) {
  const theme = useTheme();
  return (
    <View style={styles.row}>
      <View style={{ flex: 1, paddingVertical: 10 }}>
        <T>{label}</T>
        {subtitle ? (
          <T variant="footnote" tone="secondary" style={{ marginTop: 2 }}>
            {subtitle}
          </T>
        ) : null}
      </View>
      <Switch
        value={value}
        onValueChange={(next) => {
          void Haptics.selectionAsync();
          onChange(next);
        }}
        trackColor={{ true: theme.tone.success.solid, false: theme.fill }}
        accessibilityLabel={label}
      />
    </View>
  );
}

// --------------------------------------------------------------- segmented

export interface Option {
  value: string;
  label: string;
  subtitle?: string;
}

/** For two to four short choices: a segmented control beside the label. */
export function SegmentField({ label, required, error, value, options, onChange }: FieldBase & { value: string; options: Option[]; onChange: (value: string) => void }) {
  const theme = useTheme();
  return (
    <View>
      <View style={[styles.row, { paddingVertical: 8 }]}>
        <Label label={label} error={error} flex />
        <View style={[styles.segment, { backgroundColor: theme.segmentTrack }]} accessibilityRole="radiogroup" accessibilityLabel={label}>
          {options.map((option) => {
            const selected = option.value === value;
            return (
              <Pressable
                key={option.value}
                accessibilityRole="radio"
                accessibilityState={{ selected }}
                accessibilityLabel={`${label}: ${option.label}`}
                onPress={() => {
                  void Haptics.selectionAsync();
                  onChange(selected && !required ? "" : option.value);
                }}
                style={[
                  styles.segmentItem,
                  selected ? { backgroundColor: theme.segmentThumb } : null,
                  selected ? styles.segmentSelected : null,
                ]}
              >
                <T variant="footnote" weight={selected ? "600" : "500"} tone={selected ? "primary" : "secondary"}>
                  {option.label}
                </T>
              </Pressable>
            );
          })}
        </View>
      </View>
      <FieldError error={error} />
    </View>
  );
}

// ------------------------------------------------------------------ picker

interface PickerFieldProps extends FieldBase {
  value: string;
  options: Option[];
  onChange: (value: string) => void;
  /** Shown above the list in the sheet. */
  sheetTitle?: string;
  searchable?: boolean;
}

/** A row that opens a sheet of choices, with a checkmark on the current one. */
export function PickerField({ label, required, error, value, options, onChange, sheetTitle, searchable }: PickerFieldProps) {
  const theme = useTheme();
  const [open, setOpen] = useState(false);
  const selected = options.find((option) => option.value === value);
  return (
    <View>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={`${label}, ${selected?.label ?? (required ? "required, not chosen" : "not chosen")}`}
        style={({ pressed }) => [styles.row, pressed ? { backgroundColor: theme.pressed } : null]}
      >
        <Label label={label} error={error} />
        <T numberOfLines={1} align="right" style={{ flex: 1, color: selected ? theme.secondary : error ? theme.tone.danger.fg : theme.placeholder }}>
          {selected?.label ?? (required ? "Required" : "Choose")}
        </T>
        <Icon name="updown" size={13} color={theme.tertiary} weight="semibold" />
      </Pressable>
      <FieldError error={error} />
      <OptionSheet
        visible={open}
        title={sheetTitle ?? label}
        options={options}
        selected={value ? [value] : []}
        searchable={searchable ?? options.length > 12}
        onClose={() => setOpen(false)}
        onSelect={(next) => {
          onChange(next);
          setOpen(false);
        }}
      />
    </View>
  );
}

interface MultiPickerFieldProps extends FieldBase {
  values: string[];
  options: Option[];
  onChange: (values: string[]) => void;
  sheetTitle?: string;
  emptyLabel?: string;
  onSearch?: (text: string) => void;
}

export function MultiPickerField({ label, required, error, values, options, onChange, sheetTitle, onSearch }: MultiPickerFieldProps) {
  const theme = useTheme();
  const [open, setOpen] = useState(false);
  const summary = values.length === 0 ? (required ? "Required" : "None") : values.length === 1 ? "1 chosen" : `${values.length} chosen`;
  return (
    <View>
      <Pressable
        onPress={() => setOpen(true)}
        accessibilityRole="button"
        accessibilityLabel={`${label}, ${summary}`}
        style={({ pressed }) => [styles.row, pressed ? { backgroundColor: theme.pressed } : null]}
      >
        <Label label={label} error={error} />
        <T numberOfLines={1} align="right" style={{ flex: 1, color: values.length ? theme.secondary : error ? theme.tone.danger.fg : theme.placeholder }}>
          {summary}
        </T>
        <Icon name="chevron" size={13} color={theme.tertiary} weight="semibold" />
      </Pressable>
      <FieldError error={error} />
      <OptionSheet
        visible={open}
        title={sheetTitle ?? label}
        options={options}
        selected={values}
        multiple
        searchable
        onSearch={onSearch}
        onClose={() => setOpen(false)}
        onSelect={(value) => {
          onChange(values.includes(value) ? values.filter((item) => item !== value) : [...values, value]);
        }}
      />
    </View>
  );
}

interface OptionSheetProps {
  visible: boolean;
  title: string;
  options: Option[];
  selected: string[];
  multiple?: boolean;
  searchable?: boolean;
  onSearch?: (text: string) => void;
  onSelect: (value: string) => void;
  onClose: () => void;
}

export function OptionSheet({ visible, title, options, selected, multiple, searchable, onSearch, onSelect, onClose }: OptionSheetProps) {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const [query, setQuery] = useState("");
  const shown = useMemo(() => {
    if (onSearch || !query.trim()) return options;
    const needle = query.trim().toLowerCase();
    return options.filter((option) => `${option.label} ${option.subtitle ?? ""}`.toLowerCase().includes(needle));
  }, [onSearch, options, query]);

  return (
    <Modal
      visible={visible}
      animationType="slide"
      presentationStyle="pageSheet"
      onRequestClose={onClose}
      onDismiss={() => setQuery("")}
    >
      <View style={{ flex: 1, backgroundColor: theme.canvas }}>
        <View style={styles.sheetHeader}>
          <View style={{ width: 70 }} />
          <T variant="headline" align="center" style={{ flex: 1 }} numberOfLines={1}>
            {title}
          </T>
          <Pressable onPress={onClose} hitSlop={12} style={{ width: 70, alignItems: "flex-end" }} accessibilityRole="button">
            <T weight={multiple ? "600" : "400"} tone="accent">
              {multiple ? "Done" : "Cancel"}
            </T>
          </Pressable>
        </View>
        {searchable ? (
          <View style={[styles.search, { backgroundColor: theme.fill }]}>
            <Icon name="search" size={16} color={theme.tertiary} />
            <TextInput
              value={query}
              onChangeText={(text) => {
                setQuery(text);
                onSearch?.(text);
              }}
              placeholder="Search"
              placeholderTextColor={theme.placeholder}
              autoCorrect={false}
              clearButtonMode="while-editing"
              style={[type.body, { flex: 1, color: theme.text, paddingVertical: 0 }]}
              selectionColor={theme.accent}
            />
          </View>
        ) : null}
        <FlatList
          data={shown}
          keyExtractor={(item) => item.value}
          keyboardShouldPersistTaps="handled"
          keyboardDismissMode="on-drag"
          contentContainerStyle={{ paddingBottom: insets.bottom + 24 }}
          style={{ marginTop: space.s }}
          ListEmptyComponent={
            <T tone="secondary" align="center" style={{ marginTop: 40 }}>
              Nothing matches.
            </T>
          }
          renderItem={({ item, index }) => {
            const checked = selected.includes(item.value);
            const first = index === 0;
            const last = index === shown.length - 1;
            return (
              <Pressable
                onPress={() => {
                  void Haptics.selectionAsync();
                  onSelect(item.value);
                }}
                accessibilityRole={multiple ? "checkbox" : "radio"}
                accessibilityState={{ checked }}
                style={({ pressed }) => [
                  styles.option,
                  { backgroundColor: pressed ? theme.pressed : theme.card },
                  first ? { borderTopLeftRadius: radius.card, borderTopRightRadius: radius.card } : null,
                  last ? { borderBottomLeftRadius: radius.card, borderBottomRightRadius: radius.card } : null,
                ]}
              >
                <View style={{ flex: 1, paddingVertical: item.subtitle ? 9 : 12 }}>
                  <T>{item.label}</T>
                  {item.subtitle ? (
                    <T variant="footnote" tone="secondary" numberOfLines={1}>
                      {item.subtitle}
                    </T>
                  ) : null}
                </View>
                {checked ? <Icon name="check" size={17} color={theme.accent} weight="semibold" /> : null}
                {!last ? (
                  <View style={[styles.optionSeparator, { backgroundColor: theme.separator }]} />
                ) : null}
              </Pressable>
            );
          }}
        />
      </View>
    </Modal>
  );
}

// -------------------------------------------------------------------- date

export function DateField({ label, required, error, value, onChange, withTime }: FieldBase & { value: Date | null; onChange: (value: Date) => void; withTime?: boolean }) {
  const theme = useTheme();
  const current = value ?? new Date();

  if (Platform.OS === "ios") {
    return (
      <View>
        <View style={[styles.row, { paddingVertical: 6 }]}>
          <Label label={label} error={error} flex />
          <DateTimePicker
            value={current}
            mode={withTime ? "datetime" : "date"}
            display="compact"
            maximumDate={new Date()}
            accentColor={theme.accent}
            themeVariant={theme.scheme}
            onValueChange={(_event, date) => onChange(date)}
          />
        </View>
        <FieldError error={error} />
      </View>
    );
  }

  const open = () =>
    DateTimePickerAndroid.open({
      value: current,
      mode: "date",
      maximumDate: new Date(),
      onValueChange: (_event, date) => {
        if (!withTime) return onChange(date);
        DateTimePickerAndroid.open({
          value: date,
          mode: "time",
          onValueChange: (_e, time) => onChange(time),
        });
      },
    });

  return (
    <View>
      <Pressable onPress={open} style={styles.row} accessibilityRole="button" accessibilityLabel={label}>
        <Label label={label} error={error} />
        <T align="right" style={{ flex: 1, color: value ? theme.secondary : theme.placeholder }}>
          {value ? (withTime ? formatDateTime(value.toISOString()) : formatDate(value.toISOString(), true)) : required ? "Required" : "Choose"}
        </T>
      </Pressable>
      <FieldError error={error} />
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.m, paddingHorizontal: space.l, minHeight: 48 },
  input: { flex: 1, textAlign: "right", paddingVertical: 12 },
  segment: { flexDirection: "row", borderRadius: 9, padding: 2, gap: 2 },
  segmentItem: { paddingHorizontal: 12, paddingVertical: 6, borderRadius: 7, minWidth: 54, alignItems: "center" },
  segmentSelected: {
    shadowColor: "#000",
    shadowOpacity: 0.12,
    shadowRadius: 3,
    shadowOffset: { width: 0, height: 1 },
    elevation: 1,
  },
  sheetHeader: { flexDirection: "row", alignItems: "center", paddingHorizontal: space.l, paddingTop: space.l, paddingBottom: space.m },
  search: {
    flexDirection: "row",
    alignItems: "center",
    gap: 8,
    marginHorizontal: space.l,
    paddingHorizontal: 10,
    height: 38,
    borderRadius: 10,
  },
  option: { flexDirection: "row", alignItems: "center", paddingHorizontal: space.l, marginHorizontal: space.l, minHeight: 48 },
  optionSeparator: { position: "absolute", left: space.l, right: 0, bottom: 0, height: StyleSheet.hairlineWidth },
});
