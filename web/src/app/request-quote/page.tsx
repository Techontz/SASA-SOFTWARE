import type { Metadata } from "next";
import { EnquiryPage } from "@/components/marketing/EnquiryPage";

export const metadata: Metadata = {
  title: "Request a quote",
  description: "Request a SASA Compliance quote shaped around your project and the modules your team needs.",
};

export default function RequestQuotePage() {
  return (
    <EnquiryPage
      type="quote"
      eyebrow="Request a quote"
      title="Request a quote for your project."
      intro="Every project is different. Tell us about your organization, your project and the modules your team needs, and we will prepare a quote for you."
      disclaimer="Submitting this form does not create a software account or commit you to a purchase."
    />
  );
}
