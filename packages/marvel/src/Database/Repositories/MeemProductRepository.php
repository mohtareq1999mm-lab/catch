<?php

namespace Marvel\Database\Repositories;

use Marvel\Database\Models\MeemProduct;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class MeemProductRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name' => 'like',
        'category' => 'like',
    ];

    protected $dataArray = [
        'name',
        'category',
        'description',
        'image_url',
        'price',
        'url',
    ];

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return MeemProduct::class;
    }
}
