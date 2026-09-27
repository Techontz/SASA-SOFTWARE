<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Templated and logged, so "we told them" is provable. */
class Communication extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'grievance_id', 'direction', 'channel',
        'template_key', 'recipient', 'subject', 'body', 'language', 'status',
        'failure_reason', 'sent_at', 'sent_by',
    ];

    protected $hidden = ['recipient'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'recipient' => 'encrypted'];
    }

    public function grievance()
    {
        return $this->belongsTo(Grievance::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
