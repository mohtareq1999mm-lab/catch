# Tag Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/tags — List All Tags (Public)

**Purpose:** Fetch all product tags for tag cloud, filter chips, or tag-based navigation.

**Authentication:** None (public)

**Query Parameters:** None

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Organic",
      "slug": "organic",
      "details": "Fresh organic products",
      "image": null,
      "icon": "organic-icon",
      "language": "en",
      "translated_languages": ["en", "ar"],
      "type": {
        "id": 1,
        "name": "Product Type",
        "slug": "product-type"
      }
    }
  ]
}
```

---

### 2. GET /api/v1/general/tags/{slug} — Get Tag by Slug (Public)

**Purpose:** Fetch a single tag by its slug.

**Authentication:** None (public)

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Organic",
    "slug": "organic",
    "details": "Fresh organic products",
    "image": null,
    "icon": "organic-icon",
    "language": "en",
    "translated_languages": ["en", "ar"],
    "type": {
      "id": 1,
      "name": "Product Type",
      "slug": "product-type"
    }
  }
}
```

**Response 404:**
```json
{
  "status": 404,
  "message": "Data not found",
  "success": false
}
```

---

## Frontend Usage

### Tag Cloud / Tag List Page
Use `GET /api/v1/general/tags` to display a tag cloud. Each tag links to a filtered product listing page (`/products?tag={slug}`).

### Product Filter Chips
Display tags as filter chips on the product listing page. Selected tags filter the product list.

### Tag Detail
Use `GET /api/v1/general/tags/{slug}` to show tag info and optionally fetch associated products.

### State Handling

| State | Behavior |
|-------|----------|
| **Loading** | Skeleton chips/cloud |
| **Empty (list)** | "No tags available" |
| **Empty (detail)** | 404 page |
| **Error** | Retry or hide section |
