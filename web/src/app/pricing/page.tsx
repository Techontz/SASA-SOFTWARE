import { redirect } from "next/navigation";

/* Pricing is quoted per project: the old address leads to the quote form. */
export default function PricingPage() {
  redirect("/request-quote");
}
