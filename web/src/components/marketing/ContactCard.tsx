"use client";

import { Mail, MessageCircle, Phone } from "lucide-react";
import { usePublicContact } from "@/lib/api/enquiries";

/** How to reach the team directly. Shows only the channels that are set up. */
export function ContactCard() {
  const { data } = usePublicContact();
  const contact = data?.data;
  if (!contact || (!contact.email && !contact.phone && !contact.whatsapp)) return null;

  const whatsapp = contact.whatsapp?.replace(/[^\d]/g, "");

  return (
    <div className="mt-8 rounded-2xl bg-surface p-5 ring-1 ring-hairline">
      <p className="text-sm font-semibold text-ink-900">Prefer to talk to us directly?</p>
      <ul className="mt-3 space-y-2.5 text-[0.9375rem]">
        {contact.email ? (
          <li>
            <a href={`mailto:${contact.email}`} className="flex items-center gap-3 text-ink-800 hover:text-brand-700">
              <span className="grid h-8 w-8 place-items-center rounded-full bg-brand-50 text-brand-600">
                <Mail className="h-4 w-4" aria-hidden />
              </span>
              {contact.email}
            </a>
          </li>
        ) : null}
        {contact.phone ? (
          <li>
            <a href={`tel:${contact.phone.replace(/\s/g, "")}`} className="flex items-center gap-3 text-ink-800 hover:text-brand-700">
              <span className="grid h-8 w-8 place-items-center rounded-full bg-brand-50 text-brand-600">
                <Phone className="h-4 w-4" aria-hidden />
              </span>
              {contact.phone}
            </a>
          </li>
        ) : null}
        {whatsapp ? (
          <li>
            <a
              href={`https://wa.me/${whatsapp}`}
              target="_blank"
              rel="noopener noreferrer"
              className="flex items-center gap-3 text-ink-800 hover:text-brand-700"
            >
              <span className="grid h-8 w-8 place-items-center rounded-full bg-success-50 text-success-700">
                <MessageCircle className="h-4 w-4" aria-hidden />
              </span>
              WhatsApp {contact.whatsapp}
            </a>
          </li>
        ) : null}
      </ul>
    </div>
  );
}
