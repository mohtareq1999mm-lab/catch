<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\General;

use App\Http\Controllers\Controller;
use App\Models\ShipmentLabel;
use App\Services\Shipment\LabelStorageService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShipmentLabelController extends Controller
{
    public function __construct(private readonly LabelStorageService $labels) {}

    public function show(Request $request, string $uuid): StreamedResponse
    {
        $label = ShipmentLabel::query()->where('uuid', $uuid)->firstOrFail();
        $disk = $this->labels->disk();

        abort_unless($disk->exists($label->path), 404);

        return $disk->download($label->path, 'shipment-label-' . $label->uuid . '.' . $label->format, [
            'Content-Type' => $label->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
