# AI Development Rules - Architecture First

## Mandatory Rule

Before modifying ANY code in this project, the AI agent MUST:

1. Read the architecture documentation inside:

docs/architecture/

2. Identify if there is an existing architecture decision related to the requested feature.

3. Understand the current implementation flow before writing code.

4. Read the project state files:

- docs/production-status.md
- docs/feature-dependencies.md
- docs/regression-matrix.md
- docs/production-history.md

5. Read the AI investigation manual:

ai/api-investigation-manual.md

The AI agent is not allowed to directly implement changes without understanding the existing architecture and project state.

---

## Required Workflow

Every development task must follow this order:

### Phase 1 - Discovery

Before coding:

- Search the codebase.
- Find existing implementation.
- Read related architecture documents.
- Read project state files.
- Identify existing services, strategies, resolvers, repositories, and flows.

No code changes are allowed during this phase.

---

### Phase 2 - Architecture Understanding

The AI must explain:

- Current execution flow.
- Existing classes involved.
- Current business logic location.
- Correct extension point.

Example:

Request

↓

Controller

↓

Application Service

↓

Domain Service

↓

Repository

↓

Resource

↓

Response

The AI must discover the real flow from the project.

---

### Phase 3 - Change Plan

Before implementation, the AI must provide:

- Files that will be modified.
- Reason for each modification.
- Why the change follows the current architecture.
- Confirmation that no duplicate business logic is created.

---

### Phase 4 - Implementation

Only after understanding the architecture:

The AI must:

- Reuse existing services.
- Reuse existing patterns.
- Extend current flows.
- Keep backward compatibility.

---

## Forbidden Actions

The AI must NOT:

- Create new services before checking existing ones.
- Duplicate business logic.
- Put business logic inside Resources.
- Put business logic inside Models.
- Put business logic inside Controllers.
- Create parallel flows for existing features.
- Refactor frozen architecture without approval.

---

## Frozen Architecture Rule

If any document inside:

docs/architecture/

contains:

Status: Frozen

The AI must treat it as a mandatory rule.

Frozen architecture can only change because of:

1. Verified production bug.
2. Failing automated test.
3. New business requirement that cannot be implemented otherwise.

Personal preference or cleaner design is not a valid reason.

---

## Production State Management

After EVERY feature audit, bug fix, implementation, regression test, or production closure, the AI MUST update these files:

| File | Purpose |
|------|---------|
| docs/production-status.md | Master dashboard — one row per feature |
| docs/feature-dependencies.md | Dependency graph — what depends on what |
| docs/regression-matrix.md | Regression results per changed feature |
| docs/production-history.md | Chronological log of all changes |

### Update Rules

1. Read existing files first — never overwrite history.
2. Always base updates on current source code and verified test results.
3. Increment revision number when a feature changes.
4. Mark affected dependent features as Regression Required.
5. A feature CANNOT become Production Ready until:

- No verified production bugs remain.
- Required regression suites passed.
- Dependency graph updated.
- Regression matrix updated.
- Production history updated.

### Change Impact Analysis

When a feature changes, automatically:

1. Determine ALL dependent features.
2. Mark them as Regression Required.
3. Identify shared components modified.
4. Update all 4 state files.

---

## Investigation Manual

Every endpoint investigation must follow the instructions in:

ai/api-investigation-manual.md

This manual defines:

- How to trace execution paths.
- How to inspect every layer (routes, middleware, controllers, services, repositories, models, events, listeners, jobs, resources).
- How to discover production bugs.
- How to generate backend.md, frontend.md, qa.md, jira.md, changelog.md.
- Chunking strategy for large files.
- Investigation state tracking.

The AI MUST read the complete investigation manual before starting any endpoint investigation.

---

## Self-Application Rule

The AI MUST apply the same investigation rules defined in this document to this document itself.

Before acting on any task:

1. Determine if this document is too large to read in a single pass.
2. If large, divide into logical sections.
3. Read every section sequentially.
4. Keep track of which sections have been read.
5. Continue until the entire document has been inspected.

The AI MUST NEVER:

- Read only the beginning.
- Skip sections.
- Assume later sections contain no important information.
- Begin working before finishing this document.

---

## Chunking Strategy

When inspecting files too large to read in one pass:

1. Split by logical boundaries (namespace, imports, traits, constants, properties, constructor, public methods, protected methods, private methods, EOF).
2. If logical boundaries not possible, split by line ranges (1-300, 301-600, etc.).
3. Read every chunk sequentially.
4. Maintain an investigation state log.
5. If a method in one chunk calls another method in a later chunk, continue reading until the called method is inspected.
6. Before leaving a file, verify every line has been inspected.

---

## Final AI Principle

The AI must always follow:

Understand → Analyze → Plan → Modify → Update Project State

Never:

Modify → Discover
