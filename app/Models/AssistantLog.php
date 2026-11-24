<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssistantLog extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['tool_calls' => 'array', 'unmatched_figures' => 'array', 'notes' => 'array'];
}
