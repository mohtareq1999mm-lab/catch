<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\CorrectInvoiceRequest;
use App\Http\Requests\Invoice\DebitNoteRequest;
use App\Http\Resources\Invoice\AdminInvoiceCollection;
use App\Http\Resources\Invoice\AdminInvoiceResource;
use App\Http\Resources\Invoice\CustomerInvoiceCollection;
use App\Http\Resources\Invoice\InvoiceCollection;
use App\Http\Resources\Invoice\InvoiceResource;
use App\Models\Invoice;
use App\Models\DebitNote;
use App\Jobs\GenerateInvoicePdfJob;
use App\Services\Invoice\InvoiceService;
use App\Services\Invoice\InvoiceTimelineService;
use App\Services\Invoice\DebitNoteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Database\Models\Order;
use Marvel\Traits\ApiResponse;

class InvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private InvoiceService $invoiceService,
        private InvoiceTimelineService $timelineService,
        private DebitNoteService $debitNoteService,
    ) {
        $this->middleware('permission:' . Permission::VIEW_INVOICES, ['only' => ['index']]);
        $this->middleware('permission:' . Permission::VIEW_INVOICE, ['only' => ['show', 'showByUuid']]);
        $this->middleware('permission:' . Permission::REGENERATE_INVOICE, ['only' => ['regenerate']]);
        $this->middleware('permission:' . Permission::CORRECT_INVOICE, ['only' => ['correct']]);
        $this->middleware('permission:' . Permission::CANCEL_INVOICE, ['only' => ['cancel']]);
        $this->middleware('permission:' . Permission::ISSUE_DEBIT_NOTE, ['only' => ['issueDebitNote']]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('limit', 15), 100);

        $invoices = Invoice::query()
            ->with(['order', 'user'])
            ->when($request->has('search'), fn($q) => $q->where(function ($q) use ($request) {
                $search = $request->get('search');
                $q->where('invoice_number', 'like', "%{$search}%")
                  ->orWhereHas('order', fn($oq) => $oq->where('order_number', 'like', "%{$search}%"));
            }))
            ->when($request->has('status'), fn($q) => $q->where('status', $request->get('status')))
            ->when($request->has('order_id'), fn($q) => $q->where('order_id', (int) $request->get('order_id')))
            ->when($request->has('user_id'), fn($q) => $q->where('user_id', (int) $request->get('user_id')))
            ->when($request->has('invoice_series'), fn($q) => $q->where('invoice_series', $request->get('invoice_series')))
            ->when($request->has('currency'), fn($q) => $q->where('currency', $request->get('currency')))
            ->when($request->has('from'), fn($q) => $q->whereDate('created_at', '>=', $request->get('from')))
            ->when($request->has('to'), fn($q) => $q->whereDate('created_at', '<=', $request->get('to')))
            ->when($request->has('sort_by'), function ($q) use ($request) {
                $direction = $request->get('sort_direction', 'desc');
                $allowed = ['created_at', 'total', 'status', 'invoice_number'];
                $field = in_array($request->get('sort_by'), $allowed) ? $request->get('sort_by') : 'created_at';
                $q->orderBy($field, $direction === 'asc' ? 'asc' : 'desc');
            }, fn($q) => $q->orderBy('created_at', 'desc'))
            ->paginate($perPage);

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            new AdminInvoiceCollection($invoices)
        );
    }

    public function show(int $id): JsonResponse
    {
        $invoice = Invoice::with(['order.orderItems', 'transaction', 'user'])->findOrFail($id);

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            AdminInvoiceResource::make($invoice)
        );
    }

    public function showByUuid(string $uuid): JsonResponse
    {
        $invoice = Invoice::with(['order.orderItems', 'transaction', 'user'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            AdminInvoiceResource::make($invoice)
        );
    }

    /**
     * Customer invoice PDF VIEW via temporary signed URL — streams the real
     * PDF inline (browser renders it). Authorization = valid signature.
     */
    public function viewByUuidSigned(string $uuid)
    {
        return $this->streamCustomerInvoicePdf($uuid, 'inline');
    }

    /**
     * Customer PDF DOWNLOAD via temporary signed URL — streams the real PDF
     * as an attachment. Authorization = valid signature.
     */
    public function downloadByUuidSigned(string $uuid)
    {
        return $this->streamCustomerInvoicePdf($uuid, 'attachment', recordDownload: true);
    }

    /** Shared lookup + disk check + streaming for both signed customer flows. */
    private function streamCustomerInvoicePdf(string $uuid, string $disposition, bool $recordDownload = false)
    {
        $invoice = Invoice::query()->where('uuid', $uuid)->first();

        if (!$invoice) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        if (!$invoice->pdf_path) {
            return $this->apiResponse(INVOICE_PDF_NOT_GENERATED, 404, false);
        }

        $relativePath = 'invoices/' . $invoice->pdf_path;

        if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($relativePath)) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        if ($recordDownload) {
            $invoice->update(['downloaded_at' => $invoice->downloaded_at ?? now()]);
            $this->timelineService->recordDownloaded($invoice);
        }

        $filename = str_replace('/', '-', $invoice->pdf_path);

        return \Illuminate\Support\Facades\Storage::disk('public')->response(
            $relativePath,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            ]
        );
    }

    public function myInvoices(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('limit', 15), 100);

        $invoices = Invoice::query()
            ->where('user_id', $request->user()->id)
            ->with(['order'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            new CustomerInvoiceCollection($invoices)
        );
    }

    public function verify(string $uuid): JsonResponse
    {
        $result = $this->invoiceService->verifyInvoice($uuid);

        if ($result === null) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        if ($result['tampered']) {
            return $this->apiResponse(
                'Invoice verification failed',
                409,
                false,
                ['authentic' => false, 'tampered' => true]
            );
        }

        $invoice = $result['invoice'];

        $verificationUrl = url('/api/v1/general/invoices/verify/' . $uuid);

        $invoice->increment('verify_count');
        $invoice->update([
            'last_verified_at' => now(),
            'verified_at' => $invoice->verified_at ?? now(),
        ]);

        $this->timelineService->recordVerified($invoice);

        return $this->apiResponse(
            FETCH_DATA_SUCCESSFULLY,
            200,
            true,
            [
                'authentic' => true,
                'invoice' => InvoiceResource::make($invoice),
                'order' => [
                    'id' => $invoice->order?->id,
                    'order_number' => $invoice->order?->order_number,
                    'status' => $invoice->order?->status,
                    'payment_status' => $invoice->order?->payment_status,
                    'fulfillment_status' => $invoice->order?->fulfillment_status,
                ],
                'qr_content' => $verificationUrl,
            ]
        );
    }

    public function download(string $uuid)
    {
        return $this->pdfFileResponse($uuid, 'attachment', recordDownload: true);
    }

    /**
     * Stream the invoice PDF inline (browser view) — same security chain as
     * download, but without download bookkeeping.
     */
    public function view(string $uuid)
    {
        return $this->pdfFileResponse($uuid, 'inline');
    }

    private function pdfFileResponse(string $uuid, string $disposition, bool $recordDownload = false)
    {
        $invoice = Invoice::with('order')
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($invoice->user_id !== request()->user()->id
            && !request()->user()->can(Permission::VIEW_INVOICE_DOWNLOAD)) {
            return $this->apiResponse(NOT_FOUND, 404, false);
        }

        if (!$invoice->pdf_path) {
            return $this->apiResponse(
                INVOICE_PDF_NOT_GENERATED,
                404,
                false,
                [
                    'status' => $invoice->status,
                    'pdf_generated_at' => $invoice->pdf_generated_at?->toIso8601String(),
                ]
            );
        }

        if ($recordDownload) {
            $invoice->update(['downloaded_at' => $invoice->downloaded_at ?? now()]);
            $this->timelineService->recordDownloaded($invoice);
        }

        $filename = str_replace('/', '-', $invoice->pdf_path);

        return \Illuminate\Support\Facades\Storage::disk('public')->response(
            'invoices/' . $invoice->pdf_path,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => $disposition . '; filename="' . $filename . '"',
            ]
        );
    }

    /**
     * Customer download — authenticated OWNER only, no permission involved.
     * Streams the actual PDF (never JSON), after verifying the file really
     * exists on the configured disk.
     */
    public function regenerate(int $id): JsonResponse
    {
        $invoice = Invoice::query()->findOrFail($id);

        if (!in_array($invoice->status, ['failed', 'ready', 'generated'], true)) {
            return $this->apiResponse(ERROR_ADDING_ITEMS_TO_ORDER, 422, false);
        }

        $invoice->update([
            'status' => 'pdf_generating',
            'generation_attempts' => $invoice->generation_attempts + 1,
            'last_generation_error' => null,
        ]);

        $this->timelineService->recordPdfRegenerated($invoice);

        GenerateInvoicePdfJob::dispatch($invoice);

        return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [
            'invoice_id' => $invoice->id,
            'status' => 'pdf_generating',
        ]);
    }

    public function correct(CorrectInvoiceRequest $request, int $id): JsonResponse
    {
        try {
            $correction = $this->invoiceService->correctInvoice(
                $id,
                $request->validated('overrides', []),
                $request->validated('reason'),
                $request->user()->id,
            );

            return $this->apiResponse(
                'Invoice corrected successfully',
                200,
                true,
                AdminInvoiceResource::make($correction)
            );
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate(['reason' => 'required|string|max:500']);

        try {
            $invoice = $this->invoiceService->cancelInvoice(
                $id,
                $request->input('reason'),
                $request->user()->id,
            );

            return $this->apiResponse(
                'Invoice cancelled successfully',
                200,
                true,
                AdminInvoiceResource::make($invoice)
            );
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return $this->apiResponse($e->getMessage(), 422, false);
        }
    }

    public function issueDebitNote(DebitNoteRequest $request, int $id): JsonResponse
    {
        $invoice = Invoice::query()->findOrFail($id);

        if (!in_array($invoice->status, ['generated', 'ready', 'verified', 'downloaded', 'printed'], true)) {
            return $this->apiResponse(__('message.' . INVOICE_DEBIT_NOTE_NOT_ALLOWED, ['status' => $invoice->status]), 422, false);
        }

        $debitNote = $this->debitNoteService->generate(
            $invoice,
            (float) $request->validated('amount'),
            $request->validated('reason'),
            $request->user()->id,
        );

        return $this->apiResponse(DEBIT_NOTE_ISSUED_SUCCESSFULLY, 201, true, $debitNote);
    }
}