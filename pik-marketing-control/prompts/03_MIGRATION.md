# PHASE 3 — MIGRATION

Read the Master Prompt, DATA_PROFILE and MIGRATION_MAPPING.

Implement:
PROFILE → VALIDATE → DRY RUN → FIX → MIGRATE → VERIFY → REPORT

Rules:
- never modify source Excel
- never guess relationships
- never fabricate records
- preserve source lineage
- unresolved records go to MIGRATION_ISSUES

Do not run real migration while dry-run has unexplained structural failures.
