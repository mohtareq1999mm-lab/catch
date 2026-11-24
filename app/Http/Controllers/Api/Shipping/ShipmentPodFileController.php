<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Shipping\ShipmentPod;
use App\Services\Shipping\ProofOfDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class ShipmentPodFileController extends Controller
{
    public function __construct(private readonly ProofOfDeliveryService $proofOfDeliveryService)
    {
    }

    public function show(Request $request, ShipmentPod $pod): Response
    {
        if (! URL::hasValidSignature($request)) {
            abort(403);
        }

        return $this->proofOfDeliveryService->fileResponse($pod);
    }
}
