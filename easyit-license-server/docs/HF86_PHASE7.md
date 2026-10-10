# HF86 Phase 7 – Enterprise IP/CIDR self-service parity

Phase 7 closes the remaining self-service gap between the central customer portal and the Enterprise Manager.

The account API now exposes authenticated customer endpoints for adding and removing allowed IP addresses and CIDR networks:

- `POST /api/v1/account/network/add`
- `POST /api/v1/account/network/remove`

Both endpoints:

- require a valid central account session,
- re-run the current surface/license check,
- require `surface_self_service = 1`,
- normalize and validate IPv4/IPv6/CIDR values through `SurfacePolicyService::normalizeCidr()`,
- are tenant-scoped by `customer_id`,
- and emit audit records.

No license, role, DataForm, or project authorization semantics are changed in this phase.
