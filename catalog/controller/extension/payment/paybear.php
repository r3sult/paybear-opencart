<?php

class ControllerExtensionPaymentPaybear extends Controller
{

    public function index()
    {
        $this->load->language('extension/payment/paybear');
        $this->load->model('checkout/order');

        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $this->model_checkout_order->addOrderHistory($order['order_id'], $this->config->get('payment_paybear_pending_status_id'), null, false);

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
        $data['currencySign'] = $this->currency->getSymbolLeft($this->session->data['currency']) ? $this->currency->getSymbolLeft($this->session->data['currency']) : $this->currency->getSymbolRight($this->session->data['currency']);
        $data['maxUnderpaymentFiat'] = $this->config->get('payment_paybear_max_underpayment');
        $data['minOverpaymentFiat'] = $this->config->get('payment_paybear_min_overpayment');
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
            $getAddress = false;
            if (count($currencies) == 1) {
                $getAddress = true;
            }

            $paybearData = $this->model_extension_payment_paybear->findByOrderId($orderId);
            $currentCurrencyToken = null;
            if ($paybearData) {
                $allPaybearPayments = $this->model_extension_payment_paybear->getPayments($orderId);
                if (!empty($allPaybearPayments)) {
                    $firstPayment = current($allPaybearPayments);
                    $currentCurrencyToken = $firstPayment['blockchain'];
                }
            }

            // tmp solution
            if ($currentCurrencyToken) {
                $currency = $this->model_extension_payment_paybear->getCurrency($currentCurrencyToken, $orderId, true);
                $currencies = array();
                $currencies[$currentCurrencyToken] = $currency;
            }

            foreach ($currencies as $token => $currency) {
                $currency = $this->model_extension_payment_paybear->getCurrency($token, $orderId, $getAddress);
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
        $this->load->model('extension/payment/paybear_transaction');

        if (empty($this->request->get['order']) || !$this->request->get['order']) {
            die('no order id');
        }

        $order = $this->model_checkout_order->getOrder($this->request->get['order']);
        $data = file_get_contents('php://input');

        if (in_array($order['order_status_id'], array(
            // $this->config->get('payment_paybear_mispaid_status_id'),
            // $this->config->get('payment_paybear_late_payment_status_id'),
            $this->config->get('payment_paybear_completed_status_id'),
        )) || empty($data) || empty($order)) {
            die();
        }

        $comment = '';

        if ($data) {
            $params = json_decode($data);
            $paybearData = $this->model_extension_payment_paybear->findByOrderId($order['order_id']);
            if (!$paybearData) {
                die();
            }

            $allPaybearPayments = $this->model_extension_payment_paybear->getPayments($order['order_id'], $params->inTransaction->hash);
            $rate = $this->model_extension_payment_paybear->getRate($params->blockchain);

            $maxConfirmations = $params->maxConfirmations;
            if (!$maxConfirmations) {
                $maxConfirmations = $paybearData['max_confirmations'];
            }

            $invoice = $params->invoice;
            $maxUnderpaymentFiat = $this->config->get('payment_paybear_max_underpayment');
            $maxUnderpaymentCrypto = $maxUnderpaymentFiat / $rate;
            $maxDifference = max($maxUnderpaymentCrypto, 0.00000001);
            $toPay = (float) $paybearData['amount'];
            $alreadyPaid = 0;
            foreach ($allPaybearPayments as $payment) {
                $alreadyPaid += $payment['amount'];
            }
            $paidNow = $params->inTransaction->amount / pow(10, $params->inTransaction->exp);
            $totalPaid = (float) $paidNow + $alreadyPaid;

            $paybearPayment = $this->model_extension_payment_paybear_transaction->findByHash($params->inTransaction->hash);
            if (!$paybearPayment) {
                $paybearPayment = [
                    'invoice' => $params->invoice,
                    'max_confirmations' => $params->maxConfirmations,
                    'order_id' => $order['order_id'],
                    'blockchain' => $params->blockchain,
                    'amount' => sprintf('%.8F', $paidNow),
                    'currency' => $order['currency_code'],
                    'address' => $paybearData['address'],
                    'transaction_hash' => $params->inTransaction->hash,
                    'date_added' => time()
                ];
            }

            if (isset($allPaybearPayments[$paybearPayment['transaction_hash']])) {
                $transactionIndex = array_search($paybearPayment['transaction_hash'], array_keys($allPaybearPayments));
                if ($transactionIndex > 0) { //avoid race conditions
                    usleep($transactionIndex * 500);
                }
            }

            $paybearPayment['confirmations'] = $params->confirmations;

            if (!isset($paybearPayment['paybear_transaction_id'])) {
                $this->model_extension_payment_paybear_transaction->insert($paybearPayment);
            } else {
                $this->model_extension_payment_paybear_transaction->update($paybearPayment['paybear_transaction_id'], $paybearPayment);
            }

            if ($toPay - $totalPaid > $maxDifference) {
                $underpaid = $toPay - $totalPaid;
                $underpaidFiat = $underpaid * $rate;
                // $underpaidFiat = round(($toPay-$totalPaid) * $rate, 2);
                if (!isset($paybearPayment['paybear_transaction_id'])) {
                    $comment = sprintf("Looks like you underpaid %.8F %s (%.2F %s)\n\nDon't worry, here is what to do next:\n\nContact the merchant directly and...\n-Request details on how you can pay the difference..\n-Request a refund and create a new order.\n\nTips for Paying with Crypto:\n\nTip 1) When paying, ensure you send the correct amount in %s. Do not manually enter the %s Value.\n\nTip 2)  If you are sending from an exchange, be sure to correctly factor in their withdrawal fees.\n\nTip 3) Be sure to successfully send your payment before the countdown timer expires.\nThis timer is setup to lock in a fixed rate for your payment. Once it expires, the rate changes.", $underpaid, strtoupper($params->blockchain), $underpaidFiat, $order['currency_code'], strtoupper($params->blockchain), $order['currency_code']);
                }
                if ($order['order_status_id'] != $this->config->get('payment_paybear_mispaid_status_id')) {
                    $this->model_extension_payment_paybear->log(sprintf('PayBear: mispaid order: %s', $order['order_id']));
                    $this->model_checkout_order->addOrderHistory($order['order_id'], $this->config->get('payment_paybear_mispaid_status_id'), $comment, true);
                }
                // return;
            }

            if ($params->confirmations >= $maxConfirmations && $maxConfirmations > 0) {
                if ($toPay > 0 && ($toPay - $totalPaid) < $maxDifference) {
                    $orderTimestamp = strtotime($order['date_added']);
                    $paymentTimestamp = strtotime($paybearPayment['date_added']);
                    $deadline = $orderTimestamp + (int) $this->config->get('payment_paybear_exchange_rate_locktime') * 60;
                    $orderStatus = $this->config->get('payment_paybear_completed_status_id');
                    $notify = true;

                    if ($paymentTimestamp > $deadline) {
                        $this->model_extension_payment_paybear->log('PayBear: late payment');

                        $fiatPaid = $totalPaid * $rate;
                        if ($order['total'] > $fiatPaid) {
                            $orderStatus = $this->config->get('payment_paybear_late_payment_status_id');
                            $this->model_extension_payment_paybear->log('PayBear: rate changed');
                            $underpaid = $toPay - $totalPaid;
                            $underpaidFiat = $underpaid * $rate;
                            $comment = sprintf('Looks like you underpaid %.8F %s (%.2F %s)\nThis was due to the payment being sent after the Countdown Timer Expired.\n\nDon\'t worry, here is what to do next:\n\nContact the merchant directly and...\n-Request details on how you can pay the difference..\n-Request a refund and create a new order.\n\nTips for Paying with Crypto:\n\nTip 1) When paying, ensure you send the correct amount in %s. Do not manually enter the %s Value.\n\nTip 2)  If you are sending from an exchange, be sure to correctly factor in their withdrawal fees.\n\nTip 3) Be sure to successfully send your payment before the countdown timer expires.\nThis timer is setup to lock in a fixed rate for your payment. Once it expires, the rate changes.', $underpaid, strtoupper($params->blockchain), $underpaidFiat, $order['currency_code'], strtoupper($params->blockchain), $order['currency_code']);
                            $notify = true;
                        } else {
                            $this->model_extension_payment_paybear->log(sprintf('PayBear: payment complete', $paidNow));
                        }
                    }

                    $overpaid = $totalPaid - $toPay;
                    $overpaidFiat = round(($totalPaid - $toPay) * $rate, 2);
                    $minOverpaymentFiat = $this->config->get('payment_paybear_min_overpayment');
                    if ($overpaidFiat > $minOverpaymentFiat) {
                        $comment = sprintf("Whoops, you overpaid: %.8F %s(%.2F %s)\n\nDon’t worry, here is what to do next:\nTo get your overpayment refunded, please contact the merchant directly and share your Order ID %s and %s Address to send your refund to.\n\nTips for Paying with Crypto:\n\nTip 1) When paying, ensure you send the correct amount in %s. Do not manually enter the %s Value.\n\nTip 2)  If you are sending from an exchange, be sure to correctly factor in their withdrawal fees.\n\nTip 3) Be sure to successfully send your payment before the countdown timer expires.\nThis timer is setup to lock in a fixed rate for your payment. Once it expires, the rate changes.", $overpaid, strtoupper($params->blockchain),$overpaidFiat, $order['currency_code'], $order['order_id'], strtoupper($params->blockchain), strtoupper($params->blockchain), strtoupper($order['currency_code']));
                        $notify = true;
                    }

                    if ($order['order_status_id'] != $orderStatus) {
                        $this->model_checkout_order->addOrderHistory($order['order_id'], $orderStatus, $comment, $notify);
                    }
                }

                echo $params->invoice;
                die();
            }

            // $this->model_extension_payment_paybear->updateData([
            //     'paybear_id' => $paybearData['paybear_id'],
            //     'confirmations' => $params->confirmations
            // ]);
            // $notify = false;

            // todo: delete all below
            // $this->model_extension_payment_paybear->log(sprintf('PayBear: incoming callback. Confirmations - %d', $params->confirmations));
            //
            // if ($params->confirmations >= $maxConfirmations) {
            //     $this->model_extension_payment_paybear->log(sprintf('PayBear: to pay %s', $toPay));
            //     $this->model_extension_payment_paybear->log(sprintf('PayBear: paid %s', $paidNow));
            //     $this->model_extension_payment_paybear->log(sprintf('PayBear: maxDifference %s', $maxDifference));
            //
            //     $orderStatus = $this->config->get('payment_paybear_mispaid_status_id');
            //
            //     if ($toPay > 0 && ($toPay - $paidNow) < $maxDifference) {
            //         $orderTimestamp = strtotime($order['date_added']);
            //         $paymentTimestamp = strtotime($paybearData['payment_added']);
            //         $deadline = $orderTimestamp + (int) $this->config->get('payment_paybear_exchange_rate_locktime') * 60;
            //         $orderStatus = $this->config->get('payment_paybear_completed_status_id');
            //         $notify = true;
            //
            //         if ($paymentTimestamp > $deadline) {
            //             $this->model_extension_payment_paybear->log('PayBear: late payment');
            //
            //             $fiatPaid = $paidNow * $rate;
            //             if ($order['total'] < $fiatPaid) {
            //                 $orderStatus = $this->config->get('payment_paybear_late_payment_status_id');
            //                 $this->model_extension_payment_paybear->log('PayBear: rate changed');
            //                 $comment = sprintf('Late Payment / Rate changed (%s %s paid, %s %s expected)', $fiatPaid, $order['currency_code'], $order['total'], $order['currency_code']);
            //                 $notify = true;
            //             } else {
            //                 $this->model_extension_payment_paybear->log(sprintf('PayBear: payment complete', $paidNow));
            //             }
            //         }
            //     } else {
            //         $this->model_extension_payment_paybear->log(sprintf('PayBear: wrong amount %s', $paidNow));
            //         $underpaid = round(($toPay-$paidNow)*$rate, 2);
            //         $comment = sprintf('Wrong Amount Paid (%s %s received, %s %s expected) - %s %s underpaid', $paidNow, $params->blockchain, $toPay, $params->blockchain, $order['currency_code'], $underpaid);
            //         $notify = true;
            //     }
            //
            //     $this->model_checkout_order->addOrderHistory($order['order_id'], $orderStatus, $comment, $notify);
            //
            //     echo $invoice; //stop further callbacks
            //     die();
            // } elseif($order['order_status_id'] != $this->config->get('payment_paybear_awaiting_confirmations_status_id')) {
            //     $this->model_checkout_order->addOrderHistory($order['order_id'], $this->config->get('payment_paybear_awaiting_confirmations_status_id'));
            //     $this->model_extension_payment_paybear->updateData([
            //         'paybear_id' => $paybearData['paybear_id'],
            //         'payment_added' => date('Y-m-d H:i:s')
            //     ]);
            // }
        }
        die();
    }

    public function status()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/paybear');

        $order = $this->model_checkout_order->getOrder($this->request->get['order']);
        $paybearData = $this->model_extension_payment_paybear->findByOrderId($order['order_id']);

        if (!$paybearData) {
            die();
        }

        $allPayments = $this->model_extension_payment_paybear->getPayments($order['order_id']);
        $toPay = $paybearData['amount'];
        $success = false;
        $unpaidConfirmations = array();
        $rate = $this->model_extension_payment_paybear->getRate($paybearData['token']);

        $maxUnderpaymentFiat = (float)$this->config->get('payment_paybear_max_underpayment');
        $maxUnderpaymentCrypto = $maxUnderpaymentFiat / $rate;
        $maxDifference = max($maxUnderpaymentCrypto, 0.00000001);
        // $maxConfirmations = $paybearData['max_confirmations'];

        $data = array();
        $coinsPaid = 0;
        foreach ($allPayments as $payment) {
            $success = false;
            $coinsPaid += $payment['amount'];
            $confirmations = $payment['confirmations'];
            $maxConfirmations = $payment['max_confirmations'];
            if (!$maxConfirmations) {
                $maxConfirmations = $paybearData['max_confirmations'];
            }
            if ($confirmations >= $maxConfirmations) {
                $success = true;
            }
            $unpaidConfirmations[] = $confirmations;
        }
        $data['coinsPaid'] = $coinsPaid;
        $data['success'] = $success && ($toPay > 0 && ($toPay - $coinsPaid) < $maxDifference);
        $data['confirmations'] = null;
        if (!empty($unpaidConfirmations)) {
            $data['confirmations'] = min($unpaidConfirmations);
        }

        echo json_encode($data);
    }

    public function confirm()
    {
        $url = $this->url->link('extension/payment/paybear/pay', '', true);
        $this->response->redirect($url);
    }
}
