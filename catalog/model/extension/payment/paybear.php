<?php

class ModelExtensionPaymentPaybear extends Model
{
    public static $rates;

    public static $currencies = null;

    public static $baseUrl = 'https://api.paybear.io/v2';

    public function getMethod($address, $total)
    {
        $methodData = array(
            'code'       => 'paybear',
            'title'      => $this->config->get('payment_paybear_title'),
            'terms'      => '',
            'sort_order' => $this->config->get('payment_paybear_sort_order')
        );

        return $methodData;
    }

    public function getCurrency($token, $orderId, $getAddress = false)
    {
        $token = $this->sanitizaToken($token);
        $rate = $this->getRate($token);

        if ($rate) {
            $this->load->model('checkout/order');
            $orderInfo = $this->model_checkout_order->getOrder($orderId);
            $fiatValue = $orderInfo['total'];
            $coinsValue = round($fiatValue / $rate, 8);

            $currencies = $this->getCurrencies();
            $currency = (object) $currencies[strtolower($token)];
            $currency->coinsValue = $coinsValue;
            $currency->rate = round($this->getRate($currency->code), 2);

            if ($getAddress) {
                $currency->address = $this->getAddress($orderId, $token);
            } else {
                $currency->currencyUrl = html_entity_decode($this->url->link('extension/payment/paybear/currencies', ['token' => $token, 'order' => $orderId]));
            }

            return $currency;

        }

        echo 'can\'t get rate for ' . $token;

        return null;
    }

    public function getCurrencies()
    {
        if (self::$currencies === null) {
            $url = sprintf('%s/currencies?token=%s', self::$baseUrl, $this->config->get('payment_paybear_api_secret'));
            //$response = file_get_contents($url);
            $response = $this->url_get_contents($url);
            $data = json_decode($response, true);

            self::$currencies = $data['data'];
        }

        return self::$currencies;
    }

    public function getRate($curCode)
    {
        $rates = $this->getRates();
        $curCode = strtolower($curCode);

        return isset($rates->$curCode) ? $rates->$curCode->mid : false;
    }

    public function getRates()
    {
        if (empty(self::$rates)) {
            $needUpdate = false;
            $currency = $this->session->data['currency'];
            if (!$currency) {
                $currency = 'usd';
            }

            $ratesKey = sprintf('payment_paybear_%s_rates', strtolower($currency));
            $ratesTimestampKey = sprintf('%s_timestamp', $ratesKey);
            $ratesString = $this->config->get($ratesKey);
            $ratesTimestamp = (int) $this->config->get($ratesTimestampKey);

            if ($ratesString && $ratesTimestamp) {
                $ratesDeadline = $ratesTimestamp + $this->config->get('payment_paybear_exchange_rate_locktime') * 60;
                if ($ratesDeadline < time()) {
                    $needUpdate = true;
                }
            }

            if (!$needUpdate && !empty($ratesString)) {
                self::$rates = json_decode($ratesString);
            } else {
                $url = sprintf("%s/exchange/%s/rate", self::$baseUrl, strtolower($currency));

                //if ($response = file_get_contents($url)) {
                if ($response = $this->url_get_contents($url)) {
                    $response = json_decode($response);
                    if ($response->success) {
                        $ratesData = [];
                        $ratesData[$ratesKey] = json_encode($response->data);
                        $ratesData[$ratesTimestampKey] = time();
                        $this->editSettings($ratesData);
                        self::$rates = $response->data;
                    }
                }
            }
        }

        return self::$rates;
    }

    public function getAddress($orderId, $token = 'ETH')
    {
        $token = $this->sanitizaToken($token);
        $this->load->model('checkout/order');
        // $order =
        $data = $this->findByOrderId($orderId);
        // /** @var Order $order */
        $order = $this->model_checkout_order->getOrder($orderId);

        $rate = $this->getRate($token);

        if ($data && $this->sanitizaToken($data['token']) === $token) {
            return $data['address'];
        }

        if (!$data) {
            $data = [
                'order_id' => (int) $orderId,
                'token' => strtolower($token)
            ];
        }

        $apiSecret = $this->config->get('payment_paybear_api_secret');
        $callbackUrl = $this->url->link('extension/payment/paybear/callback', ['order' => $orderId], false);
        $callbackUrl = str_replace('&amp;', '&', $callbackUrl);

        $url = sprintf('%s/%s/payment/%s?token=%s', self::$baseUrl, strtolower($token), urlencode($callbackUrl), $apiSecret);
        //if ($response = file_get_contents($url)) {
        if ($response = $this->url_get_contents($url)) {
            $response = json_decode($response);
            $currencies = $this->getCurrencies();

            if (isset($response->data->address)) {
                $fiatAmount = $order['total'];
                $coinsAmount = round($fiatAmount / $rate, 8);

                $data['confirmations'] = null;
                $data['token'] = strtolower($token);
                $data['address'] = $response->data->address;
                $data['invoice'] = $response->data->invoice;
                $data['amount'] = $coinsAmount;
                $data['max_confirmations'] = $currencies[strtolower($token)]['maxConfirmations'];
                if (isset($data['paybear_id'])) {
                    $this->updateData($data);
                } else {
                    $this->addData($data);
                }

                return $response->data->address;
            }
        }

        return null;
    }

    public function addData($data)
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
            if (empty($value) && ($value !== 0 || $value !== '0')) {
                continue;
            }

            $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
        }

        $this->db->query("INSERT INTO " . DB_PREFIX . "paybear SET " . implode(', ', $valuesStrings));
    }

    public function updateData($data)
    {
        if (!isset($data['date_modified'])) {
            $data['date_modified'] = date('Y-m-d H:i:s');
        }

        $rowId = $data['paybear_id'];
        unset($data['paybear_id']);

        $valuesStrings = [];
        foreach ($data as $field => $value) {
            $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
        }

        $this->db->query("UPDATE " . DB_PREFIX . "paybear SET " . implode(', ', $valuesStrings) . ' WHERE paybear_id = ' . $rowId);
    }

    public function findByOrderId($orderId)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "paybear WHERE order_id = " . (int) $orderId);

        return $query->row;
    }

    public function log($message) {
        if ($this->config->get('payment_paybear_debug') == 1) {
            $log = new Log('paybear.log');
            // $backtrace = debug_backtrace();
            // $log->write('Origin: ' . $backtrace[6]['class'] . '::' . $backtrace[6]['function']);
            $log->write(print_r($message, 1));
        }
    }

    /**
     * @param string $token
     *
     * @return string
     */
    public function sanitizaToken($token)
    {
        $token = strtolower($token);
        $token = preg_replace('/[^a-z0-9:]/', '', $token);

        return $token;
    }

    public function getPayments($orderId, $excludeHash = null)
    {
        $sql = "SELECT * FROM " . DB_PREFIX . "paybear_transaction WHERE order_id = " . (int) $orderId;
        if ($excludeHash) {
            $sql .= ' AND transaction_hash != "' . $excludeHash . '"';
        }
        $query = $this->db->query($sql);
        $result = $query->rows;
        $data = [];
        foreach ($result as $row) {
            $data[$row['transaction_hash']] = $row;
        }

        return $data;
    }

    public function editSettings($data, $store_id = 0) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "setting` WHERE store_id = '" . (int)$store_id . "' AND `code` = 'payment_paybear' AND `key` IN ('" . implode("','", array_keys($data)) . "') ");
        $exists = [];
        foreach ($query->rows as $row) {
            $exists[$row['key']] = $row;
        }

        foreach ($data as $key => $value) {
            if (isset($exists[$key])) {
                $this->db->query("UPDATE " . DB_PREFIX . "setting SET `value` = '" . $this->db->escape($value) . "', serialized = '0'  WHERE `code` = 'payment_paybear' AND `key` = '" . $this->db->escape($key) . "' AND store_id = '" . (int)$store_id . "'");
            } else {
                $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '" . (int)$store_id . "', `code` = 'payment_paybear', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "'");
            }
        }
    }

    function url_get_contents ($Url) {
        if (!function_exists('curl_init')){
            die('CURL is not installed!');
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $Url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }

}
