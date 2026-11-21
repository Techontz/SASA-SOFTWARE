<?php

namespace App\Domain\Enquiry;

use App\Mail\NewEnquiryMail;
use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Website enquiries: "Register interest" and "Book a demo". The Auditable
 * trait records enquiry.created and enquiry.status_changed / .updated.
 */
final class EnquiryService
{
    /**
     * Store a real enquiry and tell the team. Returns null when the honeypot
     * was filled: the caller answers exactly as if it had been stored.
     *
     * @param  array<string,mixed>  $data
     */
    public function submit(array $data, ?string $ip): ?Enquiry
    {
        if (trim((string) ($data['website'] ?? '')) !== '') {
            return null;
        }

        $enquiry = DB::transaction(function () use ($data, $ip) {
            $enquiry = new Enquiry;
            $enquiry->fill([
                'type' => $data['type'],
                'full_name' => trim((string) $data['full_name']),
                'email' => strtolower(trim((string) $data['email'])),
                'organization' => trim((string) $data['organization']),
                'sector' => $data['sector'],
                'phone' => $this->nullableText($data['phone'] ?? null),
                'modules' => self::normaliseModules($data['modules'] ?? null),
                'details' => $data['type'] === 'quote' ? self::quoteDetails($data) : null,
                'message' => $this->nullableText($data['message'] ?? null),
            ]);
            $enquiry->public_id = (string) Str::ulid();
            $enquiry->reference = $this->nextReference();
            $enquiry->status = 'new';
            $enquiry->ip_hash = $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
            $enquiry->save();

            return $enquiry;
        });

        $this->notifyTeam($enquiry);

        return $enquiry;
    }

    /**
     * An administrator marks an enquiry contacted or closed, with notes.
     *
     * @param  array<string,mixed>  $data
     */
    public function handle(Enquiry $enquiry, User $handler, array $data): Enquiry
    {
        return DB::transaction(function () use ($enquiry, $handler, $data) {
            $changes = ['status' => $data['status'], 'handled_by' => $handler->id, 'handled_at' => now()];

            if (array_key_exists('admin_notes', $data)) {
                $changes['admin_notes'] = $this->nullableText($data['admin_notes']);
            }

            $enquiry->forceFill($changes)->save();

            return $enquiry->fresh(['handler:id,name']);
        });
    }

    /**
     * Known keys only, once each, in catalogue order. "Not sure" alongside
     * real choices means not sure, so it wins.
     *
     * @return array<int,string>|null
     */
    public static function normaliseModules(mixed $modules): ?array
    {
        if (! is_array($modules) || $modules === []) {
            return null;
        }

        $chosen = array_values(array_intersect(Enquiry::MODULES, $modules));

        if (in_array('not_sure', $chosen, true)) {
            return ['not_sure'];
        }

        return $chosen === [] ? null : $chosen;
    }

    /**
     * The qualifying answers of a quote request, every key present (null when
     * an optional question was skipped).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,int|string|null>
     */
    public static function quoteDetails(array $data): array
    {
        $location = trim((string) ($data['project_location'] ?? ''));

        return [
            'company_size' => (string) $data['company_size'],
            'projects_count' => (int) $data['projects_count'],
            'users_count' => (int) $data['users_count'],
            'project_location' => $location === '' ? null : $location,
            'project_stage' => $data['project_stage'] ?? null,
            'start_timeframe' => $data['start_timeframe'] ?? null,
        ];
    }

    /** A failed mail never fails the enquiry: it is already stored. */
    private function notifyTeam(Enquiry $enquiry): void
    {
        $recipients = self::recipients();

        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->queue(new NewEnquiryMail($enquiry));
        } catch (\Throwable $e) {
            Log::warning('Could not queue the new-enquiry e-mail.', [
                'reference' => $enquiry->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int,string> */
    public static function recipients(): array
    {
        $raw = config('sasa.enquiries.notify');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_unique(array_filter(
            array_map(fn ($address) => strtolower(trim((string) $address)), $list),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /** ENQ-2026-0001, numbered per calendar year, under a row lock. */
    private function nextReference(): string
    {
        $config = (array) config('sasa.enquiries.reference', []);
        $prefix = ($config['prefix'] ?? 'ENQ').'-'.now()->format('Y').'-';
        $pad = (int) ($config['pad'] ?? 4);

        $last = Enquiry::query()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('reference');

        $next = $last ? ((int) substr((string) $last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
