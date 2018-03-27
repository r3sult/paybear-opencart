<?php

class ModelExtensionPaymentPaybear extends Model
{
    public $version = '0.4.0';

    public function getVersion()
    {
        return $this->version;
    }

    public function install()
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paybear` (
                `paybear_id` INT(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
                `order_id` INT(11) NOT NULL,
                `token` VARCHAR(255) NULL DEFAULT NULL,
                `address` VARCHAR(255),
                `invoice` VARCHAR(255),
                `amount` DECIMAL(20, 8),
                `confirmations` INT(2) NULL DEFAULT NULL,
                `max_confirmations` INT(2) NULL DEFAULT NULL,
                `date_added` DATETIME NULL DEFAULT NULL,
                `date_modified` DATETIME NULL DEFAULT NULL,
                KEY `order_id` (`order_id`),
                KEY `token` (`token`)
            ) ENGINE=MyISAM DEFAULT COLLATE=utf8_general_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `". DB_PREFIX ."paybear_transaction` (
              `paybear_transaction_id` INT(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
              `order_id` VARCHAR(9) NOT NULL,
              `invoice` VARCHAR(255) NULL DEFAULT NULL,
              `blockchain` VARCHAR(255) NULL DEFAULT NULL,
              `address` VARCHAR(255) NULL DEFAULT NULL,
              `amount` DECIMAL(20, 8),
              `currency` VARCHAR(255) NULL DEFAULT NULL,
              `rate` DECIMAL(20, 8) NULL DEFAULT NULL,
              `transaction_hash` VARCHAR(255) NULL DEFAULT NULL,
              `confirmations` INT(2) NULL DEFAULT NULL,
              `max_confirmations` INT(2) NULL DEFAULT NULL,
              `date_added` DATETIME NULL DEFAULT NULL,
              `date_modified` DATETIME NULL DEFAULT NULL,
              KEY `order_id` (`order_id`),
              KEY `blockchain` (`blockchain`),
              KEY `transaction_hash` (`transaction_hash`)
            ) ENGINE = MyISAM DEFAULT COLLATE=utf8_general_ci;
        ");
    }

    public function upgrade($fromVersion)
    {
        $allMethods = get_class_methods($this);
        $methodsToRun = [];
        foreach ($allMethods as $method) {
            if (strstr($method, 'upgrade_')) {
                $methodVersion = str_replace('_', '.', str_replace('upgrade_', '', $method));
                if (version_compare($methodVersion, $fromVersion) > 0) {
                    $methodsToRun[$methodVersion] = $method;
                }
            }
        }
        uasort($methodsToRun, 'version_compare');

        foreach ($methodsToRun as $method) {
            call_user_func([$this, $method]);
        }

    }

    public function upgrade_0_3_0()
    {
        $oldData = $this->db->query("SELECT * FROM `" . DB_PREFIX . "paybear`");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `". DB_PREFIX ."paybear_transaction` (
              `paybear_transaction_id` INT(11) NOT NULL PRIMARY KEY AUTO_INCREMENT,
              `order_id` VARCHAR(9) NOT NULL,
              `invoice` VARCHAR(255) NULL DEFAULT NULL,
              `blockchain` VARCHAR(255) NULL DEFAULT NULL,
              `address` VARCHAR(255) NULL DEFAULT NULL,
              `amount` DECIMAL(20, 8),
              `currency` VARCHAR(255) NULL DEFAULT NULL,
              `rate` DECIMAL(20, 8) NULL DEFAULT NULL,
              `transaction_hash` VARCHAR(255) NULL DEFAULT NULL,
              `confirmations` INT(2) NULL DEFAULT NULL,
              `max_confirmations` INT(2) NULL DEFAULT NULL,
              `date_added` DATETIME NULL DEFAULT NULL,
              `date_modified` DATETIME NULL DEFAULT NULL,
              KEY `order_id` (`order_id`),
              KEY `blockchain` (`blockchain`),
              KEY `transaction_hash` (`transaction_hash`)
            ) ENGINE = MyISAM DEFAULT COLLATE=utf8_general_ci;
        ");

        foreach ($oldData->rows as $row) {
            $order = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE order_id = " . $row['order_id'])->row;
            if ($order['order_status_id'] != $this->config->get('payment_paybear_completed_status_id')) {
                continue;
            }

            $transactionData = [
                'order_id' => $order['order_id'],
                'invoice' => $row['invoice'],
                'blockchain' => $row['token'],
                'address' => $row['address'],
                'amount' => $row['amount'],
                'confirmations' => $row['confirmations'],
                'max_confirmations' => $row['max_confirmations'],
                'date_added' => $row['payment_added'],
                'date_modified' => $row['payment_added']
            ];

            $valuesStrings = [];
            foreach ($transactionData as $field => $value) {
                if (empty($value) && ($value !== 0 || $value !== '0')) {
                    continue;
                }

                $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
            }

            $this->db->query("INSERT INTO " . DB_PREFIX . "paybear_transaction SET " . implode(', ', $valuesStrings));

        }
    }
}
