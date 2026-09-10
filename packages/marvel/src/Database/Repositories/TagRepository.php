<?php


namespace Marvel\Database\Repositories;


use Marvel\Database\Models\Tag;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Marvel\Traits\MediaManager;





class TagRepository extends BaseRepository
{
    use MediaManager;

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name'        => 'like',

    ];

    protected $dataArray = [
        'name',
        'slug',
        'icon',
        'image',
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
        return Tag::class;
    }

    public function updateTag($request, $tag)
    {
        $data = $request->only($this->dataArray);
        if (isset($data['name'])) {
            $data['slug'] = $this->makeSlug($request, 'slug', $tag->id);
        }
        $tag->update($data);
        if ($request->has('products')) {
            $products = array_filter(array_map('intval', (array) $request->products), fn ($id) => $id > 0);
            $tag->products()->sync($products);
        }
        if ($request->has('image')) {
            if (!$this->updateSingleImage($request, 'image', $tag, 'tags', 'tags')) {
                throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
            }
        }
        if ($request->has('icon')) {
            if (!$this->updateSingleImage($request, 'icon', $tag, 'tags', 'tags')) {
                throw new HttpException(422, 'Logo upload failed, please check the file format or size.');
            }
        }
        return $this->findOrFail($tag->id);
    }
}