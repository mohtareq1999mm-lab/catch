<?php

namespace App\Models;

use App\Enums\SiteReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\User;

class SiteReview extends Model
{
    use HasFactory;

    protected $table = 'site_reviews';

    protected $fillable = [
        'user_id',
        'rating',
        'title',
        'comment',
        'status',
        'moderated_by',
        'moderated_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'status' => SiteReviewStatus::class,
        'moderated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }
}
