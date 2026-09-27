# Working on SASA

Read [README.md](README.md) first, then [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## The rules this codebase holds itself to

1. **Tenant scope comes from the token.** Never trust an `organisation_id` or `project_id` in
   a request body. `TenantContext` is the only source; `EnsureProjectContext` is the only
   thing that fills it.
2. **Confidentiality is enforced in the API resource.** If a viewer may not see complainant
   identity, the keys are *absent* from the response — never null, never hidden with CSS.
3. **Nothing is hard-deleted.** "Delete" sets `archived_at` and writes an audit event. Audit
   rows are append-only: `save()` on an existing row and `delete()` both throw.
4. **The AI proposes; a person decides.** Nothing an AI produces is written into a record's
   own columns. It goes to `ai_suggestions` and waits for a human review.
5. **Anything that differs between projects is configuration.** If you are about to add a
   constant that one client would want different, put it in `config/sasa.php` and let the
   configuration registry override it.
6. **Never tell a user their work is saved when it is only on the device.** The sync engine
   returns `saved: "server" | "device"`; the UI must say which.
7. **Controllers stay thin.** Validate, authorise, call one domain service, return a resource.
8. **No `any`.** TypeScript is strict; `npm run typecheck` must stay clean.
9. **Colour is a token, in two themes.** Never a hex value in a component. The
   theme flips `var(--color-*)`, so `text-ink-500` works in both without being
   touched — but a *solid fill carrying white text* must use `bg-primary` /
   `bg-accent-solid` / `bg-danger-solid`, never a ramp step, or it stops being
   legible in one of the themes. See [docs/DESIGN.md](docs/DESIGN.md).
10. **Status is never colour alone.** Every badge carries a word.

## Where to add things

| You are adding… | It goes in… |
| --- | --- |
| Business logic | `backend/app/Domain/<Module>/` — a service or an engine, not a controller |
| A new endpoint | `routes/api.php` + a thin controller + a form request + a resource |
| A permission | `app/Domain/Identity/PermissionCatalogue.php`, then re-run `PermissionSeeder` |
| A metric | `app/Domain/Dashboard/MetricDefinitions.php` — with its definition, or it does not exist |
| A UI primitive | `web/src/components/ui/` — check it is not already there |
| Offline behaviour | `web/src/lib/offline/` — through the `LocalStore` interface, never IndexedDB directly |
| A colour, radius or shadow | `web/src/app/globals.css` `@theme` — never inline, and add the dark value too |
| A chart colour | `web/src/lib/chartTheme.ts` — both themes, then re-run the palette validator |
| A loading state | `Scanner` for waiting on work, a skeleton for waiting on a shape |

## Before you call something done

```bash
cd backend && vendor/bin/phpunit          # 176 tests
cd web && npm run typecheck && npm test   # strict TS + 65 tests
cd web && npm run lint                    # must be 0 errors
cd web && node tools/contrast.mjs         # every colour pairing, both themes, WCAG AA
cd web && npm run build                   # the production build must pass

# Every screen, both themes, both viewports: console errors, sideways scroll,
# and the contrast of what actually rendered.
node tools/qa.mjs <account> desktop light
node tools/qa.mjs <account> desktop dark
node tools/qa.mjs <account> mobile light
node tools/qa.mjs <account> mobile dark

npx playwright test                       # 77 end-to-end tests, both viewports
```

The Playwright offline spec needs a **real production build** — the service
worker does not register in development:

```bash
pkill -f 'next dev'        # `npm run dev` and `npm start` share port 3010
rm -rf .next && npm run build
npx next start --port 3010
```

Watch for this one: if a `next dev` server is already holding port 3010,
`next start` fails to bind and *the whole suite silently runs against dev*. The
offline test is the one that notices, because the service worker is absent.
