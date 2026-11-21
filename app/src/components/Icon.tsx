import { SymbolView, type AndroidSymbol, type SymbolWeight } from "expo-symbols";
import type { SFSymbol } from "sf-symbols-typescript";

/**
 * One icon vocabulary for the whole app: SF Symbols on iOS, the matching
 * Material Symbol on Android.
 */
const ICONS = {
  today: { ios: "sun.max", android: "home" },
  grievance: { ios: "exclamationmark.bubble", android: "report" },
  stakeholder: { ios: "person.2", android: "groups" },
  engagement: { ios: "bubble.left.and.bubble.right", android: "forum" },
  account: { ios: "person.crop.circle", android: "account_circle" },
  chevron: { ios: "chevron.right", android: "chevron_right" },
  updown: { ios: "chevron.up.chevron.down", android: "unfold_more" },
  check: { ios: "checkmark", android: "check" },
  plus: { ios: "plus", android: "add" },
  close: { ios: "xmark", android: "close" },
  search: { ios: "magnifyingglass", android: "search" },
  lock: { ios: "lock.fill", android: "lock" },
  anonymous: { ios: "eye.slash.fill", android: "visibility_off" },
  people: { ios: "person.3.fill", android: "groups" },
  personAdd: { ios: "person.crop.circle.badge.plus", android: "person_add" },
  personMissing: { ios: "person.crop.circle.badge.questionmark", android: "person_off" },
  hourglass: { ios: "hourglass", android: "hourglass_top" },
  handshake: { ios: "checklist", android: "checklist" },
  calendar: { ios: "calendar", android: "calendar_today" },
  clock: { ios: "clock", android: "schedule" },
  location: { ios: "mappin.and.ellipse", android: "location_on" },
  phone: { ios: "phone", android: "call" },
  mail: { ios: "envelope", android: "mail" },
  warning: { ios: "exclamationmark.triangle.fill", android: "warning" },
  info: { ios: "info.circle", android: "info" },
  signOut: { ios: "rectangle.portrait.and.arrow.right", android: "logout" },
  offline: { ios: "wifi.slash", android: "wifi_off" },
  error: { ios: "exclamationmark.circle", android: "error" },
  note: { ios: "square.and.pencil", android: "edit_note" },
  megaphone: { ios: "megaphone", android: "campaign" },
  review: { ios: "arrow.triangle.2.circlepath", android: "sync" },
  project: { ios: "point.3.connected.trianglepath.dotted", android: "hub" },
  appearance: { ios: "circle.lefthalf.filled", android: "contrast" },
  success: { ios: "checkmark.circle.fill", android: "check_circle" },
  standard: { ios: "person.2.fill", android: "group" },
  shield: { ios: "checkmark.shield", android: "verified_user" },
} as const satisfies Record<string, { ios: SFSymbol; android: AndroidSymbol }>;

export type IconName = keyof typeof ICONS;

interface IconProps {
  name: IconName;
  size?: number;
  color: string;
  weight?: SymbolWeight;
}

export function Icon({ name, size = 20, color, weight = "medium" }: IconProps) {
  return (
    <SymbolView
      name={ICONS[name]}
      size={size}
      tintColor={color}
      weight={weight}
      resizeMode="scaleAspectFit"
      style={{ width: size, height: size }}
    />
  );
}

export function sfSymbol(name: IconName): SFSymbol {
  return ICONS[name].ios;
}

export function materialSymbol(name: IconName): AndroidSymbol {
  return ICONS[name].android;
}
