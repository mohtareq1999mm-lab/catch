# 07: Real-workbook acceptance run

**What to build:** An isolated-database acceptance run of the real 4,820-row workbook through the full import pipeline, with evidence attached to the ticket: zero product failures, `product_type` stored as `simple`, taxonomy gaps reported per slug with affected SKUs, and the in-stock contradiction reported once.

This is the end-to-end proof that the sheet schema, run outcome and product-type contract work together on the file that exposed the original bug.

**Blocked by:** 01, 02, 04

**Status:** ready-for-agent

- [ ] Run against a dedicated database, never the live `catch` database.
- [ ] 4,820 products import with zero failed rows and `product_type = simple`.
- [ ] Existing products keep their variant-derived `variable` type.
- [ ] 117 unknown category slugs and 2 unknown brand slugs are reported one per slug, with affected SKU counts.
- [ ] The 469 rows with `in_stock=0` and `quantity>0` produce one aggregated warning.
- [ ] Products with known taxonomy links receive them; unknown links are skipped.
- [ ] The run is rolled back or the database is discarded afterwards; evidence is attached to this ticket.
