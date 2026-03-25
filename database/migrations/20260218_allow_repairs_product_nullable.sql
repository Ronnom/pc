-- Migration: allow repairs.product_id to be NULL (intake without product selection)
ALTER TABLE repairs MODIFY COLUMN product_id BIGINT UNSIGNED NULL;
