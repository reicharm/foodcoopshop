ALTER TABLE `fcs_cake_payments` ADD `id_manufacturer` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `id_customer`;
UPDATE fcs_cake_action_logs SET type = 'payment_deposit_customer_added' WHERE type = 'payment_deposit_added';
UPDATE fcs_cake_action_logs SET type = 'payment_deposit_customer_deleted' WHERE type = 'payment_deposit_deleted';
