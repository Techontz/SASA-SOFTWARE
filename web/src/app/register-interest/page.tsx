import type { Metadata } from "next";
import { EnquiryPage } from "@/components/marketing/EnquiryPage";

export const metadata: Metadata = {
  title: "Register interest",
  description: "Register your interest in SASA Compliance and hear about platform availability and pilot opportunities.",
};

export default function RegisterInterestPage() {
  return (
    <EnquiryPage
      type="interest"
      eyebrow="Register interest"
      title="Register your interest in SASA Compliance."
      intro="Tell us about your organization and project needs. We will contact you about platform availability and relevant pilot opportunities."
      disclaimer="Submitting this form does not create a software account or confirm pilot access."
    />
  );
}
