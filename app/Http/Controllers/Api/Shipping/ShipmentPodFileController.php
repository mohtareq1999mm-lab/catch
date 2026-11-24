<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Shipping;

use App\Http\Controllers\Controller;
use App\Models\Shipping\ShipmentPod;
use App\Services\Shipping\ProofOfDeliveryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Marvel\Enums\Permission;
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

        $user = $request->user();
        $signedUid = $request->query('uid');
        if (! $user || $signedUid === null || (string) $user->getAuthIdentifier() !== (string) $signedUid) {
            abort(403);
        }

        if (! $this->canAccessPod($user, $pod)) {
            abort(403);
        }

        return $this->proofOfDeliveryService->fileResponse($pod);
    }

    private function canAccessPod($user, ShipmentPod $pod): bool
    {
        if ((int) $pod->uploaded_by === (int) $user->getAuthIdentifier()) {
            return true;
        }

        if ($user->can(Permission::VIEW_SHIPMENTS)) {
            return true;
        }

        $pod->loadMissing('shipment.order');
        $order = $pod->shipment?->order;
        $ownerId = $order?->customer_id ?? $order?->user_id ?? null;

        return $ownerId !== null && (int) $ownerId === (int) $user->getAuthIdentifier();
    }
}
