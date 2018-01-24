<?php

class ControllerExtensionPaymentPaybear extends Controller
{

    public function index()
    {
        $this->load->language('extension/payment/paybear');
        $this->load->model('checkout/order');

        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['currenciesUrl'] = $this->url->link('extension/payment/paybear/currencies', array(
            'order' => $this->session->data['order_id']
        ), true);
        $data['statusUrl'] = $this->url->link('extension/payment/paybear/status', [
            'order' => $this->session->data['order_id']
        ], true);
        $data['callbackUrl'] = $this->url->link('extension/payment/paybear/callback', '', true);
        $data['redirectUrl'] = $this->url->link('checkout/success', '', true);
        $data['fiatValue'] = $order['total'];
        $data['currencyIso'] = $order['currency_code'];
        // $this->session->data['from_paybear'] = true;

        return $this->load->view('extension/payment/paybear', $data);
    }

    public function currencies()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/paybear');
        $orderId = $this->request->get['order'];
        $orderInfo = $this->model_checkout_order->getOrder($orderId);
        // $currencies = $this->model_extension_payment_paybear->getCurrency();

        if (isset($this->request->get['token'])) {
            $token = $this->request->get['token'];
            $data = $this->model_extension_payment_paybear->getCurrency($token, $orderId, true);
        } else {
            $data = [];
            $currencies = $this->model_extension_payment_paybear->getCurrencies();
            foreach ($currencies as $token => $currency) {
                $currency = $this->model_extension_payment_paybear->getCurrency($token, $orderId);
                if ($currency) {
                    $data[] = $currency;
                }
            }
        }

        echo json_encode($data);
    }

    public function callback()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/paybear');

        $order = $this->model_checkout_order->getOrder($this->request->get['order']);
        $data = file_get_contents('php://input');

        if ($data) {
            $paybearData = $this->model_extension_payment_paybear->findByOrderId($order['order_id']);

            $params = json_decode($data);
            $currencies = $this->model_extension_payment_paybear->getCurrencies();
            $maxConfirmations = $currencies[$paybearData['token']]['maxConfirmations'];
            $invoice = $params->invoice;
            $this->model_extension_payment_paybear->updateData([
                'paybear_id' => $paybearData['paybear_id'],
                'confirmations' => $params->confirmations
            ]);

            $this->model_extension_payment_paybear->log(sprintf('PayBear: incoming callback. Confirmations - %d', $params->confirmations));

            if ($params->confirmations >= $maxConfirmations) {
                $toPay = $paybearData['amount'];
                $amountPaid = $params->inTransaction->amount / pow(10, $params->inTransaction->exp);
                $rate = $this->model_extension_payment_paybear->getRate($params->blockchain);
                $fiatPaid = $amountPaid * $rate;
                $maxDifference = 0.00000001;

                $this->model_extension_payment_paybear->log(sprintf('PayBear: to pay %s', $toPay));
                $this->model_extension_payment_paybear->log(sprintf('PayBear: paid %s', $amountPaid));
                $this->model_extension_payment_paybear->log(sprintf('PayBear: maxDifference %s', $maxDifference));

                $orderStatus = $this->config->get('payment_paybear_failed_status_id');

                if ($toPay > 0 && ($toPay - $fiatPaid) < $maxDifference) {
                    $orderTimestamp = strtotime($order['date_added']);
                    $paymentTimestamp = strtotime($paybearData['payment_added']);
                    $deadline = $orderTimestamp + (int) $this->config->get('payment_paybear_exchange_rate_locktime') * 60;
                    $orderStatus = $this->config->get('payment_paybear_completed_status_id');

                    if ($paymentTimestamp > $deadline) {
                        $this->model_extension_payment_paybear->log('PayBear: late payment');

                        $fiatPaid = $amountPaid * $rate;
                        if ($order['total'] < $fiatPaid) {
                            $orderStatus = $this->config->get('payment_paybear_failed_status_id');
                            $this->model_extension_payment_paybear->log('PayBear: rate changed');
                        } else {
                            $this->model_extension_payment_paybear->log(sprintf('PayBear: payment complete', $amountPaid));
                        }
                    }
                } else {
                    $this->model_extension_payment_paybear->log(sprintf('PayBear: wrong amount %s', $amountPaid));
                }

                $this->model_checkout_order->addOrderHistory($order['order_id'], $orderStatus);

                echo $invoice; //stop further callbacks
                die();
            } elseif($order['order_status_id'] != $this->config->get('payment_paybear_awaiting_confirmations_status_id')) {
                $this->model_checkout_order->addOrderHistory($order['order_id'], $this->config->get('payment_paybear_awaiting_confirmations_status_id'));
                $this->model_extension_payment_paybear->updateData([
                    'paybear_id' => $paybearData['paybear_id'],
                    'payment_added' => date('Y-m-d H:i:s')
                ]);
            }
        }
        die();
    }

    public function status()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/paybear');
        $order = $this->model_checkout_order->getOrder($this->request->get['order']);
        $paybearData = $this->model_extension_payment_paybear->findByOrderId($order['order_id']);

        $minConfirmations = $this->config->get('payment_paybear_' . strtolower($paybearData['token']) . '_confirmations');
        $confirmations = $paybearData['confirmations'];
        $data = array();
        if ($confirmations >= $minConfirmations) { //set max confirmations
            $data['success'] = true;
        } else {
            $data['success'] = false;
        }
        if (is_numeric($confirmations)) {
            $data['confirmations'] = $confirmations;
        }

        echo json_encode($data);
    }

    public function confirm()
    {
        $url = $this->url->link('extension/payment/paybear/pay', '', true);
        $this->response->redirect($url);
    }

    public function pay()
    {
        $data = array();
        $this->document->addScript('/bla.js');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');
        $this->response->setOutput($this->load->view('extension/payment/paybear_pay', $data));
    }
}
