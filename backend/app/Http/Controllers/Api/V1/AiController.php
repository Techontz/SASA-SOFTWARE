<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\AiClassificationService;
use App\Domain\Ai\AiProvider;
use App\Http\Controllers\Controller;
use App\Models\AiSuggestion;
use App\Models\Grievance;
use App\Models\VoiceCall;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI may propose. It never owns investigation findings, the resolution
 * decision, corrective-action approval or closure.
 */
class AiController extends Controller
{
    public function __construct(
        private readonly AiClassificationService $ai,
        private readonly AiProvider $provider,
    ) {}

    public function status()
    {
        return ApiResponse::data([
            'provider' => $this->provider->name(),
            'available' => $this->provider->isAvailable(),
            'model' => config('sasa.ai.model'),
            'min_confidence' => config('sasa.ai.min_confidence_to_suggest'),
            'never_owns' => config('sasa.ai.never_owns'),
            'note' => 'AI proposes a category, subcategory, severity and summary. A person confirms every one of them, '
                .'and the case shows "AI suggested — confirm" until they do.',
        ]);
    }

    public function suggest(Request $request, Grievance $grievance)
    {
        $this->authorize('classify', $grievance);

        $suggestion = $this->ai->suggestFor($grievance);

        return $suggestion
            ? ApiResponse::data($suggestion)
            : ApiResponse::message('The AI was not confident enough to suggest anything here. Classify it yourself.', [], 200);
    }

    public function review(Request $request, AiSuggestion $suggestion)
    {
        abort_unless($this->context()->can('ai.review'), 403, 'You do not have permission to review AI suggestions.');
        abort_unless($suggestion->project_id === $this->project()->id, 404);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'modified', 'rejected'])],
            'accepted_value' => ['nullable', 'array'],
        ]);

        $reviewed = $this->ai->review($suggestion, $data['decision'], $data['accepted_value'] ?? []);

        return ApiResponse::data($reviewed);
    }

    public function pending(Request $request)
    {
        abort_unless($this->context()->can('ai.review'), 403, 'You do not have permission to review AI suggestions.');

        return ApiResponse::data(
            AiSuggestion::where('project_id', $this->project()->id)
                ->where('status', 'pending')
                ->with('subject')
                ->latest()
                ->paginate(25)
        );
    }

    /** Accuracy is measurable precisely because proposals are stored apart. */
    public function accuracy()
    {
        abort_unless($this->context()->can('ai.review'), 403);

        $base = AiSuggestion::where('project_id', $this->project()->id)->whereNot('status', 'pending');
        $total = (clone $base)->count();

        return ApiResponse::data([
            'reviewed' => $total,
            'accepted' => (clone $base)->where('status', 'accepted')->count(),
            'modified' => (clone $base)->where('status', 'modified')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
            'acceptance_rate' => $total > 0
                ? round((clone $base)->where('status', 'accepted')->count() / $total * 100, 1)
                : null,
            'pending' => AiSuggestion::where('project_id', $this->project()->id)->where('status', 'pending')->count(),
        ]);
    }

    public function voiceCalls(Request $request)
    {
        abort_unless($this->context()->can('grievance.view'), 403);

        $query = VoiceCall::where('project_id', $this->project()->id)
            ->with('grievance:id,reference,status');

        if ($request->boolean('needs_review')) {
            $query->where('needs_human_review', true);
        }

        return ApiResponse::data(
            $query->latest()->paginate(25)->through(fn (VoiceCall $call) => [
                'id' => $call->id,
                'external_id' => $call->external_id,
                'provider' => $call->provider,
                'language' => $call->language,
                'status' => $call->status,
                'consent_granted' => $call->consent_granted,
                'duration_seconds' => $call->duration_seconds,
                'was_truncated' => $call->was_truncated,
                'needs_human_review' => $call->needs_human_review,
                'callback_requested' => $call->callback_requested,
                'transcript' => $call->transcript,
                'grievance' => $call->grievance ? [
                    'id' => $call->grievance->id,
                    'reference' => $call->grievance->reference,
                    'status' => $call->grievance->status,
                ] : null,
                'started_at' => $call->started_at?->toIso8601String(),
                'ended_at' => $call->ended_at?->toIso8601String(),
            ])
        );
    }
}
