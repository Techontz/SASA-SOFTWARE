<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Voice\VoiceAgentService;
use App\Domain\Voice\VoiceProvider;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * The telephony webhook. Unauthenticated by nature — the provider calls it —
 * so it is signature-verified, rate-limited and scoped to one project by an
 * explicit inbound project token.
 */
class VoiceWebhookController extends Controller
{
    public function __construct(
        private readonly VoiceAgentService $agent,
        private readonly VoiceProvider $provider,
    ) {}

    public function handle(Request $request, string $projectCode)
    {
        if (! $this->provider->verifyWebhook($request->headers->all(), $request->getContent())) {
            return ApiResponse::error('invalid_signature', 'This request could not be verified.', [], 401);
        }

        $project = Project::where('code', $projectCode)->where('status', 'active')->first();

        if (! $project) {
            return ApiResponse::error('not_found', 'No active project matches that code.', [], 404);
        }

        $payload = $this->provider->normalise($request->all());

        return response()->json($this->agent->handleTurn($project, $payload));
    }

    /** The prompts and settings, so a provider script can be generated. */
    public function script(Request $request, string $projectCode)
    {
        $project = Project::where('code', $projectCode)->firstOrFail();

        return ApiResponse::data([
            'project' => $project->code,
            'settings' => $this->agent->settings($project),
            'note' => 'Consent is mandatory. If the caller declines, log "Consent Not Granted", '
                .'route to the human call-back queue, and do not create a grievance.',
        ]);
    }
}
