/* Subscriptions, plans and payments: the shapes the billing API returns. */

export type Currency = "USD" | "TZS";
export type BillingPeriod = "monthly" | "yearly";
export type PaymentMethod = "mobile_money" | "card";
export type PaymentStatus = "pending" | "succeeded" | "failed" | "cancelled";
export type SubscriptionStatus =
  | "pending_payment"
  | "paid"
  | "quote_requested"
  | "active"
  | "rejected"
  | "cancelled";

export interface Plan {
  id: number;
  code: string;
  name: string;
  tagline: string | null;
  description: string | null;
  price_usd: number | null;
  price_tzs: number | null;
  billing_period: BillingPeriod;
  features: string[];
  limits: { projects: number | null; users: number | null };
  is_featured: boolean;
  is_custom_quote: boolean;
  sort_order: number;
  archived_at?: string | null;
}

export interface Payment {
  public_id: string;
  method: PaymentMethod;
  status: PaymentStatus;
  amount: number;
  currency: Currency;
  phone: string | null;
  checkout_url: string | null;
  failure_reason: string | null;
  created_at: string;
  paid_at: string | null;
}

export interface SubscriptionRequest {
  public_id: string;
  reference: string;
  status: SubscriptionStatus;
  currency: Currency;
  amount: number | null;
  plan: Plan;
  organisation_name: string;
  contact_name: string;
  email: string;
  phone: string;
  country?: string | null;
  message?: string | null;
  admin_notes?: string | null;
  created_at: string;
  latest_payment: Payment | null;
  payments?: Payment[];
  /** What each method will actually charge. Snippe settles in TZS only, so a
      USD-priced request is charged its shilling price. Null once paid. */
  charge_options?: {
    mobile_money: { amount: number; currency: Currency } | null;
    card: { amount: number; currency: Currency } | null;
  } | null;
}

export interface PlanInput {
  code: string;
  name: string;
  tagline: string | null;
  description: string | null;
  price_usd: number | null;
  price_tzs: number | null;
  billing_period: BillingPeriod;
  features: string[];
  limits: { projects: number | null; users: number | null };
  is_featured: boolean;
  is_custom_quote: boolean;
  sort_order: number;
}

export interface SubscriptionInput {
  plan_id: number;
  currency: Currency;
  organisation_name: string;
  contact_name: string;
  email: string;
  phone: string;
  country?: string;
  message?: string;
}
