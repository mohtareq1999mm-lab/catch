<?php

namespace Marvel\Database\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Marvel\Database\Models\City;
use Marvel\Database\Models\Governorate;

class CityRepository
{
    public function paginate(int $perPage = 15, ?string $search = null, ?int $governorateId = null): LengthAwarePaginator
    {
        $query = City::query();
        $this->applySearch($query, $search);

        if ($governorateId) {
            $query->where('governorate_id', $governorateId);
        }

        return $query->with(['governorate'])->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function all(?int $governorateId = null): Collection
    {
        $query = City::query();

        if ($governorateId) {
            $query->where('governorate_id', $governorateId);
        }

        return $query->orderByDesc('id')->get();
    }

    public function findById(int $id, array $with = []): ?City
    {
        return City::query()->with($with)->find($id);
    }

    public function create(array $data): City
    {
        $this->ensureGovernorateExists((int)$data['governorate_id']);

        return City::create($data);
    }

    public function update(City $city, array $data): City
    {
        if (isset($data['governorate_id'])) {
            $this->ensureGovernorateExists((int)$data['governorate_id']);
        }

        $city->update($data);

        return $city->refresh();
    }

    public function delete(City $city): bool
    {
        return (bool)$city->delete();
    }

    private function ensureGovernorateExists(int $governorateId): void
    {
        if (!Governorate::query()->whereKey($governorateId)->exists()) {
            throw new InvalidArgumentException('Governorate not found.');
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