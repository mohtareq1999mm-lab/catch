# 06: Retryable schema failure

**What to build:** When the product-type preflight fails because the database cannot store the canonical values, keep the uploaded workbook and expose a retry path, so the admin can fix the schema and retry without re-uploading.

Today the failure path deletes the uploaded file like every other terminal failure, which forces a fresh upload even though the file is valid.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] A preflight failure keeps the uploaded workbook on the private imports disk.
- [ ] The import record exposes enough information for a retry (file reference, failure reason, status).
- [ ] Retrying after the schema is repaired re-runs the same file without a new upload.
- [ ] Kept files are bounded/cleaned by the existing cleanup policy (or an explicit retention rule).
- [ ] An endpoint or documented command performs the retry; authorization is unchanged.
- [ ] A test covers failure → repair → retry → success.
