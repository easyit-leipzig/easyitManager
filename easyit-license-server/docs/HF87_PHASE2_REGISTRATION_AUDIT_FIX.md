# HF87 Phase 2 – Registration/Audit Fix

Fixes a PHP strict-type error in audit calls that caused `/api/v1/account/register` and successful account login to return `SERVER_INTERNAL_ERROR`.

## Cause
`AuditService::write()` requires arrays for `old` and `new`. HF87 Phase 1 passed `null` as the `old` value in the registration and successful-login audit calls. With `declare(strict_types=1)` this raises a `TypeError`; registration is rolled back and the public API exposes `SERVER_INTERNAL_ERROR`.

## Fix
- `AccountService`: use `[]` instead of `null` for old audit data on registration and login.
- `DesignerService`: correct the same legacy audit argument misuse.
- `RuntimeService`: correct the same legacy audit argument misuse.

No database migration is required. Existing keys, `config/app.php`, and database data must be retained.
