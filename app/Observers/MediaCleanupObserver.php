<?php

namespace App\Observers;

use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;

class MediaCleanupObserver
{
    public function deleting(HasMedia $model): void
    {
        if (in_array(SoftDeletes::class, class_uses_recursive($model))) {
            return;
        }

        $model->media()->cursor()->each(fn ($media) => $media->delete());
    }

    public function forceDeleting(HasMedia $model): void
    {
        $model->media()->cursor()->each(fn ($media) => $media->delete());
    }
}
