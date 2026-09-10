<?php

namespace Marvel\Database\Repositories;

use Marvel\Database\Models\PickupLocation;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class PickupLocationRepository extends BaseRepository
{
    protected $fieldSearchable = [
        'store_name' => 'like',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    public function model()
    {
        return PickupLocation::class;
    }
}
