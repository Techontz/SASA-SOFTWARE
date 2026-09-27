<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only. There is deliberately no update or delete path. */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organisation_id', 'project_id', 'user_id', 'user_name', 'action',
        'entity_type', 'entity_id', 'entity_reference', 'summary',
        'before', 'after', 'context', 'is_sensitive_view',
        'ip_address', 'user_agent', 'device_id', 'request_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
            'is_sensitive_view' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new \LogicException('Audit records are append-only and cannot be modified.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new \LogicException('Audit records are append-only and cannot be deleted.');
    }
}
