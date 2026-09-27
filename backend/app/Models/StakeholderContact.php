<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StakeholderContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'stakeholder_id', 'name', 'role', 'phone', 'email',
        'preferred_language', 'is_primary', 'notes',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function stakeholder()
    {
        return $this->belongsTo(Stakeholder::class);
    }
}
