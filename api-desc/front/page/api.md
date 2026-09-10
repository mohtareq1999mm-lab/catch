# API Documentation - Content Page Feature

## Endpoints

---

### 1. List Content Pages (Public)

**GET** `/api/v1/general/pages`

**Purpose:** Retrieve paginated list of active content pages.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "title": "Home",
            "slug": "home",
            "is_active": true,
            "sections": [
                {
                    "id": 1,
                    "type": "sliders",
                    "title": "Hero Sliders",
                    "is_active": true,
                    "endpoint": "/api/v1/general/sliders?limit=5",
                    "order": 0,
                    "setting": { "autoplay": true, "slider_speed": 5000 }
                }
            ]
        }
    ]
}
```

---

### 2. Get Content Page by Slug (Public)

**GET** `/api/v1/general/pages/{slug}`

**Purpose:** Retrieve a single content page with its active sections.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Success Response (200)

```json
{
    "data": {
        "id": 1,
        "title": "Home",
        "slug": "home",
        "is_active": true,
        "sections": [
            {
                "id": 1,
                "type": "sliders",
                "title": "Hero Sliders",
                "is_active": true,
                "endpoint": "/api/v1/general/sliders?limit=5",
                "order": 0,
                "setting": { "autoplay": true, "slider_speed": 5000 }
            },
            {
                "id": 2,
                "type": "categories",
                "title": null,
                "is_active": true,
                "endpoint": "/api/v1/general/categories?parentOnly=true",
                "order": 1,
                "setting": { "parentOnly": true }
            }
        ]
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | Slug not found or page inactive |

---

