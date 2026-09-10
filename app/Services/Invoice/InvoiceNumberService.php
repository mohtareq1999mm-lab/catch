<?php

namespace App\Services\Invoice;

use App\Models\InvoiceSequence;
use Illuminate\Support\Facades\DB;

class InvoiceNumberService
{
    public function generateNext(string $series = 'INV'): array
    {
        $year = (int) now()->year;

        return DB::transaction(function () use ($series, $year) {
            $seq = InvoiceSequence::lockForUpdate()
                ->where('series', $series)
                ->where('sequence_year', $year)
                ->first();

            if (!$seq) {
                $seq = InvoiceSequence::create([
                    'series' => $series,
                    'sequence_year' => $year,
                    'last_sequence' => 0,
                ]);
            }

            $seq->increment('last_sequence');

            $number = sprintf('%s-%d-%06d', $series, $year, $seq->last_sequence);

            return [
                'number' => $number,
                'series' => $series,
                'sequence' => (int) $seq->last_sequence,
                'year' => $year,
            ];
        });
    }
}
