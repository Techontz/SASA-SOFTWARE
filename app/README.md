# SASA mobile app

The iPhone and Android app for SASA Compliance. Field officers, community relations
officers and grievance officers use it to see what needs them today, log grievances and
engagements, add stakeholders, and look up any record on the project.

It talks to the same API as the web app (`backend/`), with the same rules: the project comes
from the token plus the `X-Sasa-Project` header, the API decides what each role may see, and
a tab or action the role cannot use is simply not shown.

## What is in it

| Tab | What it does |
| --- | --- |
| **Today** | Greeting, the project, quick actions, and "Needs attention" from `/dashboard/landing`. Each item opens the filtered list behind it. |
| **Grievances** | Search, Open / Overdue / All, status and severity badges with words, the resolution clock ("4d left", "Overdue"), confidential and anonymous markers. A case shows its details, the complainant (or why their identity is withheld) and a timeline. |
| **Stakeholders** | Search, All / High priority / Vulnerable. A record shows contact details, the assessment and its history. |
| **Engagements** | Search, All / This month / Unplanned. A record shows attendance, details and the stakeholders present. |
| **Account** | Profile, project switcher (when there is more than one), appearance (match the phone, light, dark), sign out. |

Create flows open as sheets: **Log a grievance** (confidentiality is asked first), **Log an
engagement** and **Add a stakeholder**. Their mandatory fields come from the project's own
configuration (`/sync/bootstrap` → `required_fields`), so the phone asks for exactly what
the API will insist on.

**Saving is honest.** There is no on-phone queue yet. A sheet only says "Saved to SASA" when
the server has the record. If the phone is offline or the server does not answer, it says
"Not saved", keeps everything that was typed, and the person can try again.

## Run it on the simulator

You need Node 20+ and Xcode (for the iOS simulator).

```bash
cd app
npm install
npx expo start --ios          # boots the simulator and opens the app in Expo Go
```

The app talks to `http://localhost:8031/api/v1` by default (the local QA API). To use another
API, set `EXPO_PUBLIC_API_URL` before starting:

```bash
EXPO_PUBLIC_API_URL=http://localhost:8010/api/v1 npx expo start --ios
```

Demo accounts on a seeded database all use the password `password`: `field@sasa.test`,
`grievance@sasa.test`, `cro@sasa.test`, `executive@sasa.test`, `admin@sasa.test`.

On the Android emulator the host machine is `10.0.2.2`, not `localhost`:
`EXPO_PUBLIC_API_URL=http://10.0.2.2:8031/api/v1 npx expo start --android`.

## Run it on a real phone

1. Install **Expo Go** from the App Store or Google Play.
2. Point the app at an API the phone can reach. `localhost` is the phone itself, so use
   production or your computer's network address:

   ```bash
   EXPO_PUBLIC_API_URL=https://api.sasacompliance.com/api/v1 npx expo start
   ```

3. Scan the QR code in the terminal (Camera app on iPhone, Expo Go on Android). The phone
   and the computer must be on the same network, or add `--tunnel`.

## Build for the App Store and Google Play

Store binaries are built in the cloud with EAS. `eas.json` already has three profiles:

| Profile | For | API |
| --- | --- | --- |
| `development` | an iOS simulator build | local QA API |
| `preview` | an installable test build shared by link | production |
| `production` | the stores | production |

```bash
npx eas-cli@latest login                      # an Expo account
npx eas-cli@latest init                        # links the project, writes the projectId
npx eas-cli@latest build --platform ios --profile production
npx eas-cli@latest build --platform android --profile production
npx eas-cli@latest submit --platform ios       # to App Store Connect / TestFlight
npx eas-cli@latest submit --platform android   # to Google Play
```

What the owner needs before the first store release:

- An **Apple Developer Program** membership (USD 99 a year) and an App Store Connect app
  record for the bundle id `com.sasacompliance.app`. EAS creates the certificates.
- A **Google Play Console** account (USD 25 once), the app created with package
  `com.sasacompliance.app`, and a service account key for `eas submit`.
- Store listing: screenshots, description, a privacy policy URL, and the data-safety /
  privacy answers (the app handles personal data of complainants and stakeholders).

## Checks

```bash
npm run typecheck      # strict TypeScript, no `any`
npm run doctor         # expo-doctor
npm run icons          # re-render the icon, adaptive icon and splash from the SASA mark
```

## How it is put together

```
src/app/            routes (expo-router): sign-in, the five tabs, the three create sheets
src/app/(tabs)/*/   each tab is a native stack; record screens are re-exported into every
                    tab so Back always returns where you came from
src/screens/        the list and record screens those routes share
src/components/     grouped lists, rows, badges, buttons, form rows, option sheets, icons
src/lib/            API client, session, queries, theme, formatting, form helpers
tools/              the icon generator
```

- **Navigation**: native tab bar (`expo-router/native-tabs`) and native stacks with large
  titles and the system search field. Create flows are page sheets.
- **Data**: `@tanstack/react-query`; every query key includes the project, so switching
  project never shows another project's records.
- **Session**: the token lives in the keychain (`expo-secure-store`). The profile is cached
  there too, so the app opens with no signal; the API re-checks every permission anyway.
- **Theme**: `src/lib/theme.ts` holds every colour in both themes. Dark surfaces are the
  brand navy `#162236`, never black. Appearance can be overridden in Account; the override
  goes through `Appearance`, so native headers, tab bar and pickers follow it.
- **Icons**: SF Symbols on iOS and Material Symbols on Android through `expo-symbols`.

### Simulator QA hooks (development only)

A script cannot tap the simulator, so in development builds a screen reads a `qa` deep-link
parameter (see `src/lib/qa.ts`). Release builds strip it (`__DEV__` is false).

```bash
B="exp://127.0.0.1:8081/--"
xcrun simctl openurl booted "$B/sign-in?qa_email=field@sasa.test&qa_password=password&qa=save"
xcrun simctl openurl booted "$B/new-grievance?qa=empty"      # show the missing-field state
xcrun simctl openurl booted "$B/new-grievance?qa=save"       # fill with sample data and save
xcrun simctl openurl booted "$B/grievances/grievance/31?qa_scroll=900"
xcrun simctl ui booted appearance dark
xcrun simctl io booted screenshot today-dark.png
```
