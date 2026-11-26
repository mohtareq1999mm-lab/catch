<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RequeueShippingOutboxRequest;
use App\Models\Shipping\ShippingOutbox;
use App\Services\Shipment\Outbox\ShippingOutboxService;
use Illuminate\Http\JsonResponse;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * #178: operator surface for the shipping outbox.
 *
 * Manual requeue is deliberately separate from automatic recovery: it requires
 * an authenticated admin, a reason, and produces an audit record. It never
 * calls the provider inline.
 */
class ShippingOutboxController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShippingOutboxService $outbox) {}

    public function requeue(RequeueShippingOutboxRequest $request, int $outbox): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        // Role/type is evaluated first: hasPermissionTo() throws when the
        // permission has not been seeded, which must not prevent an admin from
        // using the recovery surface.
        $allowed = in_array($user->type ?? null, ['admin', 'super-admin'], true)
            || in_array($user->role ?? null, ['admin', 'super-admin'], true);

        if (! $allowed) {
            try {
                if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('manage-shipping-outbox')) {
                    $allowed = true;
                } elseif (method_exists($user, 'can') && $user->can('manage-shipping-outbox')) {
                    $allowed = true;
                }
            } catch (HttpException $e) {
                throw $e;
            } catch (\Throwable) {
                $allowed = false;
            }
        }

        if (! $allowed) {
            abort(403, 'Forbidden. Missing required permission: manage-shipping-outbox.');
        }

        $row = ShippingOutbox::query()->findOrFail($outbox);
        $requeued = $this->outbox->requeue($row, (int) $user->getAuthIdentifier(), $request->string('reason')->toString());

        return $this->apiResponse(
            __('message.MESSAGE.SHIPPING_OUTBOX_REQUEUED'),
            200,
            true,
            ['id' => $requeued->id, 'status' => $requeued->status->value],
        );
    }
}
