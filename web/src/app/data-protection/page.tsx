import type { Metadata } from "next";
import Link from "next/link";
import { Bullets, LegalPage } from "@/components/marketing/LegalPage";

export const metadata: Metadata = {
  title: "Data protection",
  description: "How SASA Compliance protects the personal data that project teams record in the platform.",
};

export default function DataProtectionPage() {
  return (
    <LegalPage
      eyebrow="Data protection"
      title="Protecting the people a project records."
      updated="21 November 2026"
      intro={
        <p>
          SASA Compliance holds information about stakeholders, project-affected persons, complainants and workers. This
          page explains who is responsible for that information and how the platform protects it.
        </p>
      }
      sections={[
        {
          id: "roles",
          title: "Who is responsible for the data",
          body: (
            <>
              <p>
                The organization that uses SASA Compliance for a project decides what is recorded about stakeholders,
                project-affected persons, complainants and others, and why. That organization is responsible for the
                data as the controller.
              </p>
              <p>
                SASA Compliance provides and operates the platform and processes that data on the organization&apos;s
                instructions, to run the service. We do not use project records for our own purposes and we do not sell
                personal data.
              </p>
            </>
          ),
        },
        {
          id: "law",
          title: "The law we work to",
          body: (
            <p>
              We are building SASA Compliance to support organizations in meeting their obligations under the Personal
              Data Protection Act, 2022 of Tanzania and its regulations, and the data protection laws that apply to the
              projects it serves.
            </p>
          ),
        },
        {
          id: "safeguards",
          title: "How the platform protects personal data",
          body: (
            <Bullets
              items={[
                "Access by role and by project. Each person sees only the projects they belong to and only what their role allows.",
                "Confidential and anonymous grievances. For a confidential case, a complainant's identity is sent only to the people handling it. For an anonymous case, no identity is stored. This is enforced by the server, not by hiding fields on screen.",
                "Sensitive categories, such as sexual exploitation and abuse, are visible only to their handling group.",
                "An audit trail records who changed what, and when. It cannot be edited.",
                "Connections to the platform are encrypted.",
                "Work saved on a phone or laptop for offline use is kept only on that device until it is sent, and it is removed when the person signs out.",
                "Where the platform offers an AI suggestion, a person reviews it before anything is written to a record.",
              ]}
            />
          ),
        },
        {
          id: "retention",
          title: "Keeping and removing records",
          body: (
            <>
              <p>
                Project records are archived rather than erased in normal use, so that the history of a grievance or a
                commitment remains available to the project and its auditors.
              </p>
              <p>
                Where a person has a legal right to have their information corrected or erased, the organization
                responsible for the project handles the request, and we support it in doing so.
              </p>
            </>
          ),
        },
        {
          id: "hosting",
          title: "Hosting and access",
          body: (
            <p>
              The platform runs on servers operated by our hosting provider. Access to the servers is limited to
              authorized personnel who need it to operate and support the service.
            </p>
          ),
        },
        {
          id: "incidents",
          title: "If something goes wrong",
          body: (
            <p>
              If we become aware of a breach affecting personal data held in the platform, we will inform the affected
              organizations without undue delay, so that they can meet their own notification duties.
            </p>
          ),
        },
        {
          id: "contact",
          title: "Questions",
          body: (
            <p>
              For a question about how personal data is handled, contact the organization responsible for the project,
              or contact us through the{" "}
              <Link href="/register-interest" className="font-medium text-brand-700 hover:underline">
                register interest form
              </Link>
              . See also our{" "}
              <Link href="/privacy" className="font-medium text-brand-700 hover:underline">
                privacy and website terms
              </Link>
              .
            </p>
          ),
        },
      ]}
    />
  );
}
