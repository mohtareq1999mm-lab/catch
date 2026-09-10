# Shipment Module — Changelog

## [0.1.0] — 2026-07-28

### Added
- Shipment API investigation documentation (`api-desc/shipment/`)
- Full API documentation for all 6 endpoints

### Known Issues

1. **No ShipmentResource** — Controller returns raw model data instead of using a dedicated Resource class. Response structure may expose more fields than needed.
2. **Hardcoded response messages** — Success messages are literal English strings instead of constants with translations. No `SHIPMENT_CREATED_SUCCESSFULLY` constant exists.
3. **Missing translation keys** — No entries in `resources/lang/{en,ar}/message.php` for shipment messages. Arabic locale will see English fallback.
4. **No 404 handling** — `ModelNotFoundException` from `findOrFail()` / `firstOrFail()` is not caught in `show()`, `showByUuid()`, `update()`, or `updateStatus()`. Non-existent records trigger Laravel's HTML exception page instead of JSON 404.
5. **No delete endpoint** — There is no `DELETE /shipments/{id}` endpoint and no `DELETE_SHIPMENT` permission. Shipments can only be cancelled via status transition.
6. **No rate limiting** — Shipment endpoints have no throttle middleware (unlike Cart module's 20 req/min).
7. **No observer** — No activity logging for shipment create/update/status-change events.
8. **No tests** — Zero test coverage for the Shipment module.
9. **No seeder** — No seed data for development/testing.
10. **State machine logic duplicated** — Both `ShipmentStatus` enum and `Shipment` model define the same transition rules. The service layer calls `Shipment::canTransitionTo()` (model), not `ShipmentStatus::canTransitionTo()` (enum).
11. **`update()` method has no transaction** — Unlike `create()` and `updateStatus()`, the `update()` method updates the model without a database transaction.
12. **No soft deletes** — The `shipments` table has no `deleted_at` column; records cannot be restored if accidentally modified.
