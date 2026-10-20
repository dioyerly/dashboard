-- Transfer legs: one outgoing and one incoming movement per transfer reference.
-- Direction joins the uniqueness key. For receipt, refund and payment the direction is fixed per kind,
-- so their uniqueness is unchanged; for transfer it forbids two outgoing or two incoming legs per reference.
ALTER TABLE fin_movements DROP INDEX environment_id, ADD UNIQUE KEY fin_move_kind_ref_dir (environment_id,kind,reference_id,direction);
ALTER TABLE fin_movements ADD CONSTRAINT fin_move_transfer_ref CHECK (kind<>'transfer' OR reference_id IS NOT NULL);
