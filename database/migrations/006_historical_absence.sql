-- Existing values are retained. Only future missing/explicit NULL source values become NULL.
ALTER TABLE customers MODIFY COLUMN consent TINYINT UNSIGNED NULL, MODIFY COLUMN origin VARCHAR(32) NULL;
ALTER TABLE plans MODIFY COLUMN trial_days INT UNSIGNED NULL DEFAULT NULL;
