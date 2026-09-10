# Request Flows — Tag Module

## Flow 1: List Tags (Admin)

```
Client → GET /api/v1/tags?language=en&limit=15&page=1
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-tags] middleware → check Spatie permission
         ↓
    TagController@index(Request)
         ↓
    $language = $request->language ?? DEFAULT_LANGUAGE
         ↓
    TagRepository
      → where('language', $language)
      → with(['type'])
      → paginate($limit)
         ↓
    TagResource::collection($tags) → transform
         ↓
    Extract pagination meta from resource response
         ↓
    Return: { status:200, message:FETCH_DATA_SUCCESSFULLY, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create Tag (Admin)

```
Client → POST /api/v1/tags (multipart/form-data)
         ↓
    [auth:sanctum] middleware
         ↓
    [permission:create-tags] middleware
         ↓
    TagCreateRequest → validation:
      - name: required, array
      - name.*: required, string, max:150, UniqueTranslationRule::for('tags')
      - icon: nullable, string
      - image: nullable, image
         ↓
    Fail? → 422 with field errors under { message, errors }
         ↓
    TagController@store(TagCreateRequest)
         ↓
    $validatedData = $request->validated()
         ↓
    Generate slug via $this->repository->makeSlug($request)
      → makeSlug() extracts name (prefers 'en' locale if array)
      → globalSlugify() generates unique slug
         ↓
    $this->repository->create(['slug' => $slug, 'name' => $validatedData['name']])
         ↓
    If image → uploadSingleImage($request, 'image', $tag, 'tags', 'tags')
      → Uploads to 'tags' collection on 'tags' disk
      → On failure: HttpException(422, 'Image upload failed')
         ↓
    If icon → uploadSingleImage($request, 'icon', $tag, 'tags', 'tags')
      → Uploads to 'tags' collection on 'tags' disk
      → On failure: HttpException(422, 'Icon upload failed')
         ↓
    Return: new TagResource($tag)
```

## Flow 3: Show Tag (Admin)

```
Client → GET /api/v1/tags/1
         ↓
    [auth:sanctum] → [permission:view-tags]
         ↓
    TagController@show(Request, $params)
         ↓
    $language = $request->language ?? DEFAULT_LANGUAGE
         ↓
    Is numeric?
      ├─ Yes → where('id', $params)->with(['type'])->firstOrFail()
      └─ No  → where('slug', $params)->where('language', $language)->with(['type'])->firstOrFail()
         ↓
    Found? → Return: new TagResource($tag)
    Not found? → MarvelException(NOT_FOUND) → 404
```

## Flow 4: Update Tag (Admin)

```
Client → PUT /api/v1/tags/1 (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:update-tags]
         ↓
    TagUpdateRequest → validation:
      - name: sometimes, array
      - name.*: sometimes, string, max:150, UniqueTranslationRule::for('tags', 'name')->ignore($id)
      - icon: nullable, string
      - image: nullable, image
         ↓
    TagController@update(TagUpdateRequest, $id)
         ↓
    $request->merge(['id' => $id])
         ↓
    tagUpdate($request) [public]
      → $this->repository->findOrFail($request->id)
      → $this->repository->updateTag($request, $tag)
         ↓
    TagRepository::updateTag($request, $tag):
      ├─ $data = $request->only(['name', 'slug', 'icon', 'image'])
      ├─ If name changed → regenerate slug via makeSlug($request, 'slug', $tag->id)
      ├─ $tag->update($data)
      ├─ If image → updateSingleImage() [clears + uploads]
      ├─ If icon → updateSingleImage() [clears + uploads]
      └─ Return $this->findOrFail($tag->id)
         ↓
    Return: TagResource (from TagController)
```

## Flow 5: Delete Tag (Admin)

```
Client → DELETE /api/v1/tags/1
         ↓
    [auth:sanctum] → [permission:delete-tags]
         ↓
    TagController@destroy($id)
         ↓
    $this->repository->findOrFail($id)
         ↓
    Found? → $tag->delete() [hard delete]
      ├─ Pivot records cascade deleted (FK ON DELETE CASCADE)
      └─ Media files NOT cleaned up
         ↓
    Return: true (raw boolean)
    Not found? → MarvelException(NOT_FOUND) → 404
         ↓
    On MarvelException → throw MarvelException(COULD_NOT_DELETE_THE_RESOURCE)
```
