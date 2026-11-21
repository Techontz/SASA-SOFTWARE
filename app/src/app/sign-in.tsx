import * as Haptics from "expo-haptics";
import { useNetworkState } from "expo-network";
import { useEffect, useRef, useState, type ComponentRef } from "react";
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, TextInput, View } from "react-native";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import { Mark } from "@/components/Logo";
import { Button, Notice, T } from "@/components/ui";
import { API_BASE_URL, ApiError } from "@/lib/api";
import { useQa } from "@/lib/qa";
import { useSession } from "@/lib/session";
import { radius, space, type, useTheme } from "@/lib/theme";

export default function SignIn() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const network = useNetworkState();
  const { signIn } = useSession();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<{ title: string; message?: string } | null>(null);
  const passwordRef = useRef<ComponentRef<typeof TextInput>>(null);
  const qa = useQa();

  const offline = network.isConnected === false || network.isInternetReachable === false;
  const host = API_BASE_URL.replace(/^https?:\/\//, "").split("/")[0];

  const submit = async (emailValue = email, passwordValue = password) => {
    if (!emailValue.trim() || !passwordValue) {
      setError({ title: "Enter your email and password." });
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning);
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await signIn(emailValue.trim(), passwordValue);
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success);
    } catch (caught) {
      void Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error);
      if (caught instanceof ApiError && caught.isOffline) {
        setError({ title: "You are offline", message: "Signing in for the first time on this phone needs a connection." });
      } else if (caught instanceof ApiError && caught.isUnreachable) {
        setError({ title: "SASA did not answer", message: `We could not reach ${host}. Try again in a moment.` });
      } else if (caught instanceof ApiError && (caught.status === 401 || caught.status === 422)) {
        setError({ title: "That email and password do not match.", message: "Check them and try again." });
      } else {
        setError({ title: "We could not sign you in", message: caught instanceof Error ? caught.message : undefined });
      }
    } finally {
      setBusy(false);
    }
  };

  // Development only: lets a script sign in on the simulator (see lib/qa.ts).
  useEffect(() => {
    const qaEmail = qa.email;
    if (!__DEV__ || !qaEmail) return;
    void Promise.resolve().then(() => {
      setEmail(qaEmail);
      setPassword(qa.password ?? "");
      if (qa.mode === "save") void submit(qaEmail, qa.password ?? "");
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [qa.email, qa.password, qa.mode]);

  return (
    <KeyboardAvoidingView style={{ flex: 1, backgroundColor: theme.canvas }} behavior={Platform.OS === "ios" ? "padding" : undefined}>
      <ScrollView
        contentContainerStyle={[styles.content, { paddingTop: insets.top + 72, paddingBottom: insets.bottom + space.xl }]}
        keyboardShouldPersistTaps="handled"
        keyboardDismissMode="interactive"
      >
        <Mark size={64} />
        <T variant="largeTitle" style={{ marginTop: space.xxl }}>
          Sign in to SASA
        </T>
        <T tone="secondary" style={{ marginTop: space.s }}>
          Use the email and password your project administrator gave you.
        </T>

        {offline ? (
          <View style={{ marginHorizontal: -space.l }}>
            <Notice tone="warning" icon="offline" title="You are offline" message="Signing in for the first time on this phone needs a connection." />
          </View>
        ) : null}

        <View style={[styles.card, { backgroundColor: theme.card, marginTop: space.xxl }]}>
          <TextInput
            value={email}
            onChangeText={(value) => {
              setEmail(value);
              setError(null);
            }}
            placeholder="Email"
            placeholderTextColor={theme.placeholder}
            keyboardType="email-address"
            autoCapitalize="none"
            autoComplete="email"
            textContentType="username"
            autoCorrect={false}
            returnKeyType="next"
            onSubmitEditing={() => passwordRef.current?.focus()}
            accessibilityLabel="Email"
            selectionColor={theme.accent}
            style={[type.body, styles.input, { color: theme.text }]}
          />
          <View style={[styles.separator, { backgroundColor: theme.separator }]} />
          <TextInput
            ref={passwordRef}
            value={password}
            onChangeText={(value) => {
              setPassword(value);
              setError(null);
            }}
            placeholder="Password"
            placeholderTextColor={theme.placeholder}
            secureTextEntry
            autoComplete="current-password"
            textContentType="password"
            returnKeyType="go"
            onSubmitEditing={() => void submit()}
            accessibilityLabel="Password"
            selectionColor={theme.accent}
            style={[type.body, styles.input, { color: theme.text }]}
          />
        </View>

        {error ? (
          <View style={{ marginHorizontal: -space.l }}>
            <Notice tone="danger" icon="error" title={error.title} message={error.message} />
          </View>
        ) : null}

        <Button title="Sign in" onPress={() => void submit()} loading={busy} style={{ marginTop: space.xl }} />

        <View style={{ flex: 1 }} />
        <T variant="footnote" tone="tertiary" align="center" style={{ marginTop: space.xxxl }}>
          Connected to {host}
        </T>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  content: { flexGrow: 1, paddingHorizontal: space.xl },
  card: { borderRadius: radius.card, overflow: "hidden", borderCurve: "continuous" },
  input: { height: 52, paddingHorizontal: space.l },
  separator: { height: StyleSheet.hairlineWidth, marginLeft: space.l },
});
