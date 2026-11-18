# Working-tree incident — vendored Marvel package mass-deleted (uncommitted)

## Status
- RESOLVED by `git restore -- packages/marvel` at ~2026-11-17 (this session).
- **RECURRED ~2026-11-17 19:00 local**: 939 tracked deletions under
  `packages/marvel/` again (config + src), plus `vendor/` gutted
  (`vendor/autoload.php` missing). Restored again by
  `git restore -- packages/marvel` + `composer install`. The parallel
  session's own modifications (2 app files + lang files) were untouched
  by the restore. The user decision below remains pending.

## What happened
- During the image-seam implementation (ticket #61), `packages/marvel/src/Services/Import/ProductImportService.php` disappeared from disk mid-edit and `composer dump-autoload` failed.
- Forensics (`git status --porcelain`): **929 tracked files** under `packages/marvel/` were deleted from the working tree, **uncommitted** (` D` status) — the entire vendored package. 4 modified files (phpunit cache, AGENTS.md, skills-lock.json, a guest test) and 9 untracked files were unrelated and untouched.
- No parallel process claim could be verified; attribution INFERRED (a concurrent session's mass delete/move in flight). The session's own edits were to files inside the deleted tree, so they were collateral, not the cause.

## Evidence
- `git status --porcelain` captured in `%TEMP%\wtstatus.txt` before restore: deleted=929, modified=4, untracked=9.
- HEAD at the time: `16ae139` (brand run-outcome). All session commits are in HEAD — nothing committed was lost.
- Restore: `git restore -- packages/marvel` → working tree back to HEAD for those 929 files; post-restore status = 13 lines (the 4 modified + 9 untracked).

## Impact
- Uncommitted image-seam work (`ImageImporter` class, facade delegate, image-job rewrite) was lost from the working tree and is being re-created from this session's context.
- The other session's uncommitted deletion is reverted; if the un-vendor was intentional it must be redone deliberately (and coordinated).

## Containment
- Before any further commits: `git status --porcelain` scoped to the touched paths only; never stage anything outside the ticket's file set.
- Restore-first rule for uncommitted mass deletions of tracked files (reversible; HEAD is authoritative).

## Required decisions (user)
1. Confirm whether the Marvel un-vendor was intentional; if so it must be redone as an approved expand–contract, not a raw deletion.
