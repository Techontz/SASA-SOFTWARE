import type { Metadata } from "next";
import { EnquiryPage } from "@/components/marketing/EnquiryPage";

export const metadata: Metadata = {
  title: "Book a demo",
  description: "Book a SASA Compliance demonstration around the workflows your team wants to explore.",
};

export default function BookDemoPage() {
  return (
    <EnquiryPage
      type="demo"
      eyebrow="Book a demo"
      title="Book a SASA Compliance demonstration."
      intro="Tell us about your organization and the workflows you want to explore. Our team will contact you to arrange a suitable time."
      disclaimer="Submitting this form does not confirm a booking."
    />
  );
}
