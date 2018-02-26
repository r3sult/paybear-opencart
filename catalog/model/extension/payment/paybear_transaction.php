<?php

class ModelExtensionPaymentPaybearTransaction extends Model
{
    public function findByHash($hash)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "paybear_transaction WHERE transaction_hash = '" . $hash . "'");

        return $query->row;
    }

    public function insert($data)
    {
        $now = date('Y-m-d H:i:s');
        if (!isset($data['date_added'])) {
            $data['date_added'] = $now;
        }

        if (!isset($data['date_modified'])) {
            $data['date_modified'] = $now;
        }

        $valuesStrings = [];
        foreach ($data as $field => $value) {
            $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
        }

        $this->db->query("INSERT INTO " . DB_PREFIX . "paybear_transaction SET " . implode(', ', $valuesStrings));
    }

    public function update($id, $data) {
        if (!isset($data['date_modified'])) {
            $data['date_modified'] = date('Y-m-d H:i:s');
        }

        if (isset($data['paybear_transaction_id'])) {
            unset($data['paybear_transaction_id']);
        }

        $valuesStrings = [];
        foreach ($data as $field => $value) {
            $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
        }

        $this->db->query("UPDATE " . DB_PREFIX . "paybear_transaction SET " . implode(', ', $valuesStrings) . ' WHERE paybear_transaction_id = ' . (int) $id);
    }
}
