<?php


namespace Marvel\Database\Repositories;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeValue;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Resources\AttributeResource;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AttributeRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name' => 'like',
    ];

    protected $dataArray = [
        'name',
        'slug',
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
        return Attribute::class;
    }

    public function storeAttribute($request)
    {
        try {
            DB::beginTransaction();
            $request['slug'] = $this->makeSlug($request);
            $attribute = $this->create($request->only($this->dataArray));
            if (isset($request['values']) && count($request['values'])) {
                foreach ($request['values'] as  $value) {
                    AttributeValue::create([
                        'value' => $value['value'],
                        'attribute_id' => $attribute->id,
                    ]);
                }
            }
            DB::commit();
            return $attribute->load(['values']);
        } catch (\Throwable $th) {
            DB::rollBack();
            throw new HttpException(400, COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    public function updateAttribute($request, $attribute)
    {
        try {
            DB::beginTransaction();

            $request['slug'] = $this->makeSlug($request, 'slug', $attribute->id);

            $attribute->update($request->only($this->dataArray));
            if (isset($request['values']) && count($request['values'])) {
                $existingValues = $attribute->values()->get()->keyBy('slug');
                $incomingSlugs = [];

                foreach ($request['values'] as $value) {
                    $valueContent = $value['value'] ?? '';
                    $slugSource = is_array($valueContent)
                        ? ($valueContent['en'] ?? reset($valueContent))
                        : $valueContent;
                    $slug = Str::slug($slugSource);
                    $incomingSlugs[] = $slug;

                    if (!isset($existingValues[$slug])) {
                        AttributeValue::create([
                            'value' => $valueContent,
                            'attribute_id' => $attribute->id,
                        ]);
                    }
                }

                $attribute->values()->whereNotIn('slug', $incomingSlugs)->delete();
            }

            DB::commit();

            $attributeUpdated =  $this->with(['values'])->findOrFail($attribute->id);
            return $attributeUpdated;
        } catch (\Throwable $th) {
            DB::rollBack();
            throw new HttpException(400, COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }
}