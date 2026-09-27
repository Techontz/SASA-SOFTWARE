<?php

namespace App\Domain\Search;

use App\Domain\Grievance\GrievanceVisibility;
use App\Models\Commitment;
use App\Models\Engagement;
use App\Models\Grievance;
use App\Models\Stakeholder;

/**
 * Global search across stakeholders, engagements, grievances and commitments.
 *
 * It respects permissions absolutely: a confidential case never appears in the
 * results of a user outside its handling group, and an anonymous complainant
 * is unsearchable by definition.
 */
final class SearchService
{
    public function __construct(private readonly GrievanceVisibility $visibility) {}

    public function search(int $projectId, string $term, int $limitPerGroup = 5): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return ['query' => $term, 'groups' => []];
        }

        return [
            'query' => $term,
            'groups' => array_values(array_filter([
                $this->group('stakeholders', 'Stakeholders', $this->stakeholders($projectId, $term, $limitPerGroup)),
                $this->group('grievances', 'Grievances', $this->grievances($projectId, $term, $limitPerGroup)),
                $this->group('engagements', 'Engagements', $this->engagements($projectId, $term, $limitPerGroup)),
                $this->group('commitments', 'Commitments', $this->commitments($projectId, $term, $limitPerGroup)),
            ], fn ($group) => $group['results'] !== [])),
        ];
    }

    private function group(string $key, string $label, array $results): array
    {
        return ['key' => $key, 'label' => $label, 'results' => $results];
    }

    private function stakeholders(int $projectId, string $term, int $limit): array
    {
        return Stakeholder::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->search($term)
            ->limit($limit)
            ->get(['id', 'reference', 'name', 'type', 'priority', 'status', 'village', 'ward', 'district'])
            ->map(fn (Stakeholder $s) => [
                'id' => $s->id,
                'reference' => $s->reference,
                'title' => $s->name,
                'subtitle' => ucfirst(str_replace('_', ' ', $s->type)),
                'status' => $s->status,
                'badge' => $s->priority,
                'location' => $s->displayLocation(),
                'href' => "/stakeholders/{$s->id}",
            ])->all();
    }

    private function grievances(int $projectId, string $term, int $limit): array
    {
        $query = Grievance::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->search($term);

        $this->visibility->scopeVisible($query);

        return $query->limit($limit)
            ->get(['id', 'reference', 'title', 'status', 'severity', 'confidentiality', 'location_text', 'is_restricted', 'handling_groups'])
            ->map(fn (Grievance $g) => [
                'id' => $g->id,
                'reference' => $g->reference,
                'title' => $g->title,
                'subtitle' => $g->severity ? $g->severityLabel() : 'Severity not yet assessed',
                'status' => $g->status,
                'badge' => $g->confidentiality,
                'location' => $g->location_text,
                'href' => "/grievances/{$g->id}",
            ])->all();
    }

    private function engagements(int $projectId, string $term, int $limit): array
    {
        return Engagement::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->search($term)
            ->limit($limit)
            ->get(['id', 'reference', 'topic', 'held_at', 'method', 'location_text', 'planned_vs_actual'])
            ->map(fn (Engagement $e) => [
                'id' => $e->id,
                'reference' => $e->reference,
                'title' => $e->topic,
                'subtitle' => $e->held_at?->toFormattedDateString(),
                'status' => $e->planned_vs_actual,
                'badge' => $e->method,
                'location' => $e->location_text,
                'href' => "/engagements/{$e->id}",
            ])->all();
    }

    private function commitments(int $projectId, string $term, int $limit): array
    {
        $like = '%'.str_replace('%', '\%', $term).'%';

        return Commitment::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->where('commitment_text', 'like', $like)->orWhere('reference', 'like', $like))
            ->limit($limit)
            ->get(['id', 'reference', 'commitment_text', 'status', 'due_date', 'risk_level'])
            ->map(fn (Commitment $c) => [
                'id' => $c->id,
                'reference' => $c->reference,
                'title' => mb_substr($c->commitment_text, 0, 90),
                'subtitle' => $c->due_date ? 'Due '.$c->due_date->toFormattedDateString() : 'No due date',
                'status' => $c->status,
                'badge' => $c->risk_level,
                'location' => null,
                'href' => "/commitments/{$c->id}",
            ])->all();
    }
}
