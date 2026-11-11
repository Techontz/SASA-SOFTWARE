<?php

namespace App\Domain\Incident;

use App\Models\Incident;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Notifications\SasaNotification;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The incident notification register: submit from the field, review, close.
 */
final class IncidentService
{
    public const EVENT_REPORTED = 'incident.reported';

    public function __construct(
        private readonly IncidentOptions $options,
    ) {}

    /** The reporter is always the signed-in user — never a value from the request. */
    public function report(Project $project, User $reporter, array $data): Incident
    {
        $incident = DB::transaction(function () use ($project, $reporter, $data) {
            $incident = new Incident;
            $incident->organisation_id = $project->organisation_id;
            $incident->project_id = $project->id;
            $incident->fill(collect($data)->only([
                'client_uuid', 'occurred_at', 'company', 'location_id', 'site_detail',
                'category', 'incident_type', 'actual_consequence', 'potential_consequence',
                'summary', 'contractor_manager', 'resident_engineer',
            ])->all());
            $incident->reported_by = $reporter->id;
            $incident->status = 'submitted';
            $incident->save();

            return $incident;
        });

        $this->notifyManagers($project, $incident, $reporter);

        return $incident->fresh(['reporter:id,name', 'location']);
    }

    public function update(Incident $incident, array $data): Incident
    {
        return DB::transaction(function () use ($incident, $data) {
            $incident->fill(collect($data)->only([
                'occurred_at', 'company', 'location_id', 'site_detail', 'category', 'incident_type',
                'actual_consequence', 'potential_consequence', 'summary',
                'contractor_manager', 'resident_engineer',
            ])->all());
            $incident->save();

            return $incident->fresh(['reporter:id,name', 'location']);
        });
    }

    /** submitted → under_review → closed. Closed is final. */
    public function changeStatus(Incident $incident, User $actor, string $status, ?string $note = null): Incident
    {
        if (! in_array($status, ['under_review', 'closed'], true)) {
            throw new DomainRuleException('An incident can be put under review or closed.', 'invalid_status');
        }

        if ($incident->status === 'closed') {
            throw new DomainRuleException("{$incident->reference} is already closed.", 'already_closed');
        }

        if ($incident->status === $status) {
            throw new DomainRuleException("{$incident->reference} is already under review.", 'unchanged_status');
        }

        return DB::transaction(function () use ($incident, $actor, $status, $note) {
            $before = $incident->status;

            $changes = ['status' => $status];

            if ($note !== null && trim($note) !== '') {
                $changes['review_note'] = trim($note);
            }

            if ($status === 'under_review' || $incident->reviewed_at === null) {
                $changes['reviewed_by'] = $actor->id;
                $changes['reviewed_at'] = now();
            }

            if ($status === 'closed') {
                $changes['closed_by'] = $actor->id;
                $changes['closed_at'] = now();
            }

            // The Auditable trait records the status change with before/after.
            $incident->forceFill($changes)->save();

            return $incident->fresh(['reporter:id,name', 'reviewer:id,name', 'closer:id,name', 'location']);
        });
    }

    public function archive(Incident $incident): void
    {
        $incident->archive();
    }

    /**
     * The register at a glance. Archived records are excluded.
     *
     * @return array<string,mixed>
     */
    public function summary(Project $project): array
    {
        $base = fn (): Builder => Incident::query()
            ->where('project_id', $project->id)
            ->whereNull('archived_at');

        $total = $base()->count();

        $byStatus = $base()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $byCategory = $base()->selectRaw('category, COUNT(*) as aggregate')->groupBy('category')->pluck('aggregate', 'category');
        $byActual = $base()->selectRaw('actual_consequence as level, COUNT(*) as aggregate')->groupBy('actual_consequence')->pluck('aggregate', 'level');
        $byPotential = $base()->selectRaw('potential_consequence as level, COUNT(*) as aggregate')->groupBy('potential_consequence')->pluck('aggregate', 'level');

        $nearMisses = (int) ($byCategory['near_miss'] ?? 0);
        $levels = $this->options->levels();

        return [
            'total' => $total,
            'open' => (int) ($byStatus['submitted'] ?? 0) + (int) ($byStatus['under_review'] ?? 0),
            'serious' => $base()->serious()->count(),
            'serious_open' => $base()->serious()->whereIn('status', ['submitted', 'under_review'])->count(),
            'last_30_days' => $base()->where('occurred_at', '>=', now()->subDays(30))->count(),
            'near_misses' => $nearMisses,
            'near_miss_ratio' => $total > 0 ? round($nearMisses / $total * 100, 1) : null,
            'by_status' => collect(Incident::STATUSES)->map(fn ($status) => [
                'key' => $status,
                'label' => ucfirst(str_replace('_', ' ', $status)),
                'count' => (int) ($byStatus[$status] ?? 0),
            ])->values()->all(),
            'by_category' => collect($this->options->categories($project))->map(fn ($category) => [
                'key' => $category['key'],
                'label' => $category['label'],
                'count' => (int) ($byCategory[$category['key']] ?? 0),
            ])->values()->all(),
            'by_actual_consequence' => collect($levels)->map(fn ($label, $level) => [
                'level' => (int) $level, 'label' => $label, 'count' => (int) ($byActual[$level] ?? 0),
            ])->values()->all(),
            'by_potential_consequence' => collect($levels)->map(fn ($label, $level) => [
                'level' => (int) $level, 'label' => $label, 'count' => (int) ($byPotential[$level] ?? 0),
            ])->values()->all(),
            'definitions' => [
                'serious' => sprintf(
                    'Potential consequence Level %d or above, or an incident type of Fatality or Permanent Disability.',
                    Incident::seriousLevel(),
                ),
                'near_miss_ratio' => 'Near misses as a share of all incidents reported (archived records excluded).',
                'last_30_days' => 'Incidents whose date of occurrence falls in the last 30 days.',
            ],
        ];
    }

    /**
     * Every active member whose role carries incident.manage hears about a new
     * report straight away, in the app. Serious ones are marked as such.
     */
    private function notifyManagers(Project $project, Incident $incident, User $reporter): void
    {
        try {
            $userIds = ProjectMembership::query()
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->whereHas('role.permissions', fn ($q) => $q->where('key', 'incident.manage'))
                ->pluck('user_id')
                ->reject(fn ($id) => (int) $id === (int) $reporter->id)
                ->unique()
                ->values()
                ->all();

            if ($userIds === []) {
                return;
            }

            $recipients = User::query()
                ->whereIn('id', $userIds)
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            $serious = $incident->isSerious();
            $type = $this->options->typeLabel($incident->incident_type);

            Notification::send($recipients, new SasaNotification(
                eventKey: self::EVENT_REPORTED,
                subject: ($serious ? 'SERIOUS incident ' : 'New incident ').$incident->reference,
                body: sprintf(
                    '%s reported %s: %s. Potential consequence: %s.%s',
                    $reporter->name,
                    $incident->reference,
                    $type,
                    $this->options->levelLabel($incident->potential_consequence),
                    $serious ? ' This incident is flagged serious and needs review now.' : '',
                ),
                payload: [
                    'reference' => $incident->reference,
                    'serious' => $serious,
                    'url' => "/incidents/{$incident->id}",
                ],
                channels: ['in_app'],
                severity: $serious ? 'danger' : 'info',
            ));
        } catch (\Throwable $e) {
            Log::error('incident.notify_failed', [
                'incident_id' => $incident->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
