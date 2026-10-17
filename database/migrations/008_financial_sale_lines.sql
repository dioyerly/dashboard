-- Adds immutable catalog references to financial sale lines.
ALTER TABLE fin_sale_lines ADD version_id VARCHAR(64) COLLATE utf8mb4_bin NULL;
ALTER TABLE fin_sale_lines ADD CONSTRAINT fin_line_product_fk FOREIGN KEY(environment_id,product_id) REFERENCES products(environment_id,id) ON DELETE RESTRICT;
ALTER TABLE fin_sale_lines ADD CONSTRAINT fin_line_version_fk FOREIGN KEY(environment_id,version_id) REFERENCES product_versions(environment_id,id) ON DELETE RESTRICT;
