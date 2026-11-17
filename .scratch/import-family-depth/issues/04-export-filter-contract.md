# 04: One export filter contract

**What to build:** A single filter applier every export sheet uses, with each sheet declaring which filters it supports in the workbook schema. The images sheet's filter behaviour becomes an explicit declaration and the soft-deleted-row exclusion is fixed, so an export filter never silently does nothing.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] One filter applier owns status/product-type/item-type/category/brand application.
- [ ] Each export sheet declares its supported filters; the declaration is contract-tested.
- [ ] The images sheet filter behaviour is explicit (declared filters or none) and excludes soft-deleted rows.
- [ ] Adding a filter is a single edit where all declaring sheets honour it.
- [ ] Export filter tests cover every declaring sheet and the images exclusion.
