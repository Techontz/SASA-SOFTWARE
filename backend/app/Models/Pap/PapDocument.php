<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapDocument extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_document';

    public const AUDIT_NAME = 'pap_document';

    public const INPUT_FIELDS = [
        'document_type', 'document_number', 'issue_date', 'expiry_date', 'digital_file_name',
        'storage_location', 'verified_by', 'verification_date',
    ];

    public const DERIVED_FIELDS = [];

    public const SENSITIVE_FIELDS = [];

    protected $table = 'pap_documents';

    /** A NIDA card's document number IS the National ID; keep it out of the audit trail. */
    protected array $auditExcluded = ['document_number'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'verification_date' => 'date',
            'archived_at' => 'datetime',
        ];
    }
}
