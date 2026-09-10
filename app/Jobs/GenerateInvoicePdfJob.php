<?php

namespace App\Jobs;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateInvoicePdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 120, 300];
    public $timeout = 120;

    public function __construct(
        public Invoice $invoice,
    ) {
        $this->onQueue('meem-medium');
    }

    public function handle(): void
    {
        try {
            $data = $this->invoice->data;

            $filename = str_replace('/', '-', $this->invoice->invoice_number) . '.pdf';
            $relativePath = $filename;

            $disk = Storage::disk('public');

            if (!$disk->exists('invoices')) {
                $disk->makeDirectory('invoices');
            }

            // mPDF engine — proper Arabic shaping/RTL + mixed Arabic/English.
            $fontDir = storage_path('app/fonts');
            $defaultFontConfig = new \Mpdf\Config\ConfigVariables();
            $fontDirs = array_merge($defaultFontConfig->getDefaults()['fontDir'], [$fontDir]);
            $fontVariables = new \Mpdf\Config\FontVariables();
            $fontData = $fontVariables->getDefaults()['fontdata'] + [
                'segoeui' => [
                    'R' => 'segoeui.ttf',
                    'B' => 'segoeuib.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ];

            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'orientation' => 'P',
                'fontDir' => $fontDirs,
                'fontdata' => $fontData,
                'default_font' => 'segoeui',
                'tempDir' => storage_path('app/mpdf-temp'),
            ]);

            $mpdf->WriteHTML(
                view('pdf.invoice', ['invoice' => $this->invoice])->render()
            );

            $pdfContent = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
            $disk->put('invoices/' . $filename, $pdfContent);
            $pdfChecksum = md5($pdfContent);

            $this->invoice->update([
                'status' => 'ready',
                'pdf_path' => $filename,
                'pdf_checksum' => $pdfChecksum,
                'pdf_generated_at' => now(),
                'generation_attempts' => $this->invoice->generation_attempts + 1,
                'last_generation_error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->invoice->update([
                'status' => 'failed',
                'last_generation_error' => substr($e->getMessage(), 0, 1000),
                'generation_attempts' => $this->invoice->generation_attempts + 1,
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('PDF generation failed for invoice ' . $this->invoice->invoice_number, [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'order_id' => $this->invoice->order_id,
            'error' => $e->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
