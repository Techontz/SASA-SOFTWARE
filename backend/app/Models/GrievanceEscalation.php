<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GrievanceEscalation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'organisation_id', 'project_id', 'grievance_id', 'from_level', 'to_level',
        'trigger', 'reason', 'escalated_by', 'escalated_to_id', 'escalated_to_role_id', 'escalated_at',
    ];

    protected function casts(): array
    {
        return ['escalated_at' => 'datetime', 'from_level' => 'integer', 'to_level' => 'integer'];
    }

    public function grievance()
    {
        return $this->belongsTo(Grievance::class);
    }

    public function escalatedTo()
    {
        return $this->belongsTo(User::class, 'escalated_to_id');
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'escalated_to_role_id');
    }
}
