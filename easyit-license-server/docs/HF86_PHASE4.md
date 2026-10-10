# HF86 Phase 4 – Project and DataForm resource scopes

## Goal

Customer-internal roles and users can now be restricted to selected projects and selected approved DataForms. This resource scope is an additional reduction layer; it never grants capabilities.

Effective access therefore consists of:

`customer license ∩ DataForm capability policy ∩ user role capabilities ∩ resource scope`

## Server model

The license server stores role and user resource scopes centrally. Role scopes support `unrestricted` or `restricted`. User scopes support `inherit` or `restricted`.

A restricted role can contain complete project assignments and/or individual approved DataForm UUIDs. Multiple assigned roles are combined by union. A direct restricted user scope is then intersected with that role result, so a user assignment can only reduce what the roles already permit.

Existing roles and users are migrated compatibly: existing roles default to unrestricted resource access and existing users default to inheriting their roles. The protected `customer_admin` role remains unrestricted.

Only DataForms with central registry status `approved` are offered as assignable resources.

## Enterprise Manager

The live account profile contains `role_policy.resource_scope`. The manager uses it on every authenticated request to:

- filter the customer dashboard and project list;
- reject direct access to unassigned projects;
- distinguish a visible project from a fully assigned project;
- filter DataForm lists by project or individual DataForm assignment;
- reject direct access to unassigned DataForms in management and runtime bridges;
- filter relations, queries, reports and REST API endpoints to assigned DataForms;
- reserve project-wide operations such as project editing, project export, data-source/table administration, Application Builder and project packages for full project assignments.

## Compatibility rule

No resource assignment after upgrade means the same access as HF86 Phase 3. Restrictions take effect only after an administrator explicitly changes a role or user resource scope.
