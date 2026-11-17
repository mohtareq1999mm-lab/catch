# 04: Product-type contract has one source

**What to build:** The importer and its diagnostics ask the schema contract for the canonical `product_type` definition instead of restating the ENUM. One module owns the canonical values, the expected column type and the compatibility message.

Today the importer synthesizes its own `ENUM('simple','variable')` string for the write-verification message while the schema guard owns the real column type, so a diagnostic can disagree with the database.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] The importer obtains the canonical ENUM text and compatibility reason from the schema contract.
- [ ] No second hardcoded enum definition remains in the import path.
- [ ] The diagnostic reports the actual column type when incompatible.
- [ ] A unit test covers the incompatibility message and the canonical definition.
- [ ] The integrity suite stays green.
