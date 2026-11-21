import type { Metadata } from "next";
import Link from "next/link";
import { Bullets, LegalPage } from "@/components/marketing/LegalPage";

export const metadata: Metadata = {
  title: "Privacy and website terms",
  description: "What SASA Compliance collects through this website, why, and the terms for using the website.",
};

export default function PrivacyPage() {
  return (
    <LegalPage
      eyebrow="Privacy and website terms"
      title="Privacy and website terms."
      updated="21 November 2026"
      intro={
        <p>
          This page explains what we collect when you use this website, how we use it, and the terms that apply to the
          website. How the platform protects project records is explained on our{" "}
          <Link href="/data-protection" className="font-medium text-brand-700 hover:underline">
            data protection
          </Link>{" "}
          page.
        </p>
      }
      sections={[
        {
          id: "collect",
          title: "What we collect",
          body: (
            <>
              <p>When you register interest or book a demonstration, we collect:</p>
              <Bullets
                items={[
                  "your full name, e-mail address and organization;",
                  "your sector, and your phone number if you give it;",
                  "the modules you are interested in and any message you write.",
                ]}
              />
              <p>
                When an organization subscribes, we collect its name and the contact person&apos;s name, e-mail address
                and phone number. Payments are processed by our payment provider. Card details are entered on the
                provider&apos;s own secure page; we never see or store a card number.
              </p>
            </>
          ),
        },
        {
          id: "use",
          title: "How we use it",
          body: (
            <Bullets
              items={[
                "to reply to you, arrange a demonstration and tell you about platform availability and pilot opportunities you asked about;",
                "to set up and administer a subscription;",
                "to keep the website and platform secure and working.",
              ]}
            />
          ),
        },
        {
          id: "sharing",
          title: "Who we share it with",
          body: (
            <p>
              We do not sell your information. We share it only with the service providers who help us run the website
              and platform, such as our hosting provider, our payment provider and our e-mail provider, and only so far
              as they need it to provide their service, or where the law requires it.
            </p>
          ),
        },
        {
          id: "storage",
          title: "Cookies and storage",
          body: (
            <>
              <p>
                This website does not use advertising or analytics cookies. It stores a few items in your browser that
                it needs to work:
              </p>
              <Bullets
                items={[
                  "your light or dark appearance choice;",
                  "your sign-in session and chosen project, if you use the platform;",
                  "work saved for offline use, until it is sent. It is removed when you sign out.",
                ]}
              />
            </>
          ),
        },
        {
          id: "keeping",
          title: "How long we keep it",
          body: (
            <p>
              We keep enquiry details for as long as we need them to respond to you and follow up the conversation you
              started, and subscription records for as long as the subscription lasts and the law requires afterwards.
            </p>
          ),
        },
        {
          id: "rights",
          title: "Your rights",
          body: (
            <p>
              Under the Personal Data Protection Act, 2022 of Tanzania you may ask to see the personal information we
              hold about you, ask us to correct it, or ask us to stop using it or delete it where the law allows. Contact
              us through the{" "}
              <Link href="/register-interest" className="font-medium text-brand-700 hover:underline">
                register interest form
              </Link>{" "}
              and tell us what you would like us to do.
            </p>
          ),
        },
        {
          id: "terms",
          title: "Using this website",
          body: (
            <>
              <p>
                SASA Compliance is being developed. The descriptions on this website explain the platform as we are
                building it; features may change before and after launch, and nothing on the website is an offer or a
                commitment to supply. Registering interest or requesting a demonstration does not create a software
                account, confirm pilot access or confirm a booking.
              </p>
              <p>
                The screens shown on this website use demonstration data. The website, its text, design, logo and
                screens belong to SASA Compliance and may not be copied for commercial use without our permission.
              </p>
              <p>
                We take care to keep the website accurate and available, but we cannot promise that it will always be
                error-free or uninterrupted. Use of the platform itself is governed by the agreement with the
                organization that subscribes.
              </p>
              <p>These terms are governed by the laws of the United Republic of Tanzania.</p>
            </>
          ),
        },
        {
          id: "changes",
          title: "Changes to this page",
          body: <p>We will update this page when our practices change, and the date at the top will show when.</p>,
        },
      ]}
    />
  );
}
