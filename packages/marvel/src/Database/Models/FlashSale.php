<?php

namespace Marvel\Database\Models;

use Cviebrock\EloquentSluggable\Sluggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Translatable\HasTranslations;
use Marvel\Services\Pricing\ProductPricingService;
use Illuminate\Support\Str;


class FlashSale extends Model implements HasMedia, Sortable
{
    use HasTranslations, SoftDeletes, InteractsWithMedia, SortableTrait;

    protected $table = 'flash_sales';

    public $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    public array $translatable = ["title", "description"];
    public $fillable = [
        'title',
        'slug',
        'description',
        'start_date',
        'end_date',
        'status',
        'type',
        'discount',
        'max_discount_amount',
        'order',
    ];

    protected $casts = [
        'status' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

        public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'flash_sale_products')->withPivot('flash_sale_id', 'product_id');
    }

    /**
     * @return HasMany
     */
    public function flashSaleRequests(): HasMany
    {
        return $this->hasMany(FlashSaleRequests::class);
    }

    public function typeByLang()
    {
        $map = [
            'ar' => [
                'fixed_rate' => 'خصم من السعر بالقيمة',
                'percentage' => 'خصم بالنسبة المئوية',
                'final_price' => 'السعر النهائي',
            ],
            'en' => [
                'fixed_rate' => 'Fixed discount',
                'percentage' => 'Percentage discount',
                'final_price' => 'Final price',
            ],
        ];

        $locale = app()->getLocale();
        return $map[$locale][$this->type] ?? $this->type;
    }

    public function calcPrice($price)
    {
        return app(ProductPricingService::class)->calculateFlashSalePrice($this, $price);
    }

    /**
     * Determine if this flash sale is currently valid (active).
     *
     * @return bool
     */
    public function isValid(): bool
    {
        $today = today();

        return $this->status
            && (!$this->start_date || $this->start_date->lte($today))
            && (!$this->end_date || $this->end_date->gte($today));
    }


    public function scopeValid(Builder $query)
    {
        return $query->where('status', true)
            ->where(function ($query) {
                $query->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', today());
            })
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', today());
            });
    }
    public function scopeInvalid(Builder $query)
    {
        return $query->where(function ($query) {
            $query->where('status', false)
                ->orWhere(function ($query) {
                    $query->whereNotNull('start_date')
                        ->whereDate('start_date', '>', today());
                })

                ->orWhere(function ($query) {
                    $query->whereNotNull('end_date')
                        ->whereDate('end_date', '<', today());
                });
        });
    }
    public function scopeSearch($query, $field, $term, $locale)
    {
        return $query->where(function ($q) use ($field, $term, $locale) {
            $translatable = $this->translatable ?? [];
            if (in_array($field, $translatable)) {
                $q->where($field . '->' . $locale, 'like', "%$term%")
                    ->orWhere($field, 'like', "%$term%");
            } else {
                $q->where($field, 'like', "%$term%");
            }
        });
    }

    
}