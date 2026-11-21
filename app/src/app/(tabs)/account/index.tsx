import Constants from "expo-constants";
import * as Haptics from "expo-haptics";
import { Stack } from "expo-router";
import { Alert, ScrollView, View } from "react-native";
import { Row, Section, T } from "@/components/ui";
import { API_BASE_URL } from "@/lib/api";
import { useAppearance, type AppearancePreference } from "@/lib/appearance";
import { useSession } from "@/lib/session";
import { space } from "@/lib/theme";
import { Avatar } from "@/screens/StakeholderList";

const APPEARANCE: { value: AppearancePreference; label: string }[] = [
  { value: "system", label: "Match this phone" },
  { value: "light", label: "Light" },
  { value: "dark", label: "Dark" },
];

export default function Account() {
  const { user, project, projects, switchProject, signOut } = useSession();
  const { preference, setPreference } = useAppearance();
  const host = API_BASE_URL.replace(/^https?:\/\//, "").split("/")[0];

  const confirmSignOut = () =>
    Alert.alert("Sign out of SASA?", "You will need a connection to sign in again.", [
      { text: "Cancel", style: "cancel" },
      {
        text: "Sign out",
        style: "destructive",
        onPress: () => {
          void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
          void signOut();
        },
      },
    ]);

  return (
    <>
      <Stack.Screen options={{ title: "Account" }} />
      <ScrollView contentInsetAdjustmentBehavior="automatic" contentContainerStyle={{ paddingBottom: 48 }}>
        <Section>
          <View style={{ flexDirection: "row", alignItems: "center", gap: space.l, padding: space.l }}>
            <Avatar name={user?.name ?? ""} size={60} />
            <View style={{ flex: 1 }}>
              <T variant="title3">{user?.name}</T>
              <T variant="subhead" tone="secondary">
                {user?.email}
              </T>
              <T variant="subhead" tone="secondary">
                {project?.role.name}
                {user?.organisation ? ` · ${user.organisation.name}` : ""}
              </T>
            </View>
          </View>
        </Section>

        {projects.length > 1 ? (
          <Section title="Project" footer="Everything you see and record belongs to the project you choose here.">
            {projects.map((candidate) => (
              <Row
                key={candidate.id}
                title={candidate.name}
                subtitle={candidate.role.name}
                checked={candidate.id === project?.id}
                chevron={false}
                onPress={() => {
                  if (candidate.id === project?.id) return;
                  void Haptics.selectionAsync();
                  void switchProject(candidate.id);
                }}
              />
            ))}
          </Section>
        ) : (
          <Section title="Project">
            <Row title={project?.name ?? ""} subtitle={project?.role.name} />
          </Section>
        )}

        <Section title="Appearance">
          {APPEARANCE.map((option) => (
            <Row
              key={option.value}
              title={option.label}
              checked={preference === option.value}
              chevron={false}
              onPress={() => {
                void Haptics.selectionAsync();
                setPreference(option.value);
              }}
            />
          ))}
        </Section>

        <Section title="About">
          <Row title="Version" value={Constants.expoConfig?.version ?? "1.0.0"} />
          <Row title="Server" value={host} />
        </Section>

        <Section>
          <Row title="Sign out" destructive chevron={false} onPress={confirmSignOut} />
        </Section>
      </ScrollView>
    </>
  );
}
