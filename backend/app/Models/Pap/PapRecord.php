<?php

namespace App\Models\Pap;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksAuthor;
use App\Models\Pap;
use Illuminate\Database\Eloquent\Model;

/**
 * A child record of a PAP (Parts B–J and M of the register). Each part is its
 * own table; this base carries what they share: tenancy, audit, archiving and
 * the field lists the generic section endpoints and resource read from.
 */
abstract class PapRecord extends Model
{
    use Archivable, Auditable, BelongsToTenant, TracksAuthor;

    /** @var array<int,string> fields a person types in */
    public const INPUT_FIELDS = [];

    /** @var array<int,string> fields the server computes — never accepted from a request */
    public const DERIVED_FIELDS = [];

    /** @var array<int,string> fields absent unless the viewer holds pap.view_sensitive */
    public const SENSITIVE_FIELDS = [];

    protected $guarded = ['id', 'reference', 'created_by', 'updated_by', 'archived_at'];

    public function pap()
    {
        return $this->belongsTo(Pap::class);
    }

    public function auditReference(): ?string
    {
        return $this->reference ?? $this->pap?->reference;
    }
}
