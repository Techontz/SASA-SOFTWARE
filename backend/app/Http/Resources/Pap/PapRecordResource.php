<?php

namespace App\Http\Resources\Pap;

use App\Domain\Pap\PapOptions;
use App\Domain\Pap\PapSections;
use App\Domain\Tenancy\TenantContext;
use App\Models\Pap\PapDocument;
use App\Models\Pap\PapRecord;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Any PAP child record (Parts B–J and M). Emits the section's input and
 * derived fields plus a label for every dropdown value. Sensitive fields are
 * left out entirely unless the viewer holds pap.view_sensitive.
 *
 * @mixin PapRecord
 */
class PapRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var PapRecord $record */
        $record = $this->resource;
        $section = PapSections::forModel($record);
        $definition = PapSections::get($section);
        $project = app(TenantContext::class)->project() ?? $record->project;
        $options = app(PapOptions::class);
        $canSeeSensitive = PapSensitivity::canSee();

        $data = [
            'id' => $record->id,
            'section' => $section,
            'reference' => $record->getAttribute('reference'),
            'pap_id' => $record->pap_id,
        ];

        $sensitive = $record::SENSITIVE_FIELDS;

        if ($record instanceof PapDocument && $project
            && in_array($record->document_type, $options->all($project)['sensitive_document_types'] ?? [], true)) {
            $sensitive[] = 'document_number';
        }

        foreach (array_merge($record::INPUT_FIELDS, $record::DERIVED_FIELDS) as $field) {
            if (in_array($field, $sensitive, true) && ! $canSeeSensitive) {
                continue; // absent, never null
            }

            $data[$field] = $this->normalise($record, $field);
        }

        foreach ($definition['labels'] as $field => $list) {
            $data[$field.'_label'] = $project ? $options->label($project, $list, $record->{$field}) : $record->{$field};
        }

        $data['sensitive_withheld'] = $sensitive !== [] && ! $canSeeSensitive;
        $data['status'] = $record->status;
        $data['archived_at'] = $record->archived_at?->toIso8601String();
        $data['created_at'] = $record->created_at?->toIso8601String();
        $data['updated_at'] = $record->updated_at?->toIso8601String();

        return $data;
    }

    private function normalise(PapRecord $record, string $field): mixed
    {
        $value = $record->{$field};
        $cast = $record->getCasts()[$field] ?? null;

        return match (true) {
            $value === null => null,
            $value instanceof CarbonInterface => $value->toDateString(),
            is_string($cast) && str_starts_with($cast, 'decimal') => (float) $value,
            default => $value,
        };
    }
}
