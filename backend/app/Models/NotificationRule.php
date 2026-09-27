<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationRule extends Model
{
    use Auditable, HasFactory;

    public const AUDIT_NAME = 'notification_rule';

    protected $fillable = [
        'organisation_id', 'project_id', 'event_key', 'name', 'recipient_roles',
        'notify_owner', 'notify_assignee', 'channels', 'conditions',
        'template_subject', 'template_body', 'is_active', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'recipient_roles' => 'array',
            'channels' => 'array',
            'conditions' => 'array',
            'notify_owner' => 'boolean',
            'notify_assignee' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
