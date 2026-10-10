# HF86 Phase 3 – Customer RBAC

HF86 Phase 3 adds central tenant-scoped customer users and roles. Effective permissions are the intersection of the customer license, the approved DataForm policy, and the signed-in customer's role policy. Default roles are Kundenadministrator, Bearbeiter, and Leser. Role changes are reloaded through the account profile on every authenticated Enterprise request.

New central tables: customer_roles, customer_role_capabilities, customer_user_roles.
New account endpoints: /api/v1/account/team, /team/user/create, /team/user/set, /team/role/save.
