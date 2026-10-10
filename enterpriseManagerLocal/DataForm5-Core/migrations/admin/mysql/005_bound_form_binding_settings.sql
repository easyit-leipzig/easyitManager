SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET collation_connection = 'utf8mb4_unicode_ci';

CREATE TABLE IF NOT EXISTS df_bound_form_binding_settings (
    relation_id VARCHAR(191) NOT NULL,
    inherit_parent_value TINYINT(1) NOT NULL DEFAULT 1,
    bound_field_readonly TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (relation_id),

    CONSTRAINT chk_df_binding_inherit_parent
        CHECK (inherit_parent_value = 1),

    CONSTRAINT chk_df_binding_readonly
        CHECK (bound_field_readonly IN (0, 1))
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
