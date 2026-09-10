# Request Flows — Attribute Module (CRUD)

## Flow 1: List Attributes

```
Client → GET /api/v1/attributes?search=size&page=1&limit=15
         ↓
    [permission:view-attributes] middleware
         ↓
    AttributeController@index(Request)
         ↓
    $this->repository->with('values')
         ↓
    If order → orderBy($order, $sortedBy)
         ↓
    paginate($limit) + RequestCriteria (search by name)
         ↓
    AttributeResource::collection($attributes)
      └─ name → getTranslation('name', locale)
         ↓
    Return: { status:200, message, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create Attribute

```
Client → POST /api/v1/attributes
         ↓
    [permission:create-attribute] middleware
         ↓
    AttributeRequest → validation:
      - name: required, array
      - name.en: required, string, min:2, max:50, unique
      - name.ar: required, string, min:2, max:50, unique
      - values: sometimes, array
      - values.*.value.en: required, string, min:2, max:50
      - values.*.value.ar: required, string, min:2, max:50
         ↓
    Fail? → 422 with field errors
         ↓
    AttributeController@store(AttributeRequest)
         ↓
    AttributeRepository::storeAttribute($request)
         ↓
    DB::beginTransaction()
      ├─ Generate slug via makeSlug($request)
      │    └─ Source: name.en → Str::slug()
      ├─ Attribute::create(['name', 'slug'])
      └─ If values[]: for each → AttributeValue::create(...)
         ↓
    DB::commit()
         ↓
    $attribute->load(['values'])
         ↓
    AttributeResource::make($attribute)
         ↓
    Return: { status:201, message:ATTRIBUTE_CREATED_SUCCESSFULLY, success:true, data }
```

## Flow 3: Show Attribute

```
Client → GET /api/v1/attributes/1  OR  GET /api/v1/attributes/size
         ↓
    [permission:view-attributes] middleware
         ↓
    AttributeController@show(Request, $params)
         ↓
    is_numeric($params)?
      ├─ Yes → where('id', (int)$params)
      └─ No  → where('slug', $params)
         ↓
    $this->repository->with('values')->firstOrFail()
         ↓
    AttributeResource::make($attribute)
      └─ name → getRawOriginal('name')  (raw JSON)
         ↓
    Found? → Return: { status:200, message, success:true, data }
    Not found? → MarvelException(NOT_FOUND) → 404
```

## Flow 4: Update Attribute

```
Client → PUT /api/v1/attributes/1
         ↓
    [permission:update-attribute] middleware
         ↓
    AttributeRequest → validation (unique ignores current ID)
         ↓
    AttributeController@update(AttributeRequest, $id)
         ↓
    $request->id = $id
         ↓
    updateAttribute($request) [private]
      → $this->repository->with('values')->findOrFail($id)
      → $this->repository->updateAttribute($request, $attribute)
         ↓
    Regenerate slug
         ↓
    $attribute->update(['name', 'slug'])
         ↓
    If values[]: sync
      ├─ Build $incomingSlugs from request
      ├─ Create values with new slugs
      └─ Delete values with slugs not in incoming list
         ↓
    AttributeResource::make($attribute)
         ↓
    Return: { status:200, message:ATTRIBUTE_UPDATED_SUCCESSFULLY, success:true, data }
```

## Flow 5: Delete Attribute

```
Client → DELETE /api/v1/attributes/1
         ↓
    [permission:delete-attribute] middleware
         ↓
    AttributeController@destroy(Request, $id)
         ↓
    $request->id = $id
         ↓
    deleteAttribute($request)
      → $this->repository->findOrFail($request->id)
      → $attribute->delete()
         ↓
    FK CASCADE:
      ├─ attribute_values WHERE attribute_id = $id → DELETED
      └─ attribute_product WHERE attribute_value_id IN (...) → DELETED
         ↓
    Return: { status:200, message:ATTRIBUTE_DELETED_SUCCESSFULLY, success:true }
```
