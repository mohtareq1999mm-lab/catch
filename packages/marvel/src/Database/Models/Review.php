<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Review extends Model implements HasMedia
{
    use SoftDeletes, InteractsWithMedia;

    protected $table = 'reviews';

    public $fillable = [
        'user_id',
        'product_id',
        'comment',
        'rating',
        'approved',
    ];





    /**
     * @return BelongsTo
     */
    public function product(): BelongsTo
    {
        return $this->BelongsTo(Product::class, 'product_id');
    }

    /**
     * @return belongsTo
     */
    public function user(): belongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get all of the reviews feedbacks.
     */
    public function feedbacks()
    {
        return $this->morphMany(Feedback::class, 'model');
    }

    public function abusive_reports()
    {
        return $this->morphMany(AbusiveReport::class, 'model');
    }

    /**
     * Positive feedback count of review .
     * @return int
     */
    public function getPositiveFeedbacksCountAttribute()
    {
        return $this->feedbacks()->wherePositive(1)->count();
    }

    /**
     * Negative feedback count of review .
     * @return int
     */
    public function getNegativeFeedbacksCountAttribute()
    {
        return $this->feedbacks()->whereNegative(1)->count();
    }

    /**
     * Get authenticated user feedback
     * @return object | null
     */
    public function getMyFeedbackAttribute()
    {
        if (auth()->user()) {
            return $this->feedbacks()->where('user_id', auth()->user()->id)->first();
        }
        return null;
    }

    /**
     * Count no of abusive reports in the review.
     * @return int
     */
    public function getAbusiveReportsCountAttribute()
    {
        return $this->abusive_reports()->count();
    }


    public function scopeApproved($query)
    {
        return $query->where('approved', true);
    }

    public function scopeNotApproved($query)
    {
        return $query->where('approved', false);
    }
}
