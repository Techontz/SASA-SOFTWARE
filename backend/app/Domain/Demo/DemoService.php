<?php

namespace App\Domain\Demo;

use App\Domain\Audit\AuditLogger;
use App\Domain\Tenancy\ProjectFoundations;
use App\Mail\DemoInviteMail;
use App\Models\Demo;
use App\Models\Enquiry;
use App\Models\Organisation;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Demo workspaces, start to finish.
 *
 * One action gives a company that asked for a demo its own organisation and
 * project (with the default configuration every project starts with), sample
 * records, and an invitation for the person who asked: a user with the
 * project administrator role and a "set your password" link.
 *
 * The demo then runs until ends_at; expired or ended, its project refuses
 * every request (EnsureProjectContext asks DemoAccess). When the company signs
 * an agreement the demo converts and the workspace carries on as theirs.
 *
 * The Auditable trait records every row created or changed (organisation,
 * project, user, membership, demo, enquiry); demo.provisioned and
 * demo.invite_sent say what happened in one line.
 */
final class DemoService
{
    public function __construct(
        private readonly ProjectFoundations $foundations,
        private readonly DemoDataBuilder $builder,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{days?:int|null,sample_data?:bool|null,name?:string|null,email?:string|null,project_name?:string|null}  $data
     * @return array{demo:Demo,invite_url:string,email_sent:bool}
     */
    public function provision(Enquiry $enquiry, User $admin, array $data): array
    {
        $days = (int) ($data['days'] ?? config('sasa.demos.default_days', 14));
        $sample = (bool) ($data['sample_data'] ?? true);
        $name = trim((string) ($data['name'] ?? '')) ?: $enquiry->full_name;
        $email = strtolower(trim((string) ($data['email'] ?? '')) ?: $enquiry->email);
        $organisationName = trim((string) $enquiry->organization);
        $projectName = trim((string) ($data['project_name'] ?? '')) ?: "{$organisationName} demo";

        $demo = DB::transaction(function () use ($enquiry, $admin, $days, $sample, $name, $email, $organisationName, $projectName) {
            /** @var Enquiry $enquiry */
            $enquiry = Enquiry::query()->lockForUpdate()->findOrFail($enquiry->id);

            $this->assertNoActiveDemo($enquiry);
            $existing = $this->assertEmailAvailable($email);

            $organisation = new Organisation;
            $organisation->fill([
                'name' => $organisationName,
                'slug' => $this->uniqueSlug($organisationName),
                'country' => 'TZ',
                'timezone' => 'Africa/Dar_es_Salaam',
                'contact_email' => $email,
                'contact_phone' => $enquiry->phone,
                'status' => 'active',
            ]);
            $organisation->forceFill(['is_demo' => true])->save();

            $project = Project::create([
                'organisation_id' => $organisation->id,
                'name' => $projectName,
                'code' => $this->uniqueProjectCode(),
                'description' => 'A SASA demo workspace for '.$organisationName.'. The records in it are sample data.',
                'country' => 'TZ',
                'sector' => $enquiry->sector,
                'timezone' => 'Africa/Dar_es_Salaam',
                'status' => 'active',
                'starts_on' => today(),
                'fiscal_year_start_month' => 7,
            ]);

            $this->foundations->apply($project);
            $this->builder->locations($organisation->id, DemoDataBuilder::LOCATION_TREES['default']);

            $user = $this->inviteUser($existing, $organisation, $name, $email);
            $this->grantProjectAdmin($user, $project, $admin);

            if ($sample) {
                $this->builder->buildSample($project, $user);
            }

            $demo = new Demo;
            $demo->forceFill([
                'public_id' => (string) Str::ulid(),
                'enquiry_id' => $enquiry->id,
                'organisation_id' => $organisation->id,
                'project_id' => $project->id,
                'user_id' => $user->id,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => now()->addDays($days),
                'created_by' => $admin->id,
            ])->save();

            $enquiry->forceFill(['status' => 'contacted', 'handled_by' => $admin->id, 'handled_at' => now()])->save();

            $this->audit->record(
                action: 'demo.provisioned',
                entity: $demo,
                after: ['enquiry' => $enquiry->reference, 'project' => $project->code, 'user' => $email, 'days' => $days, 'sample_data' => $sample],
                summary: "Demo workspace {$project->code} given to {$organisationName} for {$days} days",
                projectId: $project->id,
                organisationId: $organisation->id,
            );

            return $demo;
        });

        return ['demo' => $this->load($demo)] + $this->invite($demo);
    }

    /** Add days from whichever is later, now or the current end. An expired or ended demo opens again. */
    public function extend(Demo $demo, int $days): Demo
    {
        return DB::transaction(function () use ($demo, $days) {
            /** @var Demo $demo */
            $demo = Demo::query()->lockForUpdate()->findOrFail($demo->id);
            $this->assertNotConverted($demo);

            $from = $demo->ends_at !== null && $demo->ends_at->isFuture() ? $demo->ends_at : now();
            $demo->forceFill([
                'ends_at' => $from->copy()->addDays($days),
                'status' => 'active',
                'ended_at' => null,
            ])->save();

            return $this->load($demo);
        });
    }

    public function end(Demo $demo): Demo
    {
        return DB::transaction(function () use ($demo) {
            /** @var Demo $demo */
            $demo = Demo::query()->lockForUpdate()->findOrFail($demo->id);
            $this->assertNotConverted($demo);

            if ($demo->status !== 'ended') {
                $demo->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            }

            return $this->load($demo);
        });
    }

    /** @return array{demo:Demo,invite_url:string,email_sent:bool} */
    public function resendInvite(Demo $demo): array
    {
        return ['demo' => $this->load($demo)] + $this->invite($demo);
    }

    /** Active demos whose end has passed become expired. Safe to run any number of times. */
    public function expireDue(): int
    {
        $count = 0;

        Demo::query()
            ->current()
            ->where('status', 'active')
            ->where('ends_at', '<=', now())
            ->orderBy('id')
            ->each(function (Demo $demo) use (&$count) {
                $demo->forceFill(['status' => 'expired'])->save();
                $count++;
            });

        return $count;
    }

    /**
     * The company signed an agreement: its demo becomes the real thing. Called
     * inside AgreementService's transaction. Returns the converted demo, if
     * the organisation had one.
     */
    public function convertOrganisation(int $organisationId): ?Demo
    {
        $organisation = Organisation::query()->lockForUpdate()->find($organisationId);

        if (! $organisation || ! $organisation->is_demo) {
            return null;
        }

        $converted = null;

        Demo::query()
            ->current()
            ->where('organisation_id', $organisationId)
            ->where('status', '!=', 'converted')
            ->lockForUpdate()
            ->orderBy('id')
            ->each(function (Demo $demo) use (&$converted) {
                $demo->forceFill(['status' => 'converted', 'ended_at' => $demo->ended_at ?? now()])->save();
                $converted = $demo;
            });

        $organisation->forceFill(['is_demo' => false])->save();

        return $converted;
    }

    // --------------------------------------------------------------- helpers

    /**
     * A fresh "set your password" token (replacing any earlier one) and, when
     * mail is configured, the invitation e-mail. A mail failure never fails
     * the request: the administrator still has the link.
     *
     * @return array{invite_url:string,email_sent:bool}
     */
    private function invite(Demo $demo): array
    {
        $user = $demo->user;
        $token = Password::broker('invites')->createToken($user);
        $url = rtrim((string) config('sasa.web_url'), '/').'/set-password?token='.urlencode($token).'&email='.urlencode($user->email).'&welcome=1';

        $sent = false;

        if (config('sasa.demos.invite_email')) {
            try {
                Mail::to($user->email)->queue(new DemoInviteMail(
                    name: $user->name,
                    organisation: $demo->organisation->name,
                    email: $user->email,
                    inviteUrl: $url,
                    endsOn: $demo->ends_at->copy()->timezone('Africa/Dar_es_Salaam')->format('j F Y'),
                    linkHours: (int) config('sasa.demos.invite_hours', 72),
                ));
                $sent = true;
            } catch (\Throwable $e) {
                Log::warning('Could not queue the demo invitation e-mail.', [
                    'demo' => $demo->public_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->audit->record(
            action: 'demo.invite_sent',
            entity: $demo,
            after: ['email' => $user->email, 'email_sent' => $sent],
            summary: $sent ? "Invitation e-mailed to {$user->email}" : "Invitation link created for {$user->email} (not e-mailed)",
            projectId: $demo->project_id,
            organisationId: $demo->organisation_id,
        );

        return ['invite_url' => $url, 'email_sent' => $sent];
    }

    private function load(Demo $demo): Demo
    {
        return $demo->fresh(['organisation:id,name', 'project:id,name,code', 'user:id,name,email', 'enquiry:id,public_id,reference']);
    }

    private function assertNoActiveDemo(Enquiry $enquiry): void
    {
        $active = Demo::query()->current()->where('enquiry_id', $enquiry->id)->where('status', 'active')
            ->get()->first(fn (Demo $demo) => $demo->effectiveStatus() === 'active');

        if ($active) {
            throw new DomainRuleException(
                'This enquiry already has a demo running. Extend it, or end it first.',
                'demo_exists',
                422,
            );
        }
    }

    /** An existing account may be reused only if it lives in a demo workspace. */
    private function assertEmailAvailable(string $email): ?User
    {
        $user = User::query()->with('organisation')->where('email', $email)->lockForUpdate()->first();

        if (! $user) {
            return null;
        }

        $inDemo = $user->organisation !== null && $user->organisation->is_demo;

        if ($user->is_system_admin || ! $inDemo) {
            $message = 'That e-mail address already belongs to a SASA account in another organisation. Use a different address.';

            throw new DomainRuleException($message, 'email_in_use', 422, ['errors' => ['email' => [$message]]]);
        }

        return $user;
    }

    private function inviteUser(?User $existing, Organisation $organisation, string $name, string $email): User
    {
        if ($existing) {
            // They may already have a password from an earlier demo: keep it,
            // and keep them able to sign in.
            $existing->forceFill([
                'organisation_id' => $organisation->id,
                'name' => $name,
                'status' => $existing->status === 'active' ? 'active' : 'invited',
            ])->save();

            return $existing;
        }

        $user = new User;
        $user->fill([
            'organisation_id' => $organisation->id,
            'name' => $name,
            'email' => $email,
            // Nobody knows it: the person sets their own through the invite link.
            'password' => Str::password(40),
            'locale' => 'en',
            'status' => 'invited',
        ]);
        $user->save();

        return $user;
    }

    private function grantProjectAdmin(User $user, Project $project, User $admin): void
    {
        $role = Role::whereNull('organisation_id')->where('key', 'project_admin')->firstOrFail();

        // The new workspace is the one the app opens on.
        ProjectMembership::where('user_id', $user->id)->where('is_default', true)->update(['is_default' => false]);

        $membership = new ProjectMembership;
        $membership->fill([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            // Restricted handling too, so the sample's SEA/SH and ethics cases can be opened.
            'handling_groups' => ['general', 'restricted_handling'],
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
            'created_by' => $admin->id,
        ]);
        $membership->save();
    }

    private function assertNotConverted(Demo $demo): void
    {
        if ($demo->status === 'converted') {
            throw new DomainRuleException(
                'This demo has become a customer workspace, so it no longer has a demo period.',
                'demo_converted',
                422,
            );
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organisation';

        do {
            $slug = Str::limit($base, 60, '').'-demo-'.Str::lower(Str::random(5));
        } while (Organisation::where('slug', $slug)->exists());

        return $slug;
    }

    /** DEMO-7KQ2: four characters that cannot be misread (no 0/O, 1/I). */
    private function uniqueProjectCode(): string
    {
        $prefix = (string) config('sasa.demos.project_code_prefix', 'DEMO');
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $suffix = '';
            for ($i = 0; $i < 4; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = $prefix.'-'.$suffix;
        } while (Project::where('code', $code)->exists());

        return $code;
    }
}
