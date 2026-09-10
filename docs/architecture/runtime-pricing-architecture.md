# Runtime Pricing Architecture

Status: Frozen

AI agents must read this document before modifying any pricing-related code.

## Metadata
- Decision ID: ADR-001
- Architecture Area: Runtime Pricing
- Status: Accepted
- Decision State: Frozen
- Production Status: Approved

---

## Context

Pricing is a critical business capability in any e-commerce system. Correct pricing depends on multiple dynamic factors that may include:

- Product base price.
- Variant pricing.
- Promotions.
- Discounts (percentage, fixed rate, or final price).
- Flash sales.
- Future pricing rules and conditions.

Before this decision, pricing logic was scattered across Models, Resources, Repositories, Controllers, and several Services. This duplication caused inconsistent results (a product could show different prices through different endpoints), made maintenance error-prone, and made it difficult to introduce new pricing rules without risking regressions across the entire application.

A single, centralized pricing authority was required to guarantee deterministic, consistent, and maintainable runtime pricing.

---

## Decision

The official runtime pricing pipeline is permanently defined as:

```
Repository / Query
        |
        v
Application Service
        |
        v
ProductPricingService
        |
        v
enrichProductWithPricing()
        |
        v
Resource
        |
        v
JSON Response
```

This flow is the ONLY supported runtime pricing pipeline.

No alternative pricing flow is permitted. Any code path that computes a price, discount, flash sale amount, or final price must pass through this pipeline.

---

## Architectural Rules

The following rules are mandatory and must be enforced during code review:

1. **Single Pricing Authority** — `ProductPricingService` is the single source of truth for all pricing calculations including current price, discount amounts, flash sale amounts, final price, variant pricing, and coupon pricing.

2. **Pre-Serialization Enrichment** — Every Product and ProductVariant must have pricing attributes set on the model instance (`current_price`, `discount_active`, `flash_sale_active`, `sale_price`) before it reaches a Resource.

3. **Resource Purity** — Resources are serialization-only layers. They must never instantiate `ProductPricingService`, calculate prices, resolve discounts, or resolve flash sales.

4. **Model Purity** — Models must contain no pricing business logic. Accessors are only allowed as lightweight attribute readers (`return $this->attributes['current_price'] ?? null;`). Models must never execute SQL, call `load()`, `loadMissing()`, `refresh()`, or `fresh()` inside pricing-related accessors.

5. **Controller Purity** — Controllers must only orchestrate requests and responses. They must never calculate prices, enrich pricing, or duplicate business rules.

6. **Zero Duplication** — Pricing calculations must never be duplicated outside `ProductPricingService`. Any duplicated pricing formula is considered a production bug.

7. **Lightweight Accessors** — Model accessors must remain lightweight attribute readers. They must never compute pricing, execute queries, load relationships, or call `ProductPricingService`.

8. **No Hidden Work** — Hidden SQL queries, lazy loading, or database access inside pricing calculations are strictly prohibited. All data required for pricing must be explicitly loaded via eager loading before enrichment.

9. **Extensibility** — Any new pricing feature must extend `ProductPricingService` instead of introducing parallel pricing logic in a different class.

---

## Architecture Freeze

The Runtime Pricing Architecture is officially frozen.

Architectural changes are allowed only under these conditions:

1. **Verified production bug** — A bug in production proves the current architecture produces incorrect results.
2. **Failing automated test** — An existing test proves the current design is incorrect.
3. **New business requirement** — A requirement cannot be implemented while preserving the current architecture.

The following are NOT valid reasons for changing the architecture:

- Personal preference.
- Code style preferences.
- Theoretical improvements without measurable impact.
- Desire to introduce unnecessary abstraction.
- "It would look cleaner."

---

## Future Development Policy

All future development must preserve:

- Single pricing authority (`ProductPricingService`).
- Pre-serialization enrichment.
- Resource purity (serialization only).
- Controller purity (orchestration only).
- Model purity (data containers only).
- Backward-compatible API responses.
- Zero hidden SQL in pricing flows.
- Zero duplicated pricing calculations.

Any Pull Request that violates these principles must be rejected unless an explicit Architecture Decision Record supersedes this document.

---

## Developer Guidelines

Before modifying any pricing-related code:

1. **Search first** — Search the codebase for existing pricing logic before writing any new pricing code.

2. **Check ProductPricingService** — Verify whether `ProductPricingService` already handles the requirement you are implementing.

3. **Extend, don't duplicate** — Extend the existing pricing service instead of creating new calculation paths. Add new methods to `ProductPricingService` rather than writing inline formulas.

4. **Keep Resources clean** — Resources must only serialize fields that already exist on the model. Never compute a price inside a Resource.

5. **Keep Models clean** — Avoid introducing pricing calculations into Models, Observers, Scopes, or Accessors. If a computed attribute is needed for serialization, it must be set via enrichment, not computed inside a model accessor.

6. **Maintain API compatibility** — Never remove or rename an existing JSON response field related to pricing without a deprecation strategy. New fields are acceptable, but existing fields must remain stable.

7. **Always eager load** — Ensure all relationships required for pricing (`flash_sales`, `variations`) are eager loaded before calling enrichment. Never rely on lazy loading inside a pricing path.

---

## AI Agent Guidelines

AI agents (including Large Language Model coding assistants) must obey the following rules when modifying code in this project:

- **Do not create new pricing services.** All pricing computation must go through `ProductPricingService`.
- **Do not move pricing logic into Resources.** Resources are serialization layers only.
- **Do not move pricing logic into Models.** Models are data containers only.
- **Do not bypass ProductPricingService.** No direct discount/flash sale calculations in Controllers, Services, or Repositories.
- **Do not add pricing accessors that compute values.** Accessors must only read from `$this->attributes`.
- **Respect the frozen architecture.** Do not suggest architectural refactoring of the pricing system.
- **When in doubt, check ProductPricingService first.** If the service already provides a method for the required calculation, reuse it. If a new calculation is needed, add it to `ProductPricingService`.

---

## Consequences

### Positive

- Pricing is deterministic and consistent across all endpoints.
- New pricing rules can be added in one place without scattering logic.
- Code review is simpler: violations of the architecture are easy to spot.
- JSON responses are stable and backward compatible.
- Testing is simplified: one service to test for pricing correctness.

### Negative

- Adding a new pricing factor requires modifying `ProductPricingService`, which could introduce regressions if not adequately tested.
- The enrichment step adds a mandatory processing stage that must not be skipped; discipline is required to ensure every endpoint enriches before returning.

---

## Compliance

Compliance with this ADR is verified by:

1. Code review — every Pull Request involving products must pass the architecture checklist.
2. Automated testing — tests must verify that pricing fields are present and correct after enrichment.
3. Architecture audits — periodic audits of the codebase to detect any new pricing logic outside `ProductPricingService`.

---

## Final State

Architecture Status:
✅ Frozen

Production Status:
✅ Approved as the project's Runtime Pricing Baseline
