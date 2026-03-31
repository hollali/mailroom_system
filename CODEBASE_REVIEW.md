# Codebase Review – Mailroom System

Date: 2026-03-31
Reviewer: GPT-5.3-Codex

## Scope Reviewed

- Application pages: `index.php`, `documents.php`, `document_type.php`, `distribution.php`, `distribution_history.php`, `parcels.php`, `list.php`, `newspaper_categories.php`, `newspaper_distribution.php`, `recipients.php`, `sidebar.php`
- Configuration/database: `config/db.php`, `config/mailroom_system.sql`
- Documentation: `Readme.md`, `PRESENTATION_SCRIPT.md`

## High-priority findings

1. **Hardcoded database credentials in source control**
   - `config/db.php` stores host/user/password directly in code, including a plaintext password.
   - **Risk:** credential leakage, accidental reuse across environments, compromise if repository is shared.
   - **Recommendation:** move credentials to environment variables (`$_ENV` / `getenv`) and keep a `db.example.php` template for local setup.

2. **Production-unsafe error settings enabled in runtime pages**
   - Multiple entry pages force `error_reporting(E_ALL)` and `ini_set('display_errors', 1)`.
   - **Risk:** stack traces and SQL/runtime details can be disclosed to users.
   - **Recommendation:** gate debug settings by environment (e.g., `APP_ENV=development`), default to `display_errors=0` in production.

3. **State-changing operations are exposed via GET and lack CSRF protection**
   - Mutating actions (e.g., recipient delete/activate) are triggered directly from query params, and POST forms do not include token verification.
   - **Risk:** CSRF and unintended actions via crafted links.
   - **Recommendation:** move all mutations to POST-only endpoints and add per-session CSRF tokens validated server-side.

## Medium-priority findings

4. **Broken routes / inconsistent filenames**
   - Code and navigation reference routes that do not exist in the repo (`available.php`, `document_types.php`, `document_distribution.php`).
   - **Risk:** redirects/navigation fail, active menu states are wrong, user confusion.
   - **Recommendation:** standardize route names to existing files and centralize route constants to avoid drift.

5. **Mixed query safety model**
   - The code uses prepared statements in many places (good), but still has several dynamically concatenated queries.
   - **Risk:** future regressions and uneven security posture.
   - **Recommendation:** migrate all dynamic SQL to prepared statements, even when values are cast, for consistency and maintainability.

## Strengths observed

- Good use of prepared statements in many create/update/delete paths.
- Session-based toast/feedback patterns are consistent and improve user UX.
- Pagination/search/filter UX is implemented across multiple modules.
- Transaction handling is present for multi-step distribution operations.

## Suggested remediation order

1. Remove hardcoded secrets + add environment-based config.
2. Disable display errors in production + add centralized error handling/logging.
3. Implement CSRF tokens + enforce POST for all mutating endpoints.
4. Fix route/file naming mismatches across redirects/sidebar/docs.
5. Normalize all SQL calls to prepared statements.

