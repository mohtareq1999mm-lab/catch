# Contacts — Changelog

## Added

### Regression Tests
- `ContactRegressionTest::b10_contact_message_received_triggers_admin_notification` — Verifies `ContactMessageReceived` event dispatches `NewContactMessageNotification` to admin users
- `ContactSoftDeleteTest::delete_all_keeps_records_in_database` — Verifies bulk delete-all does not permanently remove records
- `ContactSoftDeleteTest::delete_all_read_keeps_unread_records_in_database` — Verifies bulk delete-read does not permanently remove records
- `ContactValidationTest::create_returns_422_without_name` — Verifies missing `name` returns 422 validation error

## Changed

### Listener Registration (Bug Fix)
- **File:** `app/Providers/EventServiceProvider.php`
- **Change:** Added import for `App\Events\ContactMessageReceived` and `App\Listeners\SendContactMessageNotification`; registered mapping in `$listen` array
- **Effect:** Admin notifications now fire when a contact message is submitted

### Hard Delete → Soft Delete (Bug Fix)
- **File:** `packages/marvel/src/Database/Repositories/ContactRepository.php`
- **Change 1:** `deleteAllContacts()` — `Contact::query()->delete()` → `Contact::query()->update(['deleted_at' => now()])`
- **Change 2:** `deleteAllReadContacts()` — `Contact::query()->where('is_read', true)->delete()` → `Contact::query()->where('is_read', true)->update(['deleted_at' => now()])`
- **Effect:** Bulk operations now soft-delete consistently with single delete

### Name Validation (Bug Fix)
- **File:** `packages/marvel/src/Http/Requests/ContactCreateRequest.php`
- **Change:** Added `'name' => ['required', 'string', 'max:255']` to validation rules
- **Effect:** Missing `name` now returns 422 validation error instead of 500 database exception

### Test Updates (Side Effect)
- **Files:**
  - `tests/Feature/Contacts/ContactAuthenticationTest.php`
  - `tests/Feature/Contacts/ContactRegressionTest.php`
  - `tests/Feature/Contacts/ContactResourceTest.php`
- **Change:** Added `Notification::fake()` in 4 tests that create contacts via API
- **Reason:** Activating the listener caused Pusher broadcast errors in test environment

## Fixed

| Bug | Priority | Description |
|-----|----------|-------------|
| ContactMessageReceived listener not registered | Critical | `SendContactMessageNotification` never executed — admin notifications silently failed for all new contact submissions |
| Hard delete in bulk operations | High | `deleteAll()` and `deleteAllReadContacts()` permanently destroyed data while single delete used SoftDeletes |
| Missing name validation | Medium | Omitting `name` from contact creation caused 500 error instead of proper 422 validation response |

## Database Changes

None. All changes are application-level.

## API Changes

None. API contract is fully backward compatible.

## Breaking Changes

None.

## Non-Blocking Issues (Not Fixed)

| Issue | Severity | Reason |
|-------|----------|--------|
| "Replay" typo in route, method, column, translation | Low | Backward compatibility — renaming would break existing API consumers |
| No index on `email` column | Low | Acceptable for contact table volume |
| No index on `is_read` column | Low | Acceptable for contact table volume |
| No chunking in bulk delete | Medium | Potential timeout on very large datasets (>10k) |
| No `user_id` reference on contacts | Low | Not required for current business rules |

## Deployment Notes

1. Deploy normally via standard deployment pipeline
2. Run `php artisan optimize:clear` to refresh event cache
3. No database migrations required
4. No downtime expected

## Rollback Plan

To revert all changes:

1. **EventServiceProvider:** Remove `ContactMessageReceived` entry and its imports from `$listen` array
2. **ContactRepository:** Change both methods back to `Contact::query()->delete()` and `Contact::query()->where('is_read', true)->delete()`
3. **ContactCreateRequest:** Remove `'name'` rule from `rules()` array
4. **Test files:** Revert `Notification::fake()` additions (keep regression tests—they won't harm)
