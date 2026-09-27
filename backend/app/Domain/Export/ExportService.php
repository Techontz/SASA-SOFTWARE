<?php

namespace App\Domain\Export;

use App\Domain\Audit\AuditLogger;
use App\Domain\Grievance\GrievanceVisibility;
use App\Domain\Reporting\ReportDocument;
use App\Domain\Reporting\ReportSection;
use App\Domain\Reporting\Writers\CsvWriter;
use App\Domain\Reporting\Writers\ExcelWriter;
use App\Domain\Reporting\Writers\PdfWriter;
use App\Domain\Reporting\Writers\WordWriter;
use App\Models\ExportLog;
use App\Models\Project;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * List exports. They respect permissions and confidentiality: identity fields
 * are stripped for users outside the handling group, restricted categories are
 * excluded, and every export is audit-logged with its filters and row count.
 */
final class ExportService
{
    public function __construct(
        private readonly GrievanceVisibility $visibility,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  Builder  $query  Already filtered and already permission-scoped.
     * @param  array{columns:array<int,string>,mapper:callable}  $shape
     */
    public function export(
        Project $project,
        User $user,
        string $entity,
        string $format,
        Builder $query,
        array $shape,
        array $filters = [],
    ): array {
        if (! in_array($format, ['xlsx', 'csv', 'pdf', 'docx'], true)) {
            throw new DomainRuleException('Exports are available as Excel, CSV, PDF or Word.', 'unknown_format');
        }

        $stripIdentity = $entity === 'grievances' && $this->visibility->exportStripsIdentity();

        $rows = [];
        $query->chunkById(500, function ($records) use (&$rows, $shape) {
            foreach ($records as $record) {
                $rows[] = ($shape['mapper'])($record);
            }
        });

        $document = new ReportDocument(
            title: Str::headline($entity).' export',
            subtitle: $project->name,
            projectName: $project->name,
            organisationName: $project->organisation?->name ?? '',
            periodLabel: $filters['label'] ?? 'All records matching the current filters',
            filters: $filters,
            generatedBy: $user->name,
            generatedAt: now()->toDayDateTimeString(),
        );

        $document->add(ReportSection::table(
            Str::headline($entity),
            $shape['columns'],
            $rows,
            $stripIdentity
                ? 'Complainant identity has been removed because you are not in the handling group for these cases.'
                : null,
        ));

        $relativePath = sprintf(
            'projects/%d/exports/%s-%s.%s',
            $project->id,
            $entity,
            now()->format('Ymd-His'),
            $format
        );

        $disk = Storage::disk(config('filesystems.default', 'local'));
        $disk->put($relativePath, '');
        $absolutePath = $disk->path($relativePath);

        match ($format) {
            'xlsx' => (new ExcelWriter)->write($document, $absolutePath),
            'csv' => (new CsvWriter)->write($document, $absolutePath),
            'pdf' => (new PdfWriter)->write($document, $absolutePath),
            'docx' => (new WordWriter)->write($document, $absolutePath),
        };

        ExportLog::create([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'entity' => $entity,
            'format' => $format,
            'filters' => $filters,
            'row_count' => count($rows),
            'identity_stripped' => $stripIdentity,
            'ip_address' => request()?->ip(),
        ]);

        $this->audit->record(
            action: 'export.generated',
            summary: sprintf('Exported %d %s as %s', count($rows), $entity, strtoupper($format)),
            context: ['filters' => $filters, 'row_count' => count($rows), 'identity_stripped' => $stripIdentity],
            projectId: $project->id,
            organisationId: $project->organisation_id,
        );

        return [
            'path' => $relativePath,
            'filename' => sprintf('sasa-%s-%s.%s', $entity, now()->format('Y-m-d'), $format),
            'row_count' => count($rows),
            'identity_stripped' => $stripIdentity,
        ];
    }
}
