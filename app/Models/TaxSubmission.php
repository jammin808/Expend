<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A submission late submission points are counted on (FA 2021 Sch 24): a tax year's return ('annual', kept in step
 * with the year's filing facts) or a Making Tax Digital quarterly update ('quarterly'). See Domain\Tax\Submissions.
 */
class TaxSubmission extends Model
{
    public const KINDS = ['annual' => 'Tax return', 'quarterly' => 'Quarterly update'];

    protected $guarded = ['id'];

    protected $casts = [
        'period_start' => 'date', 'period_end' => 'date', 'due_on' => 'date', 'submitted_on' => 'date', 'excused' => 'boolean',
    ];
}
