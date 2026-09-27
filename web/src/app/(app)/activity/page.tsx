"use client";

import { Suspense } from "react";
import AuditPage from "../audit/page";

/** "Recent activity" and the audit trail are the same record set. */
export default function ActivityPage() {
  return (
    <Suspense>
      <AuditPage />
    </Suspense>
  );
}
