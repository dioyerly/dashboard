-- Original demo statistics may omit origin. NULL preserves absence without invention.
ALTER TABLE project_user_stats MODIFY COLUMN origin VARCHAR(32) NULL;
