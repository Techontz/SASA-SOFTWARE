<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attachment\AttachmentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttachmentResource;
use App\Models\Attachment;
use App\Models\Commitment;
use App\Models\Concern;
use App\Models\Engagement;
use App\Models\Grievance;
use App\Models\Stakeholder;
use App\Support\ApiResponse;
use App\Support\DomainRuleException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttachmentController extends Controller
{
    private const ATTACHABLE = [
        'stakeholder' => Stakeholder::class,
        'engagement' => Engagement::class,
        'concern' => Concern::class,
        'commitment' => Commitment::class,
        'grievance' => Grievance::class,
    ];

    public function __construct(private readonly AttachmentService $attachments) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(self::ATTACHABLE))],
            'attachable_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:'.(config('sasa.files.max_mb', 25) * 1024)],
            'kind' => ['nullable', 'string', 'max:30'],
            'caption' => ['nullable', 'string', 'max:500'],
            'is_sensitive' => ['nullable', 'boolean'],
            'client_uuid' => ['nullable', 'uuid'],
            'captured_at' => ['nullable', 'date'],
        ], [
            'file.max' => 'That file is larger than the '.config('sasa.files.max_mb', 25).' MB limit.',
        ]);

        $modelClass = self::ATTACHABLE[$data['attachable_type']];
        $attachable = $modelClass::where('project_id', $this->project()->id)->findOrFail($data['attachable_id']);

        $this->authorize('update', $attachable);

        // Replaying the same offline upload must not create a second copy.
        if (! empty($data['client_uuid'])) {
            $existing = Attachment::where('project_id', $this->project()->id)
                ->where('client_uuid', $data['client_uuid'])->first();

            if ($existing) {
                return ApiResponse::data(new AttachmentResource($existing), ['duplicate' => true]);
            }
        }

        $attachment = $this->attachments->store($this->project(), $attachable, $request->file('file'), $data);

        return ApiResponse::data(new AttachmentResource($attachment->load('uploader')), [], 201);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(self::ATTACHABLE))],
            'attachable_id' => ['required', 'integer'],
        ]);

        $modelClass = self::ATTACHABLE[$data['attachable_type']];
        $attachable = $modelClass::where('project_id', $this->project()->id)->findOrFail($data['attachable_id']);

        $this->authorize('view', $attachable);

        return ApiResponse::data(
            AttachmentResource::collection($attachable->attachments()->active()->with('uploader:id,name')->get())
        );
    }

    /** Signed, short-lived, audited. The storage path is never exposed. */
    public function download(Request $request, Attachment $attachment)
    {
        if (! $request->hasValidSignature()) {
            throw new DomainRuleException(
                'That download link has expired. Open the record again to get a fresh one.',
                'expired_link',
                403
            );
        }

        if ($attachment->scan_status === 'infected') {
            throw new DomainRuleException('That file was rejected by the virus scanner.', 'file_infected', 403);
        }

        return $this->attachments->download($attachment);
    }

    public function destroy(Attachment $attachment)
    {
        abort_unless($attachment->project_id === $this->project()->id, 404);

        $attachable = $attachment->attachable;
        $this->authorize('update', $attachable);

        $this->attachments->archive($attachment);

        return ApiResponse::message('The file has been archived. It stays in the audit trail.');
    }
}
