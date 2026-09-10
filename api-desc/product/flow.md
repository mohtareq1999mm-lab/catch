# Product Module — Request Flow Diagrams

## 1. List Products (GET /products)

```
Client                  Routes.php            ProductController           Repository/Model
  │                         │                       │                         │
  │──── GET /products ─────>│                       │                         │
  │                         │── index(Request) ────>│                         │
  │                         │                       │── with('variations') ──>│
  │                         │                       │── with('categories') ───>│
  │                         │                       │── with('flash_sales') ──>│
  │                         │                       │                         │
  │                         │                       │── apply search ─────────>│
  │                         │                       │── apply filters ────────>│
  │                         │                       │  (incl. tags filter)    │
  │                         │                       │── paginate(limit) ──────>│
  │                         │                       │                         │
  │                         │                       │<── ProductCollection ────│
  │                         │<── JSON 200 ──────────│                         │
  │<── JSON 200 ────────────│                       │                         │
```

## 2. Create Product (POST /products)

```
Client                  Routes.php            ProductController      ProductCreateRequest   ProductRepository     DB
  │                         │                       │                         │                    │              │
  │──── POST /products ────>│                       │                         │                    │              │
  │                         │── store(CreateRequest)│                         │                    │              │
  │                         │                       │── validate() ──────────>│                    │              │
  │                         │                       │<── validated ───────────│                    │              │
  │                         │                       │                         │                    │              │
  │                         │                       │── storeProduct(request)─>│                    │              │
  │                         │                       │                         │── beginTransaction >│              │
  │                         │                       │                         │── create product ──>│              │
  │                         │                       │                         │── addVariants() ───>│              │
  │                         │                       │                         │── upload images ───>│              │
  │                         │                       │                         │── sync relations ──>│              │
  │                         │                       │                         │  (categories, brands,│              │
  │                         │                       │                         │   banners, sliders, │              │
  │                         │                       │                         │   tags, flash_sales)│              │
  │                         │                       │                         │── commit ──────────>│              │
  │                         │                       │                         │<── product ────────│              │
  │                         │                       │<── ProductResource ─────│                    │              │
  │                         │<── JSON 201 ──────────│                         │                    │              │
  │<── JSON 201 ────────────│                       │                         │                    │              │
```

## 3. Show Product (GET /products/{id})

```
Client                  Routes.php            ProductController           Repository/Model
  │                         │                       │                         │
  │──── GET /products/1 ───>│                       │                         │
  │                         │── show(Request, $id) >│                         │
  │                         │                       │── fetchSingleProduct() ─>│
  │                         │                       │── findOrFail(id) ───────>│
  │                         │                       │── load relations ───────>│
  │                         │                       │── fetchRelated() ───────>│
  │                         │                       │<── ProductResource ──────│
  │                         │<── JSON 200 ──────────│                         │
  │<── JSON 200 ────────────│                       │                         │
```

## 4. Update Product (PUT /products/{id})

```
Client                  Routes.php            ProductController      ProductUpdateRequest   ProductRepository     DB
  │                         │                       │                         │                    │              │
  │─── PUT /products/1 ────>│                       │                         │                    │              │
  │                         │── update(UpdateReq, $id│                        │                    │              │
  │                         │                       │── validate() ──────────>│                    │              │
  │                         │                       │<── validated ───────────│                    │              │
  │                         │                       │                         │                    │              │
  │                         │                       │── updateProduct(req,id) >│                    │              │
  │                         │                       │                         │── find product ────>│              │
  │                         │                       │                         │── delete old vars ─>│              │
  │                         │                       │                         │── addVariants() ───>│              │
  │                         │                       │                         │── update images ───>│              │
  │                         │                       │                         │── sync relations ──>│              │
  │                         │                       │                         │  (categories, brands,│              │
  │                         │                       │                         │   banners, sliders, │              │
  │                         │                       │                         │   tags)             │              │
  │                         │                       │                         │<── product ────────│              │
  │                         │                       │<── ProductResource ─────│                    │              │
  │                         │<── JSON 200 ──────────│                         │                    │              │
  │<── JSON 200 ────────────│                       │                         │                    │              │
```

## 5. Delete Product (DELETE /products/{id})

```
Client                  Routes.php            ProductController           Repository/Model
  │                         │                       │                         │
  │─── DELETE /products/1 ──>│                       │                         │
  │                         │── destroy($id) ───────>│                         │
  │                         │                       │── findOrFail(id) ───────>│
  │                         │                       │── softDelete() ─────────>│
  │                         │                       │                         │
  │                         │<── JSON 200 ──────────│                         │
  │<── JSON 200 ────────────│                       │                         │
```

## 6. Bulk Delete Products (POST /products/bulk-delete)

```
Client                  Routes.php            ProductController           DB
  │                         │                       │                      │
  │── POST /products/bulk-delete ──>│               │                      │
  │    { ids: [1,2,3] }           │── destroyBulk()>│                      │
  │                               │                 │── validate ids ──────>│
  │                               │                 │── destroy whereIn ───>│
  │                               │                 │<── done ─────────────│
  │                               │<── JSON 200 ────│                      │
  │<── JSON 200 ──────────────────│                 │                      │
```

## 7. Destroy All Products (DELETE /products/all)

```
Client                  Routes.php            ProductController           DB
  │                         │                       │                      │
  │── DELETE /products/all ─>│                       │                      │
  │                         │── destroyAll() ───────>│                      │
  │                         │                       │── delete() all ──────>│
  │                         │                       │<── done ─────────────│
  │                         │<── JSON 200 ──────────│                      │
  │<── JSON 200 ────────────│                       │                      │
```

## 8. Product Import (POST /products/import)

```
Client                Routes.php          ProductImportController      ImportProductsJob     DB
  │                       │                       │                      │                  │
  │── POST /products/import ──>│                  │                      │                  │
  │    (file: .xlsx)          │── import(Request)>│                      │                  │
  │                           │                    │── store file ────────>│                  │
  │                           │                    │── create Import ─────>│                  │
  │                           │                    │   {status: pending}  │                  │
  │                           │                    │── writeSignalFile ───>│                  │
  │                           │                    │── dispatch job ──────>│                  │
  │                           │                    │                       │── process rows ──>│
  │                           │                    │                       │── update Import ──>│
  │                           │                    │<── JSON 202 ─────────│                  │
  │                           │<── JSON 202 ───────│                       │                  │
  │<── JSON 202 ──────────────│                    │                       │                  │
```

## 9. Import Status (GET /products/import/{id})

```
Client                Routes.php          ProductImportController           DB
  │                       │                       │                        │
  │── GET /products/import/1 ──>│                  │                        │
  │                           │── status($id) ────>│                        │
  │                           │                    │── findOrFail(id) ──────>│
  │                           │                    │── readSignalFile ──────>│
  │                           │                    │<── progress data ──────│
  │                           │                    │── calc progress % ─────>│
  │                           │<── JSON 200 ───────│                        │
  │<── JSON 200 ──────────────│                    │                        │
```

## 10. Import Cancel (POST /products/import/{id}/cancel)

```
Client                Routes.php          ProductImportController           DB
  │                       │                       │                        │
  │── POST /products/import/1/cancel ──>│          │                        │
  │                           │── cancel($id) ────>│                        │
  │                           │                    │── findOrFail(id) ──────>│
  │                           │                    │── if completed → 409 ──>│
  │                           │                    │── writeSignalFile ─────>│
  │                           │                    │── update status ───────>│
  │                           │                    │   {status: cancelled}  │
  │                           │<── JSON 200 ───────│                        │
  │<── JSON 200 ──────────────│                    │                        │
```

## 11. Import Download Errors (GET /products/import/{id}/download-errors)

```
Client                Routes.php          ProductImportController           DB
  │                       │                       │                        │
  │── GET /products/import/1/download-errors ──>│  │                        │
  │                           │── downloadErrors($id)                       │
  │                           │                    │── findOrFail(id) ──────>│
  │                           │                    │── if no errors → 404   │
  │                           │                    │── generate XLSX ───────>│
  │                           │<── Binary file ────│                        │
  │<── Binary file ───────────│                    │                        │
```

## 12. List Reviews (GET /reviews)

```
Client                  Routes.php            ReviewController      ReviewRepository      DB
  │                         │                       │                    │                │
  │── GET /reviews?product_id=1 ──>│                │                    │                │
  │                         │── index(Request) ────>│                    │                │
  │                         │                       │── validate ────────>│                │
  │                         │                       │── where(product_id) │                │
  │                         │                       │── paginate(limit) ──>│                │
  │                         │                       │                    │<── reviews ─────│
  │                         │                       │<── collection ─────│                │
  │                         │<── JSON 200 ──────────│                    │                │
  │<── JSON 200 ────────────│                       │                    │                │
```

## 13. Create Review (POST /reviews)

```
Client                  Routes.php            ReviewController    ReviewCreateRequest  ReviewRepository   DB
  │                         │                       │                    │                │              │
  │── POST /reviews ────────>│                       │                    │                │              │
  │    {rating, comment,    │── store(CreateReq) ───>│                    │                │              │
  │     product_id}         │                       │── validate() ──────>│                │              │
  │                         │                       │<── validated ──────│                │              │
  │                         │                       │── storeReview(req) ─>│                │              │
  │                         │                       │                    │── create review ─>│              │
  │                         │                       │                    │<── review ───────│              │
  │                         │                       │<── resource ───────│                │              │
  │                         │<── JSON 200 ──────────│                    │                │              │
  │<── JSON 200 ────────────│                       │                    │                │              │
```

## 14. Toggle Review Approval (PATCH /reviews/{id}/toggle-approve)

```
Client                  Routes.php            ReviewController      ReviewRepository      DB
  │                         │                       │                    │                │
  │── PATCH /reviews/1/toggle-approve ──>│           │                    │                │
  │                         │── toggleApprove($id) >│                    │                │
  │                         │                       │── findOrFail(id) ──>│                │
  │                         │                       │── flip approved ───>│                │
  │                         │                       │── save ────────────>│                │
  │                         │                       │                    │<── review ─────│
  │                         │                       │<── resource ───────│                │
  │                         │<── JSON 200 ──────────│                    │                │
  │<── JSON 200 ────────────│                       │                    │                │
```

## 15. Delete Review (DELETE /reviews/{id})

```
Client                  Routes.php            ReviewController      ReviewRepository      DB
  │                         │                       │                    │                │
  │── DELETE /reviews/1 ────>│                       │                    │                │
  │                         │── destroy($id) ───────>│                    │                │
  │                         │                       │── findOrFail(id) ──>│                │
  │                         │                       │── delete() ────────>│                │
  │                         │                       │                    │<── done ───────│
  │                         │<── JSON 200 ──────────│                    │                │
  │<── JSON 200 ────────────│                       │                    │                │
```
