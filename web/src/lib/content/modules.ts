/*
 * The platform's modules, in the client's own words. One source for the
 * Platform page, the home page summary and the enquiry forms' module list.
 */

export type ModuleKey =
  | "stakeholder_register"
  | "stakeholder_engagement"
  | "grievance_management"
  | "pap_register"
  | "incident_notification"
  | "eshs_assurance"
  | "dashboards_reporting";

export interface PlatformModule {
  key: ModuleKey;
  name: string;
  summary: string;
  points: string[];
  note?: string;
  /** A real screen from the application. */
  screen: string;
  path: string;
}

export const PLATFORM_MODULES: PlatformModule[] = [
  {
    key: "stakeholder_register",
    name: "Stakeholder Register",
    summary:
      "Organize the people, groups and institutions connected to a project. Keep their details, locations and engagement priorities in one structured register.",
    points: [
      "Maintain records for individuals, households, groups and institutions.",
      "Record relevant categories, locations and stakeholder information.",
      "Identify engagement priorities and relevant vulnerability information.",
      "Connect relevant records to engagement and grievance follow-up as these functions become available.",
    ],
    screen: "/landing/screens/stakeholders.webp",
    path: "stakeholders",
  },
  {
    key: "stakeholder_engagement",
    name: "Stakeholder Engagement",
    summary:
      "Plan consultations, record discussions and follow up concerns and commitments. Help teams maintain a clear history of engagement activities.",
    points: [
      "Plan engagement activities and record completed consultations.",
      "Capture discussion points, attendance information and concerns.",
      "Track commitments, responsible people and due dates.",
      "Review planned activities alongside completed work.",
    ],
    screen: "/landing/screens/engagements.webp",
    path: "engagements",
  },
  {
    key: "grievance_management",
    name: "Grievance Management",
    summary:
      "Follow complaints from initial recording through investigation, resolution and closure. Keep responsibilities and case histories clear. People remain responsible for investigation findings and resolution decisions.",
    points: [
      "Record and classify cases and assign responsible officers.",
      "Capture acknowledgement, investigation and resolution information.",
      "Track deadlines, progress and closure evidence.",
    ],
    screen: "/landing/screens/grievances.webp",
    path: "grievances",
  },
  {
    key: "pap_register",
    name: "PAP Master Register",
    summary:
      "PAP means Project-Affected Person. This module brings affected-person and household records together with information about land, assets, entitlements and compensation.",
    points: [
      "Maintain household, land and asset information.",
      "Track valuations, entitlements and compensation records.",
      "Follow relocation and livelihood restoration activities.",
    ],
    note: "The module only records compensation information. It does not transfer compensation payments.",
    screen: "/landing/screens/paps.webp",
    path: "paps",
  },
  {
    key: "incident_notification",
    name: "Incident Notification",
    summary:
      "Record incidents consistently and capture information needed for internal follow-up. Include the location, relevant company, incident category, actual and potential consequences, reporting details and supporting evidence.",
    points: [
      "Cover environmental, health and safety, security, social and material-loss incident categories.",
      "Record the severity of actual and potential consequences.",
      "Identify reporting parties and relevant project contacts.",
      "Allow supporting photos, videos or documents inside the software when this is ready.",
    ],
    note: "This incident notification module does not replace emergency responses or any separate reporting required by law.",
    screen: "/landing/screens/incidents.webp",
    path: "incidents",
  },
  {
    key: "eshs_assurance",
    name: "ESHS Contractor Assurance",
    summary:
      "ESHS means Environmental, Social, Health and Safety. This module helps project teams record findings, follow contractor actions and verify closure.",
    points: [
      "The project client or supervision team raises the findings.",
      "The finding identifies the contractor, location, category, risk and required action.",
      "The contractor provides an update and supporting closure evidence.",
      "Only authorized client or supervision personnel verify and close the findings.",
    ],
    screen: "/landing/screens/eshs.webp",
    path: "eshs",
  },
  {
    key: "dashboards_reporting",
    name: "Dashboards, Analytics and Reporting",
    summary:
      "Bring project information together so teams can review progress and outstanding work, using the reports and summaries available in the software.",
    points: [
      "Review engagement activity and grievance follow-up.",
      "Summarize affected-person records and compensation information where available.",
      "Follow outstanding and overdue contractor actions.",
      "Prepare reports using the functions included in the software.",
    ],
    screen: "/landing/screens/analytics.webp",
    path: "analytics",
  },
];

export const SECTORS = [
  { value: "infrastructure", label: "Infrastructure" },
  { value: "mining", label: "Mining" },
  { value: "energy", label: "Energy" },
  { value: "other", label: "Other" },
] as const;
