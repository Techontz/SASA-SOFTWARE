import type { PapSectionKey } from "@/types/pap";

/**
 * The child sections of a PAP record (Parts B–J and M), described once so the
 * detail page can list, add and edit any of them with the same component.
 * Option lists name a key in /paps/options. Derived fields are shown, never
 * typed — the server computes them.
 */

export type PapFieldKind = "text" | "textarea" | "number" | "money" | "date" | "select" | "boolean";

export interface PapFieldDefinition {
  key: string;
  label: string;
  kind: PapFieldKind;
  options?: string;
  required?: boolean;
  hint?: string;
  unit?: string;
  /** Absent from the API without pap.view_sensitive. */
  sensitive?: boolean;
  /** Takes a full row in the form. */
  wide?: boolean;
}

export interface PapDerivedDefinition {
  key: string;
  label: string;
  kind: "money" | "percent" | "number";
  unit?: string;
  explain: string;
}

export interface PapSectionDefinition {
  key: PapSectionKey;
  part: string;
  label: string;
  singular: string;
  description: string;
  permission: "pap.manage" | "pap.manage_payments";
  /** The headline of a record card. */
  titleField: string;
  /** The field whose label is shown as the record's status badge. */
  statusField?: string;
  /** Fields shown on the record card (beyond title and status). */
  summary: string[];
  fields: PapFieldDefinition[];
  derived: PapDerivedDefinition[];
  hasVisits?: boolean;
  empty: string;
}

export const PAP_SECTIONS: PapSectionDefinition[] = [
  {
    key: "household-surveys",
    part: "B",
    label: "Household survey",
    singular: "household survey",
    description: "The household's socio-economic baseline — the yardstick livelihood restoration is measured against.",
    permission: "pap.manage",
    titleField: "household_head",
    statusField: "poverty_category",
    summary: ["survey_date", "household_size", "primary_occupation", "monthly_household_income", "access_to_water"],
    fields: [
      { key: "household_id", label: "Household ID", kind: "text", hint: "Defaults to the PAP's household." },
      { key: "survey_date", label: "Survey date", kind: "date" },
      { key: "household_head", label: "Household head", kind: "text" },
      { key: "household_size", label: "Household size", kind: "number" },
      { key: "number_of_adults", label: "Number of adults", kind: "number" },
      { key: "number_of_children", label: "Number of children", kind: "number" },
      { key: "education_level", label: "Education level", kind: "select", options: "education_levels" },
      { key: "primary_occupation", label: "Primary occupation", kind: "text" },
      { key: "secondary_occupation", label: "Secondary occupation", kind: "text" },
      { key: "monthly_household_income", label: "Monthly household income", kind: "money" },
      { key: "main_income_source", label: "Main income source", kind: "text" },
      { key: "asset_ownership_score", label: "Asset ownership score", kind: "number" },
      { key: "poverty_category", label: "Poverty category", kind: "select", options: "poverty_categories" },
      { key: "livestock_owned", label: "Livestock owned", kind: "textarea", wide: true },
      { key: "access_to_electricity", label: "Access to electricity", kind: "select", options: "electricity_access" },
      { key: "access_to_water", label: "Access to water", kind: "select", options: "water_access" },
      { key: "access_to_sanitation", label: "Access to sanitation", kind: "select", options: "sanitation_access" },
      { key: "comments", label: "Comments", kind: "textarea", wide: true },
    ],
    derived: [],
    empty: "No household survey yet. Record the baseline before compensation is paid.",
  },
  {
    key: "land",
    part: "C",
    label: "Land",
    singular: "land parcel",
    description: "Every plot the project affects, how it is held, and how much of it is taken.",
    permission: "pap.manage",
    titleField: "plot_number",
    statusField: "land_status",
    summary: ["ownership_type", "land_use", "total_plot_size_ha", "affected_area_ha", "percentage_affected"],
    fields: [
      { key: "plot_number", label: "Plot number", kind: "text", required: true },
      { key: "village", label: "Village", kind: "text" },
      { key: "land_use", label: "Land use", kind: "select", options: "land_uses" },
      { key: "ownership_type", label: "Ownership type", kind: "select", options: "land_ownership_types" },
      { key: "ccro_number", label: "CCRO number", kind: "text" },
      { key: "title_number", label: "Title number", kind: "text" },
      { key: "total_plot_size_ha", label: "Total plot size", kind: "number", unit: "ha" },
      { key: "affected_area_ha", label: "Affected area", kind: "number", unit: "ha", hint: "Cannot be more than the whole plot." },
      { key: "gps_easting", label: "GPS easting", kind: "number" },
      { key: "gps_northing", label: "GPS northing", kind: "number" },
      { key: "map_reference", label: "Map reference", kind: "text" },
      { key: "land_status", label: "Land status", kind: "select", options: "land_statuses" },
      { key: "remarks", label: "Remarks", kind: "textarea", wide: true },
    ],
    derived: [
      { key: "percentage_affected", label: "Percentage affected", kind: "percent", explain: "Affected area ÷ total plot size." },
    ],
    empty: "No land recorded for this PAP.",
  },
  {
    key: "assets",
    part: "D",
    label: "Assets",
    singular: "asset",
    description: "Structures, fences, water sources and other assets on affected land.",
    permission: "pap.manage",
    titleField: "asset_type",
    statusField: "affected_status",
    summary: ["asset_category", "area", "quantity", "condition"],
    fields: [
      { key: "asset_category", label: "Asset category", kind: "select", options: "asset_categories", required: true },
      { key: "asset_type", label: "Asset type", kind: "text" },
      { key: "description", label: "Description", kind: "textarea", wide: true },
      { key: "length", label: "Length", kind: "number", unit: "m" },
      { key: "width", label: "Width", kind: "number", unit: "m" },
      { key: "area", label: "Area", kind: "number", unit: "m²", hint: "Calculated from length × width when both are given." },
      { key: "quantity", label: "Quantity", kind: "number" },
      { key: "unit", label: "Unit", kind: "select", options: "asset_units" },
      { key: "condition", label: "Condition", kind: "select", options: "asset_conditions" },
      { key: "affected_status", label: "Affected status", kind: "select", options: "asset_affected_statuses" },
      { key: "photo_reference", label: "Photo reference", kind: "text" },
      { key: "remarks", label: "Remarks", kind: "textarea", wide: true },
    ],
    derived: [],
    empty: "No assets recorded for this PAP.",
  },
  {
    key: "crops",
    part: "E",
    label: "Crops & trees",
    singular: "crop or tree",
    description: "Standing crops and trees, counted and rated for compensation.",
    permission: "pap.manage",
    titleField: "crop_type",
    statusField: "productive_status",
    summary: ["plot_number", "number_of_plants", "area_cultivated", "unit_rate", "compensation_amount"],
    fields: [
      { key: "plot_number", label: "Plot number", kind: "text" },
      { key: "crop_type", label: "Crop type", kind: "text", required: true },
      { key: "number_of_plants", label: "Number of plants", kind: "number" },
      { key: "area_cultivated", label: "Area cultivated", kind: "number", unit: "ha" },
      { key: "tree_species", label: "Tree species", kind: "text" },
      { key: "tree_age", label: "Tree age", kind: "number", unit: "years" },
      { key: "productive_status", label: "Productive status", kind: "select", options: "productive_statuses" },
      { key: "rate_basis", label: "Rate is", kind: "select", options: "crop_rate_bases" },
      { key: "unit_rate", label: "Unit rate", kind: "money" },
      { key: "remarks", label: "Remarks", kind: "textarea", wide: true },
    ],
    derived: [
      { key: "compensation_amount", label: "Compensation amount", kind: "money", explain: "Number of plants × unit rate, or hectares × unit rate when the rate is per hectare." },
    ],
    empty: "No crops or trees recorded for this PAP.",
  },
  {
    key: "valuations",
    part: "F",
    label: "Valuation",
    singular: "valuation",
    description: "The valuer's assessment, component by component. Only an approved valuation counts towards the budget.",
    permission: "pap.manage",
    titleField: "valuation_report_number",
    statusField: "approval_status",
    summary: ["valuation_date", "valuer_name", "total_compensation_value", "approval_date"],
    fields: [
      { key: "valuation_date", label: "Valuation date", kind: "date" },
      { key: "valuer_name", label: "Valuer name", kind: "text" },
      { key: "valuation_report_number", label: "Valuation report number", kind: "text" },
      { key: "land_value", label: "Land value", kind: "money" },
      { key: "structure_value", label: "Structure value", kind: "money" },
      { key: "crop_value", label: "Crop value", kind: "money" },
      { key: "tree_value", label: "Tree value", kind: "money" },
      { key: "business_loss", label: "Business loss", kind: "money" },
      { key: "disturbance_allowance", label: "Disturbance allowance", kind: "money" },
      { key: "transport_allowance", label: "Transport allowance", kind: "money" },
      { key: "accommodation_allowance", label: "Accommodation allowance", kind: "money" },
      { key: "loss_of_profit_allowance", label: "Loss of profit allowance", kind: "money" },
      { key: "other_allowances", label: "Other allowances", kind: "money" },
      { key: "approval_status", label: "Approval status", kind: "select", options: "valuation_approval_statuses" },
      { key: "approval_date", label: "Approval date", kind: "date" },
    ],
    derived: [
      { key: "total_compensation_value", label: "Total compensation value", kind: "money", explain: "The sum of every value and allowance above." },
    ],
    empty: "No valuation recorded yet.",
  },
  {
    key: "entitlements",
    part: "G",
    label: "Entitlements",
    singular: "entitlement",
    description: "What the PAP is entitled to under the resettlement action plan — cash and in kind.",
    permission: "pap.manage",
    titleField: "entitlement_type",
    statusField: "entitlement_status",
    summary: ["quantity", "unit_rate", "value", "approval_date"],
    fields: [
      { key: "entitlement_type", label: "Entitlement type", kind: "select", options: "entitlement_types", required: true },
      { key: "description", label: "Description", kind: "textarea", wide: true },
      { key: "quantity", label: "Quantity", kind: "number" },
      { key: "unit_rate", label: "Unit rate", kind: "money" },
      { key: "approval_date", label: "Approval date", kind: "date" },
      { key: "entitlement_status", label: "Entitlement status", kind: "select", options: "entitlement_statuses" },
      { key: "remarks", label: "Remarks", kind: "textarea", wide: true },
    ],
    derived: [{ key: "value", label: "Value", kind: "money", explain: "Quantity × unit rate." }],
    empty: "No entitlements recorded yet.",
  },
  {
    key: "payments",
    part: "H",
    label: "Payments",
    singular: "payment",
    description: "Compensation approved and paid. Recording a payment needs the payments permission.",
    permission: "pap.manage_payments",
    titleField: "compensation_agreement_number",
    statusField: "payment_status",
    summary: ["amount_approved", "amount_paid", "outstanding_amount", "payment_date", "payment_method"],
    fields: [
      { key: "compensation_agreement_number", label: "Compensation agreement number", kind: "text" },
      { key: "bank_name", label: "Bank name", kind: "text" },
      { key: "bank_account_number", label: "Bank account number", kind: "text", sensitive: true },
      { key: "mobile_money_number", label: "Mobile money number", kind: "text", sensitive: true },
      { key: "amount_approved", label: "Amount approved", kind: "money", required: true },
      { key: "amount_paid", label: "Amount paid", kind: "money", hint: "Cannot be more than the amount approved." },
      { key: "payment_date", label: "Payment date", kind: "date" },
      { key: "payment_method", label: "Payment method", kind: "select", options: "payment_methods" },
      { key: "receipt_number", label: "Receipt number", kind: "text" },
      { key: "acknowledgement_signed", label: "Acknowledgement signed", kind: "boolean" },
      { key: "witness_name", label: "Witness name", kind: "text" },
      {
        key: "payment_status",
        label: "Payment status",
        kind: "select",
        options: "payment_statuses",
        hint: "Left blank, it follows the money. Choose On hold or Disputed to set it deliberately.",
      },
      { key: "comments", label: "Comments", kind: "textarea", wide: true },
    ],
    derived: [{ key: "outstanding_amount", label: "Outstanding", kind: "money", explain: "Amount approved − amount paid." }],
    empty: "No payments recorded yet.",
  },
  {
    key: "relocations",
    part: "I",
    label: "Relocation",
    singular: "relocation",
    description: "Physical relocation: replacement plot and house, moving date and assistance.",
    permission: "pap.manage",
    titleField: "replacement_house_number",
    statusField: "relocation_status",
    summary: ["replacement_plot_number", "house_handover_date", "moving_date", "moving_assistance_provided"],
    fields: [
      { key: "relocation_required", label: "Relocation required", kind: "boolean" },
      { key: "replacement_plot_number", label: "Replacement plot number", kind: "text" },
      { key: "replacement_house_number", label: "Replacement house number", kind: "text" },
      { key: "house_handover_date", label: "House handover date", kind: "date" },
      { key: "moving_date", label: "Moving date", kind: "date" },
      { key: "moving_assistance_provided", label: "Moving assistance provided", kind: "boolean" },
      { key: "transit_assistance_provided", label: "Transit assistance provided", kind: "boolean" },
      { key: "relocation_status", label: "Relocation status", kind: "select", options: "relocation_statuses" },
      { key: "remarks", label: "Remarks", kind: "textarea", wide: true },
    ],
    derived: [],
    empty: "No relocation recorded. Most PAPs lose land, not a home.",
  },
  {
    key: "livelihoods",
    part: "J",
    label: "Livelihood",
    singular: "livelihood enrolment",
    description: "Livelihood restoration: the programme, the support given, and monitoring visits until income is restored.",
    permission: "pap.manage",
    titleField: "livelihood_program",
    statusField: "programme_status",
    summary: ["income_source_lost", "date_enrolled", "grant_amount", "income_restored"],
    fields: [
      { key: "income_source_lost", label: "Income source lost", kind: "text" },
      { key: "livelihood_program", label: "Livelihood programme", kind: "text", required: true },
      { key: "training_provided", label: "Training provided", kind: "textarea", wide: true },
      { key: "grant_amount", label: "Grant amount", kind: "money" },
      { key: "business_startup_support", label: "Business start-up support", kind: "textarea" },
      { key: "agricultural_support", label: "Agricultural support", kind: "textarea" },
      { key: "date_enrolled", label: "Date enrolled", kind: "date" },
      { key: "date_completed", label: "Date completed", kind: "date" },
      { key: "income_restored", label: "Income restored", kind: "select", options: "income_restored_options" },
      { key: "programme_status", label: "Status", kind: "select", options: "livelihood_statuses" },
    ],
    derived: [],
    hasVisits: true,
    empty: "Not enrolled in a livelihood restoration programme.",
  },
  {
    key: "documents",
    part: "M",
    label: "Documents",
    singular: "document",
    description: "Identity, tenure, valuation and payment documents held for this PAP. Attach scans under Files.",
    permission: "pap.manage",
    titleField: "document_type",
    summary: ["document_number", "issue_date", "storage_location", "verified_by", "verification_date"],
    fields: [
      { key: "document_type", label: "Document type", kind: "select", options: "document_types", required: true },
      { key: "document_number", label: "Document number", kind: "text", hint: "For a NIDA card or bank confirmation, only viewers with sensitive access see this." },
      { key: "issue_date", label: "Issue date", kind: "date" },
      { key: "expiry_date", label: "Expiry date", kind: "date" },
      { key: "digital_file_name", label: "Digital file name", kind: "text" },
      { key: "storage_location", label: "Storage location", kind: "text" },
      { key: "verified_by", label: "Verified by", kind: "text" },
      { key: "verification_date", label: "Verification date", kind: "date" },
    ],
    derived: [],
    empty: "No documents registered.",
  },
];

export function papSection(key: PapSectionKey): PapSectionDefinition {
  const section = PAP_SECTIONS.find((candidate) => candidate.key === key);
  if (!section) throw new Error(`Unknown PAP section ${key}`);
  return section;
}
