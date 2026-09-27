# Configuration

> The rule: anything that differs between projects is configuration; anything that is the
> same everywhere is code.

Three layers, most specific wins:

```
project row   →   organisation row   →   config/sasa.php
```

Every change is written to the audit trail. Organisation defaults exist precisely so that
cross-project reporting stays possible.

## What can be changed, and where

| Setting | Screen | Effect |
| --- | --- | --- |
| **Priority weighting** | Configuration → Priority | Weights per dimension (0 disables it), the value of High/Medium/Low, and the band thresholds. Applies to new and recalculated assessments. |
| **Grievance categories** | Configuration → Categories | Add, rename, retire. A retired category disappears from new cases and stays on historical ones. Set a default severity, mark a category restricted and name its handling group. |
| **SLA standards** | Configuration → SLA standards | Per clock (acknowledgement, investigation, resolution), per severity, per category, per country. Number **and** unit — working days, working hours or calendar days. Reminder thresholds as percentages. |
| **Working calendar** | Configuration → Working calendar | The working week, working hours, timezone and the public holiday list. Everything the SLA engine counts against. |
| **Notification rules** | Configuration → Notifications | Per event: who is told, on which channels, under what conditions, and in what words. |
| **Lists** | Configuration → Lists and fields | Stakeholder types, engagement methods, project phases, languages, vulnerability categories. |
| **Disaggregation dimensions** | Configuration → Lists and fields | Which optional demographics this project collects. Sensitive ones are off by default. |
| **Minimum cell size** | `sasa.disaggregation.minimum_cell_size` | Default 5. Cross-tab cells below it are suppressed. |
| **AI voice** | `sasa.voice` | Call cap, behaviour at the cap (`soft`/`hard`/`structured_only`), warning point, extension length, consent requirement, languages. |
| **Commitment reminders** | `sasa.commitments.reminder_offsets` | Default `[-14, -3, 0, 7, 30]` days relative to the due date. |
| **Report definitions** | Reports | Template, filters, period, formats, schedule and distribution list. |

## Environment

Backend, `backend/.env`:

```dotenv
APP_URL=https://api.sasa.example
SASA_WEB_URL=https://sasa.example
SASA_CORS_ORIGINS="https://sasa.example"

DB_CONNECTION=mysql
DB_HOST=…  DB_DATABASE=sasa  DB_USERNAME=…  DB_PASSWORD=…

QUEUE_CONNECTION=redis        # database works, redis is better under load
CACHE_STORE=redis
FILESYSTEM_DISK=s3            # private bucket; never public-read

SASA_TOKEN_TTL_MINUTES=720          # field users
SASA_ADMIN_TOKEN_TTL_MINUTES=240    # administrators, deliberately shorter
SASA_REQUIRE_MFA_FOR_ADMINS=true

SASA_ATTACHMENT_MAX_MB=25
SASA_SIGNED_URL_TTL_MINUTES=10
SASA_VIRUS_SCAN_ENABLED=true
SASA_VIRUS_SCAN_COMMAND="clamdscan --no-summary --stdout"

SASA_AI_PROVIDER=null         # null | anthropic — null is a real keyword classifier
SASA_AI_MODEL=claude-sonnet-5
ANTHROPIC_API_KEY=

SASA_VOICE_PROVIDER=null
SASA_VOICE_WEBHOOK_SECRET=…   # HMAC-SHA256 over the raw body
SASA_VOICE_SOFT_CAP_SECONDS=120

SASA_BACKUP_PATH=/var/backups/sasa
SASA_BACKUP_RETENTION_DAYS=30
```

Frontend, `web/.env.local`:

```dotenv
NEXT_PUBLIC_API_URL=https://api.sasa.example/api/v1
NEXT_PUBLIC_SHOW_DEMO_LOGINS=false     # never true in production
```

## Adding a provider

The application is written against interfaces, so a vendor is a binding, not a rewrite:

```php
// app/Providers/AppServiceProvider.php
$this->app->bind(AiProvider::class, fn () => match (config('sasa.ai.provider')) {
    'anthropic', 'claude' => new ClaudeAiProvider,
    default               => new NullAiProvider,   // deterministic, no API key
});

$this->app->bind(VoiceProvider::class, fn () => match (config('sasa.voice.provider')) {
    default => new NullVoiceProvider,              // full conversation, no telephony account
});
```

A telephony provider implements `verifyWebhook`, `normalise` and `respond`. Nothing above
`app/Domain/Voice` changes.
