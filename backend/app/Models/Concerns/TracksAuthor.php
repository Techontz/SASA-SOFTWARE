<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait TracksAuthor
{
    public static function bootTracksAuthor(): void
    {
        static::creating(function (Model $model) {
            $userId = auth()->id();

            if ($userId && empty($model->created_by)) {
                $model->created_by = $userId;
            }

            if ($userId && empty($model->updated_by)) {
                $model->updated_by = $userId;
            }
        });

        static::updating(function (Model $model) {
            if ($userId = auth()->id()) {
                $model->updated_by = $userId;
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
