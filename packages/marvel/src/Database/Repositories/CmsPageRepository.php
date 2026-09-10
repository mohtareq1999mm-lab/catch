<?php

declare(strict_types=1);

namespace Marvel\Database\Repositories;

use Marvel\Database\Models\CmsPage;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;

class CmsPageRepository extends BaseRepository
{
    /**
     * @var array<string, string>
     */
    protected $fieldSearchable = [
        'slug' => 'like',
        'title' => 'like',
    ];

    /**
     * @var string[]
     */
    protected $dataArray = [
        'slug',
        'title',
        'content',
        'meta',
    ];

    public function boot(): void
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            // Criteria push failures are non-blocking.
        }
    }

    /**
     * Configure the Model.
     */
    public function model(): string
    {
        return CmsPage::class;
    }
}

