<?php

namespace App\Http\Resources;

use App\Support\LocalizedName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-order status transition options (computed, not a model).
 *
 * Expects resource array:
 *   current_status: ['code', 'name' => [en, ar]] | null
 *   flow: ['code', 'shipping_type'] | null
 *   statuses: list of [
 *     code, name, sort_order|null, transition_allowed, permitted, allowed,
 *     permission, reason|null, requires_inputs[]
 *   ]
 *
 * `allowed` is the AND of Flow validity and actor permission — never one
 * without the other. The GET response never authorizes anything: PATCH
 * revalidates everything server-side.
 */
class OrderStatusOptionsResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function payload(
        ?string $currentCode,
        ?array $currentName,
        ?array $flow,
        array $statuses,
    ): array {
        return [
            'current_status' => $currentCode === null ? null : [
                'code' => $currentCode,
                'name' => $currentName ?? ['en' => $currentCode, 'ar' => null],
            ],
            'flow' => $flow,
            'statuses' => $statuses,
        ];
    }

    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : [];

        return [
            'current_status' => $data['current_status'] ?? null,
            'flow' => $data['flow'] ?? null,
            'statuses' => $data['statuses'] ?? [],
        ];
    }

    /**
     * Build one candidate row (shared by controller; keeps flags consistent).
     *
     * @param  array<int, string>  $requiresInputs
     */
    public static function candidate(
        string $code,
        mixed $name,
        ?int $sortOrder,
        bool $transitionAllowed,
        bool $permitted,
        string $permission,
        ?string $reason,
        array $requiresInputs = [],
    ): array {
        $nameArray = $name instanceof \App\Models\OrderFlow\OrderStatus
            ? LocalizedName::for($name, 'name')
            : (is_array($name) ? $name : ['en' => (string) $name, 'ar' => null]);

        return [
            'code' => $code,
            'name' => $nameArray,
            'sort_order' => $sortOrder,
            'transition_allowed' => $transitionAllowed,
            'permitted' => $permitted,
            'allowed' => $transitionAllowed && $permitted,
            'permission' => $permission,
            'reason' => ($transitionAllowed && $permitted) ? null : $reason,
            'requires_inputs' => array_values($requiresInputs),
        ];
    }
}
