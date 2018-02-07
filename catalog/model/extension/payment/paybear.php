<?php

class ModelExtensionPaymentPaybear extends Model
{
    public static $rates;

    public static $currencies = null;

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
        $rate = $this->getRate($token);

        if ($rate) {
            $this->load->model('checkout/order');
            $orderInfo = $this->model_checkout_order->getOrder($orderId);
            $fiatValue = $orderInfo['total'];
            $coinsValue = round($fiatValue / $rate, 8);

            $currencies = $this->getCurrencies();
            $currency = (object) $currencies[strtolower($token)];
            $currency->coinsValue = $coinsValue;
            // $formattedRate = number_format($currency->rate, 2, $this->language->get('decimal_point'), $this->language->get('thousand_point'));
            // $currency->rate = $formattedRate;


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
            $url = sprintf('https://api.paybear.io/v2/currencies?token=%s', $this->config->get('payment_paybear_api_secret'));
            $response = file_get_contents($url);
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
            $currency = $this->session->data['currency'];
            if (!$currency) {
                $currency = 'USD';
            }

            $url = sprintf("https://api.paybear.io/v2/exchange/%s/rate", strtolower($currency));

            if ($response = file_get_contents($url)) {
                $response = json_decode($response);
                if ($response->success) {
                    self::$rates = $response->data;
                }
            }
        }

        return self::$rates;
    }

    public function getAddress($orderId, $token = 'ETH')
    {
        $this->load->model('checkout/order');
        // $order =
        $data = $this->findByOrderId($orderId);
        // /** @var Order $order */
        $order = $this->model_checkout_order->getOrder($orderId);

        $rate = $this->getRate($token);

        if ($data && strtolower($data['token']) == strtolower($token)) {
            return $data['address'];
        } elseif (!$data) {
            $data = [
                'order_id' => (int) $orderId,
                'token' => strtolower($token)
            ];
        }

        $apiSecret = $this->config->get('payment_paybear_api_secret');
        $callbackUrl = $this->url->link('extension/payment/paybear/callback', ['order' => $orderId], false); //$this->context->link-
        $callbackUrl = str_replace('&amp;', '&', $callbackUrl);

        $url = sprintf('https://api.paybear.io/v2/%s/payment/%s?token=%s', strtolower($token), urlencode($callbackUrl), $apiSecret);
        if ($response = file_get_contents($url)) {
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

}
