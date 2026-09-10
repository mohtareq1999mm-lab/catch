<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceSequence extends Model
{
    protected $table = 'invoice_sequences';

    protected $primaryKey = 'series';

    public $incrementing = false;

    protected $fillable = [
        'series',
        'sequence_year',
        'last_sequence',
    ];
}
