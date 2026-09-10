# Bug Report — Tag Module

---

## BUG-TAG-001: `store()` Has No Return Statement

**Severity:** Critical

**Component:** `Marvel\Http\Controllers\TagController` — `store()` method (line 122)

**Description:** The `store()` method creates a tag, uploads images, but never returns a response. It implicitly returns `null`. The client receives an empty 200 response with no body.

**Code Location:** `packages/marvel/src/Http/Controllers/TagController.php` — lines 122-144

**Current Behavior:**
```php
public function store(TagCreateRequest $request)
{
    try {
        // ... creates tag, uploads images ...
    } catch (MarvelException $th) {
        throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
    }
    // NO RETURN STATEMENT — returns null
}
```

**Impact:** Frontend receives empty response body on tag creation. Cannot get the created tag ID without a separate API call.

**Fix Applied:** Added `return new TagResource($tag);` after the try-catch block.

---

## BUG-TAG-002: Undefined Variable `$language` in `show()`

**Severity:** Critical

**Component:** `Marvel\Http\Controllers\TagController` — `show()` method (line 168)

**Description:** When looking up a tag by slug (non-numeric `$params`), the variable `$language` is used but never defined. This throws a PHP warning/error at runtime.

**Code Location:** `packages/marvel/src/Http/Controllers/TagController.php` — line 168

**Current Behavior:**
```php
$tag = $this->repository->where('slug', $params)->where('language', $language)->with(['type'])->firstOrFail();
// $language is undefined!
```

**Impact:** Any request to `GET /tags/{slug}` with a string slug crashes with "Undefined variable $language". The numeric ID path works fine because it returns early before reaching the undefined variable.

**Fix Applied:** Added `$language = $request->language ?? DEFAULT_LANGUAGE;` at the start of the try block.

---

## BUG-TAG-003: UniqueTranslationRule Checks Wrong Table

**Severity:** High

**Component:** `Marvel\Http\Requests\TagUpdateRequest` — `rules()` method (line 33)

**Description:** The unique translation rule checks the `categories` table instead of the `tags` table. This means:
1. A tag name could collide with a category name (incorrectly rejected)
2. A tag name that already exists in tags is not detected

**Code Location:** `packages/marvel/src/Http/Requests/TagUpdateRequest.php` — lines 33-34

**Current Behavior (before fix):**
```php
UniqueTranslationRule::for('categories', 'name')
```

**Impact:** Tag name uniqueness validation is completely broken. Tags with duplicate names can be created. Name conflicts with categories are incorrectly blocked.

**Fix Applied:** Changed to `UniqueTranslationRule::for('tags', 'name')->ignore($this->route('tag'))`.

---

## BUG-TAG-004: `->ignore()` in CREATE Request Is Nonsensical

**Severity:** High

**Component:** `Marvel\Http\Requests\TagCreateRequest` — `rules()` method (line 33)

**Description:** The create request includes `->ignore($this->route('tag'))` in the unique translation rule. For a POST request, there is no `{tag}` route parameter, so `$this->route('tag')` is always `null`. While it doesn't cause an error, it is misleading and suggests copy-paste from an update request.

**Code Location:** `packages/marvel/src/Http/Requests/TagCreateRequest.php` — line 33

**Current Behavior (before fix):**
```php
UniqueTranslationRule::for('tags')->ignore($this->route('tag'))
```

**Impact:** None functionally (null ignore is a no-op), but indicates poor code quality and potential copy-paste mistakes.

**Fix Applied:** Removed `->ignore($this->route('tag'))` from the create request.

---

## BUG-TAG-005: Wrong Exception Constants in Catch Blocks

**Severity:** Medium

**Component:** `Marvel\Http\Controllers\TagController` — all catch blocks

**Description:** All catch blocks in the controller use `COULD_NOT_CREATE_THE_RESOURCE` regardless of the operation. This means update failures say "Could not create the resource" and delete failures say "Could not create the resource".

**Code Locations:**
- `show()`: `throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` — should be `NOT_FOUND`
- `update()`: `throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` — should be `COULD_NOT_UPDATE_THE_RESOURCE`
- `tagUpdate()`: `throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` — should be `COULD_NOT_UPDATE_THE_RESOURCE`
- `destroy()`: `throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE)` — should be `COULD_NOT_DELETE_THE_RESOURCE`

**Impact:** Misleading error messages returned to API consumers. Update failures incorrectly identified as create failures.

**Fix Applied:** Each catch block now uses the semantically correct constant.

---

## BUG-TAG-006: Missing English Translation Keys

**Severity:** Medium

**Component:** `resources/lang/en/message.php`

**Description:** The following translation keys exist in Arabic (`ar/message.php`) but are missing from English (`en/message.php`):
- `ERROR.COULD_NOT_CREATE_THE_RESOURCE`
- `ERROR.COULD_NOT_UPDATE_THE_RESOURCE`
- `ERROR.COULD_NOT_DELETE_THE_RESOURCE`
- `MESSAGE.TAG_CREATED_SUCCESSFULLY`
- `MESSAGE.TAG_UPDATED_SUCCESSFULLY`
- `MESSAGE.TAG_DELETED_SUCCESSFULLY`
- `ERROR.TAG_NOT_FOUND`

**Impact:** When the app language is set to English, these messages fall back to the translation key string (e.g., "ERROR.COULD_NOT_CREATE_THE_RESOURCE") instead of a human-readable message.

**Fix Applied:** Added all 7 missing keys to `resources/lang/en/message.php`.

---

## BUG-TAG-007: `destroy()` Returns Raw Boolean

**Severity:** Low

**Component:** `Marvel\Http\Controllers\TagController` — `destroy()` method

**Description:** The `destroy()` method returns the raw result of `$tag->delete()` which is a boolean (`true`). This is inconsistent with other destroy methods in the project that return JSON responses.

**Code Location:** `packages/marvel/src/Http/Controllers/TagController.php` — line 225-226

**Current Behavior:**
```php
return $this->repository->findOrFail($id)->delete();
// Returns: true
```

**Impact:** Inconsistent API response format. Frontend expects JSON but receives a raw boolean.

**Recommended Fix:** Wrap in `$this->apiResponse(TAG_DELETED_SUCCESSFULLY, 200, true)` or similar.
