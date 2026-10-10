CREATE TABLE IF NOT EXISTS df_bound_form_binding_settings (
    relation_id TEXT NOT NULL PRIMARY KEY,
    inherit_parent_value INTEGER NOT NULL DEFAULT 1
        CHECK (inherit_parent_value = 1),
    bound_field_readonly INTEGER NOT NULL DEFAULT 1
        CHECK (bound_field_readonly IN (0, 1)),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
