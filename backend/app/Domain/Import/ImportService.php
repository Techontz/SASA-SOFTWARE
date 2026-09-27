<?php

namespace App\Domain\Import;

use App\Domain\Audit\AuditLogger;
use App\Domain\Engagement\CommitmentService;
use App\Domain\Grievance\IntakeEngine;
use App\Domain\Stakeholder\StakeholderService;
use App\Models\Grievance;
use App\Models\GrievanceCategory;
use App\Models\ImportJob;
use App\Models\Location;
use App\Models\Project;
use App\Models\Stakeholder;
use App\Models\User;
use App\Support\DomainRuleException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * The import wizard:
 *
 *   download a template → upload → map columns → validate → preview
 *   → fix errors → commit
 *
 * Commits run in resumable batches inside a transaction per batch, and the
 * last committed row is recorded — so a failure halfway through never leaves
 * production data half-written and never re-imports what already landed.
 */
final class ImportService
{
    public const ENTITIES = ['stakeholders', 'locations', 'engagements', 'commitments', 'grievances'];

    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly StakeholderService $stakeholders,
        private readonly CommitmentService $commitments,
        private readonly IntakeEngine $intake,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{columns:array<int,array{key:string,label:string,required:bool,help:string}>} */
    public function template(string $entity): array
    {
        return match ($entity) {
            'stakeholders' => ['columns' => [
                ['key' => 'name', 'label' => 'Name', 'required' => true, 'help' => 'The person, household, group or organisation.'],
                ['key' => 'type', 'label' => 'Type', 'required' => true, 'help' => 'individual, household, community_group, cso, government, traditional_leader, contractor, project_staff, business'],
                ['key' => 'phone', 'label' => 'Phone', 'required' => false, 'help' => 'Any format. Used for duplicate detection.'],
                ['key' => 'email', 'label' => 'Email', 'required' => false, 'help' => ''],
                ['key' => 'region', 'label' => 'Region', 'required' => false, 'help' => 'Matched against the location hierarchy.'],
                ['key' => 'district', 'label' => 'District', 'required' => false, 'help' => ''],
                ['key' => 'ward', 'label' => 'Ward', 'required' => false, 'help' => ''],
                ['key' => 'village', 'label' => 'Village', 'required' => false, 'help' => ''],
                ['key' => 'influence', 'label' => 'Influence', 'required' => false, 'help' => 'high, medium or low'],
                ['key' => 'interest', 'label' => 'Interest', 'required' => false, 'help' => 'high, medium or low'],
                ['key' => 'power', 'label' => 'Power', 'required' => false, 'help' => 'high, medium or low'],
                ['key' => 'impact', 'label' => 'Impact', 'required' => false, 'help' => 'high, medium or low'],
                ['key' => 'is_vulnerable', 'label' => 'Vulnerable', 'required' => false, 'help' => 'yes or no'],
                ['key' => 'preferred_language', 'label' => 'Preferred language', 'required' => false, 'help' => 'e.g. sw, en'],
                ['key' => 'concerns_expectations', 'label' => 'Concerns and expectations', 'required' => false, 'help' => ''],
                ['key' => 'consent_status', 'label' => 'Consent', 'required' => false, 'help' => 'granted, refused, not_recorded'],
            ]],
            'locations' => ['columns' => [
                ['key' => 'level', 'label' => 'Level', 'required' => true, 'help' => 'country, region, district, ward or village'],
                ['key' => 'name', 'label' => 'Name', 'required' => true, 'help' => ''],
                ['key' => 'parent_name', 'label' => 'Parent name', 'required' => false, 'help' => 'The name of the level above. Leave blank for country.'],
                ['key' => 'code', 'label' => 'Code', 'required' => false, 'help' => ''],
                ['key' => 'estimated_population', 'label' => 'Estimated population', 'required' => false, 'help' => ''],
            ]],
            'commitments' => ['columns' => [
                ['key' => 'commitment_text', 'label' => 'Commitment', 'required' => true, 'help' => 'What was promised, in the words it was promised.'],
                ['key' => 'source_date', 'label' => 'Made on', 'required' => false, 'help' => 'YYYY-MM-DD'],
                ['key' => 'due_date', 'label' => 'Due date', 'required' => false, 'help' => 'YYYY-MM-DD'],
                ['key' => 'owner_email', 'label' => 'Owner email', 'required' => false, 'help' => 'Matched to a project member.'],
                ['key' => 'risk_level', 'label' => 'Risk', 'required' => false, 'help' => 'high, medium or low'],
                ['key' => 'status', 'label' => 'Status', 'required' => false, 'help' => 'open, in_progress, fulfilled, cancelled'],
                ['key' => 'stakeholder_reference', 'label' => 'Stakeholder ID', 'required' => false, 'help' => 'e.g. STK-0001'],
            ]],
            'grievances' => ['columns' => [
                ['key' => 'received_at', 'label' => 'Date received', 'required' => true, 'help' => 'YYYY-MM-DD'],
                ['key' => 'channel', 'label' => 'Received via', 'required' => true, 'help' => 'voice, whatsapp, sms, web, in_person, email, leader, suggestion_box'],
                ['key' => 'title', 'label' => 'Summary', 'required' => false, 'help' => 'Left blank, the first line of the description is used.'],
                ['key' => 'description', 'label' => 'Description', 'required' => true, 'help' => ''],
                ['key' => 'category_name', 'label' => 'Category', 'required' => false, 'help' => 'Matched to the project category list.'],
                ['key' => 'severity', 'label' => 'Severity', 'required' => false, 'help' => '1 to 5'],
                ['key' => 'confidentiality', 'label' => 'Confidentiality', 'required' => false, 'help' => 'normal, confidential or anonymous'],
                ['key' => 'complainant_name', 'label' => 'Complainant', 'required' => false, 'help' => 'Leave blank for anonymous cases.'],
                ['key' => 'complainant_phone', 'label' => 'Complainant phone', 'required' => false, 'help' => ''],
                ['key' => 'village', 'label' => 'Village', 'required' => false, 'help' => ''],
                ['key' => 'status', 'label' => 'Status', 'required' => false, 'help' => 'Historic status, e.g. closed'],
            ]],
            default => throw new DomainRuleException('There is no import template for that.', 'unknown_entity'),
        };
    }

    public function upload(Project $project, string $entity, UploadedFile $file): ImportJob
    {
        if (! in_array($entity, self::ENTITIES, true)) {
            throw new DomainRuleException('That kind of record cannot be imported.', 'unknown_entity');
        }

        $path = $file->store(sprintf('projects/%d/imports', $project->id), config('filesystems.default', 'local'));
        $rows = $this->readRows(Storage::disk(config('filesystems.default', 'local'))->path($path));

        if ($rows === []) {
            throw new DomainRuleException('That file appears to be empty.', 'empty_file');
        }

        $headers = array_map(fn ($header) => trim((string) $header), array_shift($rows));

        $job = ImportJob::create([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'entity' => $entity,
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'headers' => $headers,
            'mapping' => $this->guessMapping($entity, $headers),
            'status' => 'uploaded',
            'rows_total' => count($rows),
            'preview' => array_slice($rows, 0, 5),
            'created_by' => auth()->id(),
        ]);

        $this->audit->record(
            action: 'import.uploaded',
            entity: $job,
            after: ['entity' => $entity, 'rows' => count($rows), 'file' => $file->getClientOriginalName()],
            summary: "Uploaded {$file->getClientOriginalName()} for import",
        );

        return $job;
    }

    /** Validate the mapped file WITHOUT writing anything. */
    public function validateJob(ImportJob $job, array $mapping): ImportJob
    {
        $rows = $this->rowsFor($job);
        $errors = [];
        $valid = 0;
        $preview = [];

        foreach ($rows as $index => $row) {
            $mapped = $this->mapRow($row, $job->headers, $mapping);
            $validator = Validator::make($mapped, $this->rules($job->entity));

            if ($validator->fails()) {
                $errors[] = [
                    'row' => $index + 2, // +1 for the header, +1 for 1-based
                    'values' => $mapped,
                    'errors' => $validator->errors()->all(),
                ];
            } else {
                $valid++;

                if (count($preview) < 10) {
                    $preview[] = $mapped;
                }
            }
        }

        $job->forceFill([
            'mapping' => $mapping,
            'status' => 'validated',
            'rows_valid' => $valid,
            'rows_invalid' => count($errors),
            'errors' => array_slice($errors, 0, 200),
            'preview' => $preview,
        ])->save();

        return $job->fresh();
    }

    /**
     * Commit in batches, resuming from the last committed row. Invalid rows
     * are skipped and reported — they never block the valid ones, and they are
     * never silently dropped.
     */
    public function commit(ImportJob $job, Project $project): ImportJob
    {
        if ($job->status === 'committed') {
            return $job;
        }

        if ($job->status !== 'validated' && $job->status !== 'committing') {
            throw new DomainRuleException('Validate the file before importing it.', 'not_validated');
        }

        $rows = $this->rowsFor($job);
        $job->forceFill(['status' => 'committing'])->save();

        $committed = $job->rows_committed;
        $failures = [];
        $start = $job->last_committed_row;

        foreach (array_slice($rows, $start, null, true) as $index => $row) {
            $mapped = $this->mapRow($row, $job->headers, $job->mapping ?? []);
            $validator = Validator::make($mapped, $this->rules($job->entity));

            if ($validator->fails()) {
                $failures[] = ['row' => $index + 2, 'errors' => $validator->errors()->all()];
                $job->forceFill(['last_committed_row' => $index + 1])->save();

                continue;
            }

            try {
                DB::transaction(fn () => $this->persist($job->entity, $project, $mapped));
                $committed++;
            } catch (\Throwable $e) {
                $failures[] = ['row' => $index + 2, 'errors' => [$e->getMessage()]];
            }

            $job->forceFill([
                'rows_committed' => $committed,
                'last_committed_row' => $index + 1,
            ])->save();
        }

        $job->forceFill([
            'status' => 'committed',
            'rows_committed' => $committed,
            'errors' => array_slice(array_merge($job->errors ?? [], $failures), 0, 200),
            'committed_at' => now(),
        ])->save();

        $this->audit->record(
            action: 'import.committed',
            entity: $job,
            after: ['imported' => $committed, 'failed' => count($failures), 'entity' => $job->entity],
            summary: "Imported {$committed} {$job->entity}",
        );

        return $job->fresh();
    }

    private function persist(string $entity, Project $project, array $data): void
    {
        match ($entity) {
            'stakeholders' => $this->persistStakeholder($project, $data),
            'locations' => $this->persistLocation($project, $data),
            'commitments' => $this->commitments->create($project, $this->prepareCommitment($project, $data)),
            'grievances' => $this->intake->intake($project, $this->prepareGrievance($project, $data)),
            default => throw new DomainRuleException('That kind of record cannot be imported.', 'unknown_entity'),
        };
    }

    private function persistStakeholder(Project $project, array $data): void
    {
        $locationId = $this->resolveLocation($project, $data);

        $this->stakeholders->create($project, array_merge($data, [
            'primary_location_id' => $locationId,
            'is_vulnerable' => $this->boolean($data['is_vulnerable'] ?? null),
            'consent_status' => $data['consent_status'] ?? 'not_recorded',
            'influence' => $this->level($data['influence'] ?? null),
            'interest' => $this->level($data['interest'] ?? null),
            'power' => $this->level($data['power'] ?? null),
            'impact' => $this->level($data['impact'] ?? null),
        ]));
    }

    private function persistLocation(Project $project, array $data): void
    {
        $parent = ! empty($data['parent_name'])
            ? Location::where('organisation_id', $project->organisation_id)
                ->where('name', $data['parent_name'])
                ->first()
            : null;

        Location::updateOrCreate(
            [
                'organisation_id' => $project->organisation_id,
                'project_id' => $project->id,
                'level' => $data['level'],
                'name' => $data['name'],
                'parent_id' => $parent?->id,
            ],
            [
                'code' => $data['code'] ?? null,
                'estimated_population' => $data['estimated_population'] ?? null,
                'status' => 'active',
            ]
        );
    }

    private function prepareCommitment(Project $project, array $data): array
    {
        $owner = ! empty($data['owner_email'])
            ? User::where('email', $data['owner_email'])->first()
            : null;

        $stakeholder = ! empty($data['stakeholder_reference'])
            ? Stakeholder::where('project_id', $project->id)
                ->where('reference', $data['stakeholder_reference'])->first()
            : null;

        return array_merge($data, [
            'owner_id' => $owner?->id,
            'source_type' => 'manual',
            'stakeholder_ids' => $stakeholder ? [$stakeholder->id] : [],
            'status' => $data['status'] ?? 'open',
            'risk_level' => $this->level($data['risk_level'] ?? 'medium') ?? 'medium',
        ]);
    }

    private function prepareGrievance(Project $project, array $data): array
    {
        $category = ! empty($data['category_name'])
            ? GrievanceCategory::forProject($project->id)
                ->where('name', $data['category_name'])->first()
            : null;

        $locationId = $this->resolveLocation($project, $data);

        return array_merge($data, [
            'category_id' => $category?->id,
            'location_id' => $locationId,
            'confidentiality' => $data['confidentiality'] ?? 'normal',
            // Historic rows carry their own idempotency key so a re-run of the
            // same migration file does not duplicate the register.
            'idempotency_key' => 'import:'.md5(json_encode([
                $data['received_at'] ?? '', $data['description'] ?? '', $data['complainant_phone'] ?? '',
            ])),
        ]);
    }

    private function resolveLocation(Project $project, array $data): ?int
    {
        foreach (['village', 'ward', 'district', 'region'] as $level) {
            if (empty($data[$level])) {
                continue;
            }

            $location = Location::where('organisation_id', $project->organisation_id)
                ->where('level', $level)
                ->where('name', $data[$level])
                ->first();

            if ($location) {
                return $location->id;
            }
        }

        return null;
    }

    private function rules(string $entity): array
    {
        return match ($entity) {
            'stakeholders' => [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'string', 'in:'.implode(',', Stakeholder::TYPES)],
                'email' => ['nullable', 'email'],
                'influence' => ['nullable', 'string'],
                'interest' => ['nullable', 'string'],
                'power' => ['nullable', 'string'],
                'impact' => ['nullable', 'string'],
            ],
            'locations' => [
                'level' => ['required', 'in:'.implode(',', Location::LEVELS)],
                'name' => ['required', 'string', 'max:255'],
            ],
            'commitments' => [
                'commitment_text' => ['required', 'string'],
                'due_date' => ['nullable', 'date'],
                'source_date' => ['nullable', 'date'],
                'owner_email' => ['nullable', 'email'],
            ],
            'grievances' => [
                'received_at' => ['required', 'date'],
                'channel' => ['required', 'in:'.implode(',', Grievance::CHANNELS)],
                'description' => ['required', 'string'],
                'severity' => ['nullable', 'integer', 'between:1,5'],
                'confidentiality' => ['nullable', 'in:normal,confidential,anonymous'],
            ],
            default => [],
        };
    }

    /** @return array<int,array<int,string>> */
    private function readRows(string $absolutePath): array
    {
        if (str_ends_with(strtolower($absolutePath), '.csv')) {
            $rows = [];
            $handle = fopen($absolutePath, 'r');

            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }

            fclose($handle);

            return $rows;
        }

        $spreadsheet = IOFactory::load($absolutePath);
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        return array_values(array_filter($rows, fn ($row) => collect($row)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty()));
    }

    private function rowsFor(ImportJob $job): array
    {
        $rows = $this->readRows(Storage::disk(config('filesystems.default', 'local'))->path($job->path));
        array_shift($rows);

        return $rows;
    }

    private function mapRow(array $row, array $headers, array $mapping): array
    {
        $mapped = [];

        foreach ($mapping as $field => $header) {
            if ($header === null || $header === '') {
                continue;
            }

            $index = array_search($header, $headers, true);

            if ($index === false) {
                continue;
            }

            $value = $row[$index] ?? null;
            $mapped[$field] = is_string($value) ? trim($value) : $value;

            if ($mapped[$field] === '') {
                $mapped[$field] = null;
            }
        }

        return $mapped;
    }

    /** Guess the mapping so the wizard opens on a sensible default. */
    private function guessMapping(string $entity, array $headers): array
    {
        $mapping = [];

        foreach ($this->template($entity)['columns'] as $column) {
            foreach ($headers as $header) {
                if (Str::slug((string) $header, '_') === $column['key']
                    || strcasecmp(trim((string) $header), $column['label']) === 0) {
                    $mapping[$column['key']] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function boolean(mixed $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'yes', 'y', 'true', 'ndiyo'], true);
    }

    private function level(?string $value): ?string
    {
        $normalised = mb_strtolower(trim((string) $value));

        return in_array($normalised, ['high', 'medium', 'low'], true) ? $normalised : null;
    }
}
