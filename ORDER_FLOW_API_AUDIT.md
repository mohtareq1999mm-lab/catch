# ORDER FLOW API AUDIT (end-to-end, evidence-based)

Date (UTC): 2026-10-01. Scope: `D:\work\meem`. Method: read-only inspection.

## 1. Current state per area

| Area | State | Evidence |
|---|---|---|
| Flow tables/service | EXISTS, correct | `OrderFlowService.php` (resolve/assign/next/allows/validate/sync-perms); migrations `2026_09_28_000001/000002`, `2026_09_29_000001` |
| Seeded flows | EXISTS, correct | `flowsSeed()`: local 6 steps, international 11 steps — match §5 exactly |
| Catalog (22 codes) | EXISTS, correct | `ALL_STATUS_CODES` == §6 list verbatim; bilingual `{en,ar}` names (migration `2026_10_01_000001`) |
| Dynamic shipping_type | EXISTS, correct | No enum in schema (`string(30)` both tables); no allow-list in code (removed); format-only validation; resolution DB-driven fail-closed |
| Flow code vs shipping_type | DISTINCT, correct | Separate columns; seeds use same value but fields documented separately; resolution keys ONLY on `shipping_type`; `shipping_type` immutable after create |
| Definition endpoint | EXISTS | `FlowDefinitionController@byShippingType`, route `api.order-flows.by-shipping-type`, definitions-only |
| Options endpoint | EXISTS | `OrderStatusOptionsController@index`, route `orders.statuses`, order-specific flags, N+1-free |
| PATCH endpoint | EXISTS | Marvel `Order/OrderController@updateStatus`, same route; general+granular+transition+input+F-1 enforced |
| Granular permissions | EXISTS | `change-order-status.<code>` via `syncTargetStatusPermissions()` + `OrderStatusPermissionSeeder` + `OrderFlowSeeder` hook; enforced in PATCH controller + repository `updateOrder` |
| Bulk inputs POST | EXISTS | `{inputs:[...]}` collection contract, one transaction, dup/sort/in-flight guards; no `/inputs/bulk` |
| Validator | EXISTS | `FlowInputValidator` (unknown/required/type/source/rules, fail-closed) |
| Value ownership | EXISTS | `origin/destination_country_id`, `customs_reference` on orders + `order_flow_values` audit; no metadata blob |
| Localization | EXISTS | Spatie Translatable (status/flow/input names) + `resources/lang/{en,ar}` messages |
| Compat | EXISTS | checkout local-default, legacy clients unaffected until required inputs published; general+granular OR-legacy on admin config routes |

## 2. §7 transition rules vs code (all verified in `allowsFlowTransition`)

normal-successor ✓ (L400-402) · noop ✓ (L371) · completed-from-non-terminal ✓ (L383) · cancelled-except-completed/delivered/cancelled ✓ (L375-381) · failed_delivery-from-OFD ✓ (L387) · returned-from-failed/OFD ✓ (L391) · delivered/cancelled terminal ✓ (L375).

## 3. Gaps found (this audit)

1. **Granular PATCH coverage incomplete in tests**: `processing`/`completed` proven; `packed`/`shipped`/`delivered`/`cancelled` target perms asserted only via flags or inactive paths — no walk-through PATCH proof. FIX: add walk test.
2. **Canonical doc stale**: `api-desc/order/flow.md` status-change section describes the pre-flow 5-status flow (wrong enum, wrong middleware location, no flow/inputs/permissions). FIX: update that section + append flow-system sections; preserve all other flows in the file.
3. **Two 422 shapes coexist**: FormRequest failures return raw `$validator->errors()`; service failures return the `{status,message,success,data.errors}` envelope. Both pre-existing project conventions — documented, not unified (unifying would break clients).
4. **Legacy `order_status` column writes** (trait/refund): legacy-column only, flow-untouched — safe, no action.

## 4. Not missing / not duplicated

No second flow engine, permission system, validator, or status endpoint exists or is proposed. Sibling machines untouched. `same_day` exists ONLY in tests (seeds stay local/international per §3/§21).
