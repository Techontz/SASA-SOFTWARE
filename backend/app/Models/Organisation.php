<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** The hard tenant boundary. */
class Organisation extends Model
{
    use Archivable, Auditable, HasFactory;

    public const AUDIT_NAME = 'organisation';

    protected $fillable = [
        'name', 'slug', 'country', 'timezone', 'contact_email', 'contact_phone',
        'logo_path', 'brand_color', 'status',
    ];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function roles()
    {
        return $this->hasMany(Role::class);
    }

    public function configurations()
    {
        return $this->hasMany(Configuration::class);
    }
}
