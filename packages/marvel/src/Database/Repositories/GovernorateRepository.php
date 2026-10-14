<?php

namespace Marvel\Database\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\ShippingPrice;

class GovernorateRepository
{
    public function paginate(int $perPage = 15, ?string $search = null, ?bool $status = null, ?int $countryId = null): LengthAwarePaginator
    {
        $query = Governorate::query();
        $this->applySearch($query, $search);

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($countryId) {
            $query->where('country_id', $countryId);
        }

        return $query->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function allActive(?int $countryId = null): Collection
    {
        $query = Governorate::query()->with('shippingPrice')->active();

        if ($countryId) {
            $query->where('country_id', $countryId);
        }

        return $query->orderByDesc('id')->get();
    }

    public function findById(int $id, array $with = []): ?Governorate
    {
        return Governorate::query()->with($with)->find($id);
    }

    public function create(array $data): Governorate
    {
        $this->ensureCountryExists((int)$data['country_id']);

        return DB::transaction(function () use ($data) {
            $shippingData = $data['shipping_price'] ?? null;

            // remove nested shipping_price before creating governorate
            $govData = $data;
            unset($govData['shipping_price']);

            $governorate = Governorate::create($govData);

            if (! empty($shippingData) && is_array($shippingData)) {
                // attach governorate_id and create shipping price via relation
                $shippingData['governorate_id'] = $governorate->id;
                ShippingPrice::create($shippingData);
            }

            return $governorate->refresh();
        });
    }

    public function update(Governorate $governorate, array $data): Governorate
    {
        if (isset($data['country_id'])) {
            $this->ensureCountryExists((int)$data['country_id']);
        }
        return DB::transaction(function () use ($governorate, $data) {
            $shippingData = $data['shipping_price'] ?? null;

            $govData = $data;
            unset($govData['shipping_price']);

            $governorate->update($govData);

            if (! empty($shippingData) && is_array($shippingData)) {
                $existing = $governorate->shippingPrice()->first();

                if ($existing) {
                    $existing->update($shippingData);
                } else {
                    $shippingData['governorate_id'] = $governorate->id;
                    ShippingPrice::create($shippingData);
                }
            }

            return $governorate->refresh();
        });
    }

    public function delete(Governorate $governorate): bool
    {
        if ($governorate->cities()->exists()) {
            throw new InvalidArgumentException('Cannot delete a governorate that has cities.');
        }

        return (bool)$governorate->delete();
    }

    public function bulkStatus(array $ids, bool $status): int
    {
        return Governorate::query()->whereIn('id', $ids)->update(['status' => $status]);
    }

    private function ensureCountryExists(int $countryId): void
    {
        if (!Country::query()->whereKey($countryId)->exists()) {
            throw new InvalidArgumentException('Country not found.');
        }
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        if (blank($search)) {
            return;
        }

        $search = mb_strtolower($search);

        $query->where(function ($q) use ($search) {
            $q->whereRaw(
                'LOWER(name->>"$.en") LIKE ?',
                ["%{$search}%"]
            )->orWhereRaw(
                'LOWER(name->>"$.ar") LIKE ?',
                ["%{$search}%"]
            );
        });
    }
}
