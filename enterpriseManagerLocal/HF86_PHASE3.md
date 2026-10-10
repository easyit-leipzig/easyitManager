# HF86 Phase 3 – Customer RBAC

The customer manager now supports tenant-local users and roles backed by the central license server. Effective access is limited by customer license ∩ DataForm policy ∩ user role. The navigation exposes “Benutzer & Rollen” only to users with the corresponding customer administration privilege. Role policy is refreshed with the central account profile on every authenticated request.
