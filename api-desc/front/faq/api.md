# API Documentation - FAQ Feature

## Endpoints

---

### 1. List FAQs (Public)

**GET** `/api/v1/general/faqs`

**Purpose:** Retrieve all active FAQs for the public help page.

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
            "faq_title": "What is your return policy?",
            "faq_description": "You can return any item within 30 days of purchase for a full refund."
        },
        {
            "id": 2,
            "faq_title": "How long does shipping take?",
            "faq_description": "Standard shipping takes 5-7 business days. Express shipping is available."
        }
    ]
}
```

---

### 2. List FAQs (Admin)

**GET** `/api/v1/faqs`

**Purpose:** Retrieve paginated list of all FAQs for admin management.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `view-faqs` |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `page` | `integer` | No | Page number |
| `search` | `string` | No | Search by faq_title |
| `sort` | `string` | No | Sort field (id, faq_title, status, created_at) |
| `order` | `string` | No | Sort direction (asc/desc) |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "faq_title": "What is your return policy?",
            "faq_description": "You can return any item within 30 days..."
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 3,
        "per_page": 15,
        "total": 35
    }
}
```

---

### 3. Create FAQ (Admin)

**POST** `/api/v1/faqs`

**Purpose:** Create a new FAQ entry.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `create-faq` |

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `faq_title[en]` | `string` | Yes | English question (min:3, max:1000) |
| `faq_title[ar]` | `string` | Yes | Arabic question |
| `faq_description[en]` | `string` | Yes | English answer (min:3, max:1000) |
| `faq_description[ar]` | `string` | Yes | Arabic answer |
| `shop_id` | `integer` | No | Associated shop ID |

#### Success Response (201)

```json
{
    "data": {
        "id": 36,
        "faq_title": "What is your return policy?",
        "faq_description": "You can return any item within 30 days..."
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 422 | Validation failure |
| 401 | Unauthenticated |
| 403 | Missing permission |

---

### 4. Get FAQ (Admin)

**GET** `/api/v1/faqs/{id}`

**Purpose:** Retrieve a single FAQ by ID.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `view-faqs` |

#### Success Response (200)

Returns single FAQ resource.

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | FAQ not found |

---

### 5. Update FAQ (Admin)

**PUT** `/api/v1/faqs/{id}`

**Purpose:** Update an existing FAQ (partial updates supported).

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `update-faq` |

#### Request Parameters

Same as create but all fields optional. Additional field:

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | `boolean` | Active/inactive |

#### Success Response (200)

Returns updated FAQ resource.

---

### 6. Delete FAQ (Admin)

**DELETE** `/api/v1/faqs/{id}`

**Purpose:** Soft-delete a FAQ.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `delete-faq` |

#### Success Response (200)

```json
{
    "message": "FAQ deleted successfully"
}
```

---

### 7. Reorder FAQs (Admin)

**POST** `/api/v1/faqs/reorder`

**Purpose:** Update sort order for FAQs.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `update-faq` |

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `faqs` | `array` | Yes | Array of FAQ IDs in new order |

#### Success Response (200)

```json
{
    "message": "FAQs reordered successfully"
}
```

---

### 8. GraphQL

**Query FAQs:**
```graphql
query {
    faqs(search: "return", orderBy: [{ column: "faq_title", order: ASC }]) {
        data {
            id
            faq_title
            faq_description
        }
    }
}
```

**Create FAQ:**
```graphql
mutation {
    createFaq(input: {
        faq_title: "What is your return policy?"
        faq_description: "You can return items within 30 days."
    }) {
        id
        faq_title
    }
}
```

---

## Resource Structure

### FaqResource (Admin & Public)

| Field | Type | Description |
|-------|------|-------------|
| `id` | `integer` | Primary key |
| `faq_title` | `string` | Translated question |
| `faq_description` | `string` | Translated answer |

## Business Rules

1. **Sorting:** Order managed automatically by Spatie Sortable (auto-assigned on create)
2. **Soft Delete:** FAQ is soft-deleted; hidden from list/show endpoints
3. **Role Scoping:** Super Admin sees all FAQs; Store Owner sees own shop; Staff sees assigned shop
4. **Public Filtering:** Only `status=true` FAQs returned on public endpoint
5. **Unique Translation:** Both title and description have unique translation validation
