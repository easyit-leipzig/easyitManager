# HF86 Phase 8 – Surface self-lockout guard

HF86 Phase 8 hardens customer self-service for allowed domains and IP/CIDR networks.

- If domain enforcement is required, the last active allowed domain cannot be removed by customer self-service.
- If IP enforcement is required, the last active allowed network cannot be removed by customer self-service.
- Account API requests additionally protect the currently authorized Enterprise surface: a rule cannot be removed if no remaining rule still authorizes the current host or source IP.
- The check and removal are serialized by locking the customer row and active rule rows inside one transaction, preventing concurrent removals from bypassing the invariant.
- Superadmin management remains authoritative and is intentionally not restricted by this customer self-service guard.
- The legacy customer portal uses the same central safe-removal service for last-rule protection.
