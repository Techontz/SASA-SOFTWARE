<?php

namespace App\Models\Pap;

use App\Models\Concerns\HasReference;

class PapPayment extends PapRecord
{
    use HasReference;

    public const REFERENCE_KEY = 'pap_payment';

    public const AUDIT_NAME = 'pap_payment';

    public const INPUT_FIELDS = [
        'compensation_agreement_number', 'bank_name', 'bank_account_number', 'mobile_money_number',
        'amount_approved', 'amount_paid', 'payment_date', 'payment_method', 'receipt_number',
        'acknowledgement_signed', 'witness_name', 'payment_status', 'comments',
    ];

    public const DERIVED_FIELDS = ['outstanding_amount'];

    public const SENSITIVE_FIELDS = ['bank_account_number', 'mobile_money_number'];

    protected $table = 'pap_payments';

    protected array $auditExcluded = ['bank_account_number', 'mobile_money_number'];

    protected function casts(): array
    {
        return [
            'bank_account_number' => 'encrypted',
            'mobile_money_number' => 'encrypted',
            'amount_approved' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'payment_date' => 'date',
            'acknowledgement_signed' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }
}
