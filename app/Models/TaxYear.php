<?php

namespace App\Models;

use App\Support\UkTaxYear;
use Illuminate\Database\Eloquent\Model;

/** A tax year the owner is tracking, with its filing status. */
class TaxYear extends Model
{
    public const STATUSES = [
        'outstanding' => 'Outstanding',
        'in_progress' => 'In progress',
        'ready_to_file' => 'Ready to file',
        'filed' => 'Filed',
        'amending' => 'Amending',
    ];

    protected $guarded = ['id'];

    protected $casts = [
        'starts_on' => 'date', 'ends_on' => 'date', 'filed_on' => 'date', 'locked_at' => 'datetime',
        'filed_turnover_pence' => 'integer', 'filed_expenses_pence' => 'integer', 'filed_profit_pence' => 'integer',
        'payments_on_account_paid_pence' => 'integer', 'payment_plan_from' => 'date',
    ];

    public static function track(string $label, string $status = 'outstanding'): self
    {
        $year = UkTaxYear::fromLabel($label);

        return static::query()->firstOrCreate(['label' => $year->label()], [
            'starts_on' => $year->startsOn(), 'ends_on' => $year->endsOn(), 'status' => $status,
        ]);
    }

    public function period(): UkTaxYear
    {
        return UkTaxYear::fromLabel($this->label);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
