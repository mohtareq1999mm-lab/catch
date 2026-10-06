<?php

namespace App\Http\Controllers\Api\Admin\Wms;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Marvel\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * P9-1: shared foundation for WMS admin command endpoints.
 *
 * Thin adapter only: server-derived actor identity, warehouse scope
 * enforcement, and canonical error mapping. Never owns transactions,
 * locks, or domain transitions — those belong to domain services.
 */
abstract class WmsAdminController extends Controller
{
    use ApiResponse;

    /**
     * Server-derived actor. Never accept caller-supplied actor IDs over HTTP.
     */
    protected function actorId(): int
    {
        return (int) auth()->id();
    }

    /**
     * Enforce Spatie permission (route middleware, coarse gate) plus
     * object-level warehouse scope resolved from the loaded domain object.
     *
     * Reads should pass $asNotFound=true (404 anti-enumeration).
     * Writes must pass $asNotFound=false (403 via AuthorizationException).
     *
     * Null home warehouse, null target warehouse, and cross-warehouse
     * access all deny (fail-closed per D9-15 interim rule).
     *
     * @throws AuthorizationException
     * @throws NotFoundHttpException
     */
    protected function authorizeWarehouseScope(?int $warehouseId, string $permission, bool $asNotFound = false): void
    {
        try {
            app(WarehouseAccess::class)->denyUnless(auth()->user(), $warehouseId, $permission);
        } catch (AuthorizationException $e) {
            if ($asNotFound) {
                throw new NotFoundHttpException('Resource Not Found', $e);
            }

            throw $e;
        }
    }

    /**
     * Concurrency / claim / assignment / duplicate-replay conflict (409).
     */
    protected function conflict(string $message): never
    {
        throw new ConflictHttpException($message);
    }

    /**
     * Validation / illegal-transition / business-refusal (422).
     */
    protected function unprocessable(string $message): never
    {
        throw new UnprocessableEntityHttpException($message);
    }
}
