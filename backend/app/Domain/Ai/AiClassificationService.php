<?php

namespace App\Domain\Ai;

use App\Domain\Audit\AuditLogger;
use App\Models\AiSuggestion;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Support\DomainRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Stores AI proposals as suggestions and applies them only when a person says
 * so. This is what makes classification accuracy measurable and keeps
 * accountability with the officer.
 */
final class AiClassificationService
{
    public function __construct(
        private readonly AiProvider $provider,
        private readonly AuditLogger $audit,
    ) {}

    public function suggestFor(Grievance $grievance): ?AiSuggestion
    {
        $categories = GrievanceCategory::query()
            ->forProject($grievance->project_id)
            ->selectable()
            ->topLevel()
            ->with(['subcategories' => fn ($q) => $q->selectable()])
            ->get()
            ->map(fn (GrievanceCategory $category) => [
                'id' => $category->id,
                'key' => $category->key,
                'name' => $category->name,
                'subcategories' => $category->subcategories
                    ->map(fn ($sub) => ['id' => $sub->id, 'key' => $sub->key, 'name' => $sub->name])->all(),
            ])->all();

        $result = $this->provider->classify([
            'title' => $grievance->title,
            'description' => $grievance->description,
            'language' => $grievance->complainant_language,
            'channel' => $grievance->channel,
            'categories' => $categories,
        ]);

        if (! $result->isUsable()) {
            return null;
        }

        $suggestion = AiSuggestion::updateOrCreate(
            [
                'subject_type' => $grievance->getMorphClass(),
                'subject_id' => $grievance->id,
                'kind' => 'classification',
            ],
            [
                'organisation_id' => $grievance->organisation_id,
                'project_id' => $grievance->project_id,
                'suggestion' => $result->toArray(),
                'confidence' => $result->confidence,
                'provider' => $this->provider->name(),
                'model' => config('sasa.ai.model'),
                'raw_response' => $result->raw,
                'status' => 'pending',
            ]
        );

        $grievance->forceFill(['has_ai_suggestions' => true])->save();

        $this->audit->record(
            action: 'ai.suggestion_created',
            entity: $grievance,
            after: ['kind' => 'classification', 'confidence' => $result->confidence],
            summary: 'AI proposed a classification for a person to confirm',
        );

        return $suggestion;
    }

    /**
     * A person accepts, modifies or rejects. Nothing else moves a suggestion
     * into the confirmed columns.
     */
    public function review(AiSuggestion $suggestion, string $decision, array $acceptedValue = []): AiSuggestion
    {
        if (! in_array($decision, ['accepted', 'modified', 'rejected'], true)) {
            throw new DomainRuleException('A suggestion can only be accepted, modified or rejected.', 'invalid_decision');
        }

        return DB::transaction(function () use ($suggestion, $decision, $acceptedValue) {
            $suggestion->forceFill([
                'status' => $decision,
                'accepted_value' => $decision === 'rejected' ? null : ($acceptedValue ?: $suggestion->suggestion),
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ])->save();

            $this->audit->record(
                action: 'ai.suggestion_reviewed',
                entity: $suggestion->subject,
                after: ['decision' => $decision, 'value' => $suggestion->accepted_value],
                summary: "AI suggestion {$decision} by a person",
            );

            return $suggestion->fresh();
        });
    }
}
