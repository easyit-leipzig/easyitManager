# HF86 Phase 5 – Secure admin bootstrap token lifecycle

## Goal

The license server must not ship an installation-specific bootstrap secret inside a release ZIP. At the same time a fresh installation without an administrator must remain self-starting.

HF86 Phase 5 therefore changes the first-admin setup token from a packaged secret into a local one-time secret generated on the target server.

## Behaviour

When `/admin/` is opened and no administrator exists:

1. `AdminAuth` first accepts an explicitly configured `admin.setup_token` when the operator deliberately provided one.
2. Otherwise it looks for the configured `admin.setup_token_file` (default: `secure/admin-setup.token`).
3. If the file is absent, PHP creates its directory if necessary and generates a 256-bit random token with `random_bytes(32)`.
4. The token file is created exclusively (`fopen(..., 'x')`) so concurrent first requests cannot silently replace each other's bootstrap secret.
5. The file is written locally, permissions are tightened where the platform supports it, and a local Apache deny file is added as defense in depth.
6. The token is never rendered into HTML and never added to a release archive. The setup page only tells the operator where to read it from the server filesystem.
7. After the first Superadmin has been created successfully, the token file is deleted as before.

An existing but invalid/too-short token file is not overwritten automatically. Setup fails closed until the operator removes or fixes that file.

## Packaging rule

`secure/` remains excluded from full and update packages. A release archive therefore contains no bootstrap token, private server key, or other installation-specific secret.

## Compatibility

Existing installations with an administrator are unaffected. Existing explicit `admin.setup_token` configuration continues to work. Existing valid token files continue to work and are still removed after successful first-admin setup.
