<?php

namespace App\Domain\Dashboard;

use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filter set shared by every dashboard: reporting period, cascading
 * location, category, severity, channel and owner.
 */
final class DashboardFilters
{
    public function __construct(
        public readonly int $projectId,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?int $locationId = null,
        public readonly ?int $categoryId = null,
        public readonly ?int $severity = null,
        public readonly ?string $channel = null,
        public readonly ?int $ownerId = null,
        public readonly ?string $projectPhase = null,
    ) {}

    public static function fromRequest(int $projectId, array $input): self
    {
        $to = isset($input['to']) ? CarbonImmutable::parse($input['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = isset($input['from'])
            ? CarbonImmutable::parse($input['from'])->startOfDay()
            : $to->subMonths(3)->startOfDay();

        return new self(
            projectId: $projectId,
            from: $from,
            to: $to,
            locationId: isset($input['location_id']) ? (int) $input['location_id'] : null,
            categoryId: isset($input['category_id']) ? (int) $input['category_id'] : null,
            severity: isset($input['severity']) ? (int) $input['severity'] : null,
            channel: $input['channel'] ?? null,
            ownerId: isset($input['owner_id']) ? (int) $input['owner_id'] : null,
            projectPhase: $input['project_phase'] ?? null,
        );
    }

    /** @return array<int,int>|null Location ids at or below the selected one. */
    public function locationIds(): ?array
    {
        if (! $this->locationId) {
            return null;
        }

        static $cache = [];

        return $cache[$this->locationId] ??= Location::find($this->locationId)?->descendantIds() ?? [$this->locationId];
    }

    public function applyLocation(Builder $query, string $column = 'location_id'): Builder
    {
        $ids = $this->locationIds();

        return $ids ? $query->whereIn($column, $ids) : $query;
    }

    /**
     * The grievance-shaped filters, in one place, so a report generated with a
     * filter set contains exactly the records the dashboard showed for it.
     */
    public function applyGrievanceFilters(Builder $query): Builder
    {
        $this->applyLocation($query);

        return $query
            ->when($this->categoryId, fn ($q) => $q->where(fn ($w) => $w
                ->where('category_id', $this->categoryId)
                ->orWhere('subcategory_id', $this->categoryId)))
            ->when($this->severity, fn ($q) => $q->where('severity', $this->severity))
            ->when($this->channel, fn ($q) => $q->where('channel', $this->channel))
            ->when($this->ownerId, fn ($q) => $q->where('assigned_to_id', $this->ownerId));
    }

    public function label(): string
    {
        return $this->from->format('j M Y').' — '.$this->to->format('j M Y');
    }

    public function days(): int
    {
        return max(1, (int) $this->from->diffInDays($this->to));
    }

    /** The equivalent window immediately before this one, for trend deltas. */
    public function previousPeriod(): self
    {
        $length = $this->from->diffInDays($this->to);

        return new self(
            projectId: $this->projectId,
            from: $this->from->subDays($length + 1),
            to: $this->from->subDay(),
            locationId: $this->locationId,
            categoryId: $this->categoryId,
            severity: $this->severity,
            channel: $this->channel,
            ownerId: $this->ownerId,
            projectPhase: $this->projectPhase,
        );
    }

    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'location_id' => $this->locationId,
            'category_id' => $this->categoryId,
            'severity' => $this->severity,
            'channel' => $this->channel,
            'owner_id' => $this->ownerId,
            'project_phase' => $this->projectPhase,
            'label' => $this->label(),
        ];
    }
}
