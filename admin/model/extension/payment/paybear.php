<?php

class ModelExtensionPaymentPaybear extends Model
{
    public function install()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paybear` (
                `paybear_id` INT(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id` INT(11) NOT NULL,
                `token` VARCHAR(256) NULL DEFAULT NULL,
                `address` VARCHAR(256),
                `invoice` VARCHAR(256),
                `amount` DECIMAL(20, 8),
                `confirmations` INT(2) NULL DEFAULT NULL,
                `max_confirmations` INT(2) NULL DEFAULT NULL,
                `date_added` DATETIME NULL DEFAULT NULL,
                `date_modified` DATETIME NULL DEFAULT NULL,
                `payment_added` DATETIME NULL DEFAULT NULL,
                KEY `order_id` (`order_id`),
                KEY `token` (`token`)
            ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
    ");
    }
}
