<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use HasFactory;

    protected $fillable = ['working_calendar_id', 'date', 'name', 'recurs_annually'];

    protected function casts(): array
    {
        return ['date' => 'date', 'recurs_annually' => 'boolean'];
    }

    public function calendar()
    {
        return $this->belongsTo(WorkingCalendar::class, 'working_calendar_id');
    }
}
