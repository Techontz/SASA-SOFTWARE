import type { ExpoConfig } from "expo/config";

/**
 * The API the app talks to. Set EXPO_PUBLIC_API_URL to point a build at
 * another server; the default is the local QA API.
 */
const config: ExpoConfig = {
  name: "SASA",
  slug: "sasa",
  scheme: "sasa",
  version: "1.0.0",
  orientation: "portrait",
  icon: "./assets/images/icon.png",
  userInterfaceStyle: "automatic",
  backgroundColor: "#f5f5f7",
  ios: {
    bundleIdentifier: "com.sasacompliance.app",
    supportsTablet: true,
    config: { usesNonExemptEncryption: false },
    infoPlist: {
      // The local QA API is plain http. Production is https and needs none of this.
      NSAppTransportSecurity: { NSAllowsLocalNetworking: true },
    },
  },
  android: {
    package: "com.sasacompliance.app",
    adaptiveIcon: {
      backgroundColor: "#ffffff",
      foregroundImage: "./assets/images/android-icon-foreground.png",
      backgroundImage: "./assets/images/android-icon-background.png",
      monochromeImage: "./assets/images/android-icon-monochrome.png",
    },
    predictiveBackGestureEnabled: false,
  },
  web: { favicon: "./assets/images/favicon.png" },
  plugins: [
    "expo-router",
    "expo-secure-store",
    "@react-native-community/datetimepicker",
    [
      "expo-splash-screen",
      {
        image: "./assets/images/splash-icon.png",
        imageWidth: 120,
        resizeMode: "contain",
        backgroundColor: "#162236",
        dark: { image: "./assets/images/splash-icon.png", backgroundColor: "#162236" },
      },
    ],
  ],
  experiments: { typedRoutes: false },
};

export default config;
