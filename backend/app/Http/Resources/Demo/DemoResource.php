<?php

namespace App\Http\Resources\Demo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A demo workspace, for whoever holds enquiry.manage. The invitation link is
 * a credential: it is returned only by the call that created it (provision
 * or resend-invite), never by a list.
 */
class DemoResource extends JsonResource
{
    private ?string $inviteUrl = null;

    private ?bool $emailSent = null;

    public function withInvite(string $inviteUrl, bool $emailSent): static
    {
        $this->inviteUrl = $inviteUrl;
        $this->emailSent = $emailSent;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'public_id' => $this->public_id,
            'status' => $this->effectiveStatus(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'organisation' => $this->organisation ? ['id' => $this->organisation->id, 'name' => $this->organisation->name] : null,
            'project' => $this->project ? ['id' => $this->project->id, 'name' => $this->project->name, 'code' => $this->project->code] : null,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name, 'email' => $this->user->email] : null,
            'enquiry' => $this->enquiry ? ['public_id' => $this->enquiry->public_id, 'reference' => $this->enquiry->reference] : null,
        ];

        if ($this->inviteUrl !== null) {
            $data['invite_url'] = $this->inviteUrl;
            $data['email_sent'] = (bool) $this->emailSent;
        }

        $data['created_at'] = $this->created_at?->toIso8601String();

        return $data;
    }
}
