<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SectionTypeSetting extends Model
{
    protected $table = 'section_type_settings';

    protected $fillable = ['section_type_id', 'setting_key', 'value'];

    protected $casts = ['value' => 'array'];

    public function sectionType(): BelongsTo
    {
        return $this->belongsTo(SectionType::class, 'section_type_id', 'id');
    }
}
