<?php

namespace Marvel\Database\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Marvel\Database\Models\Country;

class CountryRepository
{
    public function paginate(int $perPage = 15, ?string $search = null, ?bool $status = null): LengthAwarePaginator
    {
        $query = Country::query();
        $this->applySearch($query, $search);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    public function allActive(): Collection
    {
        return Country::query()->active()->orderByDesc('id')->get();
    }

    public function findById(int $id, array $with = []): ?Country
    {
        return Country::query()->with($with)->find($id);
    }

    public function create(array $data): Country
    {
        return Country::create($data);
    }

    public function update(Country $country, array $data): Country
    {
        $country->update($data);

        return $country->refresh();
    }

    public function delete(Country $country): bool
    {
        return (bool)$country->delete();
    }

    public function bulkStatus(array $ids, bool $status): int
    {
        return Country::query()->whereIn('id', $ids)->update(['status' => $status]);
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