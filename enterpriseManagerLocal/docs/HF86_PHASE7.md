# HF86 Phase 7 – IP/CIDR self-service in Mein Konto

The Enterprise Manager now exposes the same customer-managed origin restrictions for IP addresses and CIDR networks that already existed in the central customer portal.

Under **Mein Konto**, customers can view current network rules and, when `surface_self_service` is enabled by the Superadmin, add or remove IPv4/IPv6/CIDR entries.

The manager does not validate or trust these values locally. It sends them to the license server, which applies the canonical `SurfacePolicyService::normalizeCidr()` validation and tenant-scoped persistence.

This phase does not change license capabilities, roles, resource scopes, or DataForm token semantics.
