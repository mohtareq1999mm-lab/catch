# Data Flow - Activity Log Feature

## Flow 1: Entity CRUD → Observer → Log

```
User performs action (e.g., creates a product)
  |
  v
Product::create([...])
  |
  v
ProductObserver::created(Product)
  |
  +-- Auth::id() → causerId (null if unauthenticated)
  +-- Build properties array (changed fields, product_name, etc.)
  +-- Build description string (translated via __('activity.product_created'))
  |
  v
LogActivityJob::dispatch(
    subjectType: 'Marvel\Models\Product',
    subjectId: 1,
    causerId: 1,
    event: 'created',
    logName: 'products',
    description: 'Product created',
    properties: ['product_name' => 'Wireless Headphones']
  )
  |  (Queue: medium)
  |
  v
LogActivityJob::handle()
  |
  +-- $subject = Product::find(1)  // May fail if deleted
  +-- $causer = User::find(1)
  |
  +-- activity('products')
  |     ->performedOn($subject)
  |     ->withProperties(['product_name' => ...])
  |     ->event('created')
  |     ->causedBy($causer)
  |     ->log('Product created')
  |
  v
INSERT INTO activity_log (log_name, description, event, subject_type,
  subject_id, causer_type, causer_id, properties, created_at)
VALUES ('products', 'Product created', 'created', 'Marvel\Models\Product',
  1, 'Marvel\Models\User', 1, '{"product_name":"Wireless Headphones"}', NOW())
```

## Flow 2: Read Activity Logs

```
Admin
  |
  GET /api/v1/logs/activity?log_name=products&event=created&per_page=15
  Authorization: Bearer <token>
  |
  v
auth:sanctum middleware
  |
  v
permission:view-activity-log middleware
  |
  v
ActivityLogController@index(Request)
  |
  +-- Activity::query()
  |     ->when(log_name, fn) → where('log_name', 'products')
  |     ->when(event, fn)    → where('event', 'created')
  |     ->when(causer_id, fn)→ where('causer_id', $id)
  |     ->when(search, fn)   → where description LIKE or log_name LIKE
  |     ->latest()
  |     ->paginate(15)
  |
  v
ActivityLogResource::collection($logs)
  |-- Maps: id, log_name, description, event, subject_type,
  |         subject_id, causer_type, causer_id, properties,
  |         created_at, updated_at
  |
  v
JSON Response (paginated)
```
