<?php

namespace Marvel\Database\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\ShippingPrice;

class ShippingPriceRepository
{
    public function paginate(int $perPage = 15, ?bool $status = null, ?int $governorateId = null): LengthAwarePaginator
    {
        $query = ShippingPrice::query();

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($governorateId) {
            $query->where('governorate_id', $governorateId);
        }

        return $query->with(['governorate.country'])->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function findById(int $id, array $with = []): ?ShippingPrice
    {
        return ShippingPrice::query()->with($with)->find($id);
    }

    public function findByGovernorateId(int $governorateId): ?ShippingPrice
    {
        return ShippingPrice::query()->where('governorate_id', $governorateId)->first();
    }

    public function create(array $data): ShippingPrice
    {
        $this->ensureGovernorateExists((int) $data['governorate_id']);
        $this->ensureGovernorateHasNoPrice((int) $data['governorate_id']);

        return ShippingPrice::create($data);
    }

    public function update(ShippingPrice $shippingPrice, array $data): ShippingPrice
    {
        if (isset($data['governorate_id']) && (int) $data['governorate_id'] !== (int) $shippingPrice->governorate_id) {
            $this->ensureGovernorateExists((int) $data['governorate_id']);
            $this->ensureGovernorateHasNoPrice((int) $data['governorate_id'], $shippingPrice->id);
        }

        $shippingPrice->update($data);

        return $shippingPrice->refresh();
    }

    public function delete(ShippingPrice $shippingPrice): bool
    {
        return (bool) $shippingPrice->delete();
    }

    public function bulkStatus(array $ids, bool $status): int
    {
        return ShippingPrice::query()->whereIn('id', $ids)->update(['status' => $status]);
    }

    private function ensureGovernorateExists(int $governorateId): void
    {
        if (!Governorate::query()->whereKey($governorateId)->exists()) {
            throw new InvalidArgumentException('Governorate not found.');
        }
    }

    private function ensureGovernorateHasNoPrice(int $governorateId, ?int $exceptId = null): void
    {
        $query = ShippingPrice::query()->where('governorate_id', $governorateId);

        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException('This governorate already has a shipping price.');
        }
    }
}
