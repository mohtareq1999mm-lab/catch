<?php

namespace App\Models\OrderFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Translatable\HasTranslations;

/**
 * Flow Input definition (belongs to an OrderFlow).
 *
 * Generic widget types only (text|number|boolean|date|select|
 * multi_select); domain binding via $source
 * (countries|governorates|warehouses|pickup_locations). Never add
 * domain-primitive types (country, city, ...) — extend sources instead.
 *
 * required_at: 'checkout' | 'transition:<status_code>'.
 */
class FlowInput extends Model
{
    use HasTranslations;

    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_DATE = 'date';

    public const TYPE_SELECT = 'select';

    public const TYPE_MULTI_SELECT = 'multi_select';

    public const TYPES = [
        self::TYPE_TEXT,
        self::TYPE_NUMBER,
        self::TYPE_BOOLEAN,
        self::TYPE_DATE,
        self::TYPE_SELECT,
        self::TYPE_MULTI_SELECT,
    ];

    public const SOURCE_COUNTRIES = 'countries';

    public const SOURCE_GOVERNORATES = 'governorates';

    public const SOURCE_WAREHOUSES = 'warehouses';

    public const SOURCE_PICKUP_LOCATIONS = 'pickup_locations';

    public const SOURCES = [
        self::SOURCE_COUNTRIES,
        self::SOURCE_GOVERNORATES,
        self::SOURCE_WAREHOUSES,
        self::SOURCE_PICKUP_LOCATIONS,
    ];

    public const REQUIRED_AT_CHECKOUT = 'checkout';

    protected $table = 'flow_inputs';

    protected $fillable = [
        'flow_id',
        'key',
        'label',
        'placeholder',
        'help_text',
        'type',
        'source',
        'required',
        'required_at',
        'sort_order',
        'validation',
        'is_active',
    ];

    public array $translatable = ['label', 'placeholder', 'help_text'];

    protected $casts = [
        'label' => 'array',
        'placeholder' => 'array',
        'help_text' => 'array',
        'required' => 'boolean',
        'sort_order' => 'integer',
        'validation' => 'array',
        'is_active' => 'boolean',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(OrderFlow::class, 'flow_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * True when this input is demanded in the given context
     * ('checkout' or 'transition:<status_code>').
     */
    public function isRequiredInContext(string $context): bool
    {
        if (!$this->required || !$this->is_active) {
            return false;
        }

        return $this->required_at === $context;
    }
}
