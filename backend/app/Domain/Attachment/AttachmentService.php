<?php

namespace App\Domain\Attachment;

use App\Domain\Audit\AuditLogger;
use App\Models\Attachment;
use App\Models\Project;
use App\Support\DomainRuleException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Private storage, signed temporary URLs, auditable downloads.
 *
 * The raw storage path never reaches the client; the API hands out a
 * short-lived signed URL and logs every download.
 */
final class AttachmentService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Project $project, Model $attachable, UploadedFile $file, array $meta = []): Attachment
    {
        $this->guardFile($file);

        $disk = config('filesystems.default', 'local');
        $directory = sprintf('projects/%d/%s/%d', $project->id, $attachable->getMorphClass(), $attachable->getKey());
        $filename = Str::uuid().'.'.($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs($directory, $filename, ['disk' => $disk]);

        $attachment = Attachment::create([
            'organisation_id' => $project->organisation_id,
            'project_id' => $project->id,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'client_uuid' => $meta['client_uuid'] ?? null,
            'kind' => $meta['kind'] ?? $this->inferKind($file),
            'original_name' => $file->getClientOriginalName(),
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'caption' => $meta['caption'] ?? null,
            'is_sensitive' => (bool) ($meta['is_sensitive'] ?? false),
            'captured_at' => $meta['captured_at'] ?? now(),
            'synced_at' => now(),
            'uploaded_by' => auth()->id(),
            'scan_status' => 'pending',
        ]);

        $this->scan($attachment);

        $this->audit->record(
            action: 'attachment.uploaded',
            entity: $attachable,
            after: ['attachment_id' => $attachment->id, 'name' => $attachment->original_name, 'kind' => $attachment->kind],
            summary: "Attached {$attachment->original_name}",
        );

        return $attachment->fresh();
    }

    /** Short-lived signed URL; the storage path is never exposed. */
    public function temporaryUrl(Attachment $attachment): string
    {
        return URL::temporarySignedRoute(
            'attachments.download',
            now()->addMinutes((int) config('sasa.files.signed_url_ttl_minutes', 10)),
            ['attachment' => $attachment->id]
        );
    }

    public function download(Attachment $attachment)
    {
        $this->audit->record(
            action: 'attachment.downloaded',
            entity: $attachment,
            summary: "Downloaded {$attachment->original_name}",
            sensitiveView: $attachment->is_sensitive,
        );

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name);
    }

    public function archive(Attachment $attachment): void
    {
        // Files are never hard-deleted — the audit trail must stay meaningful.
        $attachment->forceFill(['archived_at' => now()])->save();

        $this->audit->record(
            action: 'attachment.archived',
            entity: $attachment,
            summary: "Archived {$attachment->original_name}",
        );
    }

    private function guardFile(UploadedFile $file): void
    {
        $maxBytes = (int) config('sasa.files.max_mb', 25) * 1024 * 1024;

        if ($file->getSize() > $maxBytes) {
            throw new DomainRuleException(
                sprintf('That file is larger than the %d MB limit. Try compressing it, or split it into parts.', config('sasa.files.max_mb', 25)),
                'file_too_large'
            );
        }

        $mime = $file->getMimeType();

        if (! in_array($mime, config('sasa.files.allowed_mimes', []), true)) {
            throw new DomainRuleException(
                'That file type cannot be attached. Photos, PDFs, Office documents, audio and video are accepted.',
                'file_type_not_allowed'
            );
        }
    }

    /**
     * Virus scanning where the infrastructure supports it. When no scanner is
     * configured the status is recorded as "skipped" rather than claimed as
     * "clean" — we do not assert a check we did not run.
     */
    private function scan(Attachment $attachment): void
    {
        if (! config('sasa.files.virus_scan_enabled')) {
            $attachment->forceFill(['scan_status' => 'skipped'])->save();

            return;
        }

        try {
            $absolute = Storage::disk($attachment->disk)->path($attachment->path);
            $command = array_merge(explode(' ', (string) config('sasa.files.virus_scan_command')), [$absolute]);

            $process = new Process($command);
            $process->setTimeout(60);
            $process->run();

            $infected = $process->getExitCode() === 1;

            $attachment->forceFill([
                'scan_status' => $infected ? 'infected' : ($process->getExitCode() === 0 ? 'clean' : 'failed'),
                'scan_result' => trim($process->getOutput().$process->getErrorOutput()) ?: null,
            ])->save();

            if ($infected) {
                Storage::disk($attachment->disk)->delete($attachment->path);
                $attachment->forceFill(['archived_at' => now()])->save();

                throw new DomainRuleException(
                    'That file was rejected by the virus scanner and has not been kept.',
                    'file_infected'
                );
            }
        } catch (DomainRuleException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('attachment.scan_failed', ['attachment_id' => $attachment->id, 'error' => $e->getMessage()]);
            $attachment->forceFill(['scan_status' => 'failed', 'scan_result' => $e->getMessage()])->save();
        }
    }

    private function inferKind(UploadedFile $file): string
    {
        $mime = (string) $file->getMimeType();

        return match (true) {
            str_starts_with($mime, 'image/') => 'photo',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => 'document',
        };
    }
}
