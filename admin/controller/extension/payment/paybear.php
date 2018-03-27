<?php

class ControllerExtensionPaymentPaybear extends Controller
{
    private $error = [];

    public function index()
    {
        $this->load->model('setting/setting');
        $this->load->model('extension/payment/paybear');
        $this->load->language('extension/payment/paybear');

        $installedVersion = $this->config->get('payment_paybear_version');
        if (!$installedVersion) {
            $installedVersion = '0.2.0';
        }
        $currentVersion = $this->model_extension_payment_paybear->getVersion();

        if (version_compare($currentVersion, $installedVersion) > 0) {
            $this->model_extension_payment_paybear->upgrade($installedVersion);
            $this->model_setting_setting->editSettingValue('payment_paybear', 'payment_paybear_version', $currentVersion);
        }

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_paybear', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['log_filename'] = 'paybear.log';
        $data['log_lines'] = $this->readLastLines(DIR_LOGS . $data['log_filename'], 500);
        $data['clear_log'] = str_replace('&amp;', '&', $this->url->link('extension/payment/paybear/clearlog', 'user_token=' . $this->session->data['user_token'], true));
        $data['default_currency'] = $this->config->get('config_currency');

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/paybear', 'user_token=' . $this->session->data['user_token'], true)
        ];


        $data['action'] = $this->url->link('extension/payment/paybear', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $this->load->model('extension/payment/paybear');

        $fields = array(
            'payment_paybear_title',
            'payment_paybear_description',
            'payment_paybear_api_secret',
            'payment_paybear_api_public',
            'payment_paybear_exchange_rate_locktime',
            'payment_paybear_completed_status_id',
            'payment_paybear_awaiting_confirmations_status_id',
            // 'payment_paybear_failed_status_id',
            'payment_paybear_pending_status_id',
            'payment_paybear_mispaid_status_id',
            'payment_paybear_late_payment_status_id',
            'payment_paybear_status',
            'payment_paybear_debug',
            'payment_paybear_max_underpayment',
            'payment_paybear_min_overpayment',
            'payment_paybear_version'
        );

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/paybear', $data));
    }

    public function install()
    {
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/language');

        $langs = $this->model_localisation_language->getLanguages();

        $defaultParams = [
            'payment_paybear_version' => '0.4.0',
            'payment_paybear_status' => 1,
            'payment_paybear_title' => 'Crypto via PayBear.io (BTC/ETH/LTC and more)',
            'payment_paybear_exchange_rate_locktime' => 15,
            'payment_paybear_max_underpayment' => 0.01,
            'payment_paybear_min_overpayment' => 1,
        ];

        $orderStatuses = $this->model_localisation_order_status->getOrderStatuses();
        foreach ($orderStatuses as $status) {
            if ($status['name'] == 'PayBear: Payment Accepted') {
                $defaultParams['payment_paybear_completed_status_id'] = $status['order_status_id'];
            }
            if ($status['name'] == 'PayBear: Awaiting Confirmations') {
                $defaultParams['payment_paybear_awaiting_confirmations_status_id'] = $status['order_status_id'];
            }
            if ($status['name'] == 'PayBear: Mispaid') {
                $defaultParams['payment_paybear_mispaid_status_id'] = $status['order_status_id'];
            }
            if ($status['name'] == 'PayBear: Late Payment') {
                $defaultParams['payment_paybear_late_payment_status_id'] = $status['order_status_id'];
            }
        }


        foreach ($langs as $lang) {
            if (!isset($defaultParams['payment_paybear_completed_status_id'])) {
                $this->model_localisation_order_status->addOrderStatus([
                    'order_status' => [
                        $lang['language_id'] => ['name' => 'PayBear: Payment Accepted']
                    ]
                ]);

                $defaultParams['payment_paybear_completed_status_id'] = $this->db->getLastId();
            }

            if (!isset($defaultParams['payment_paybear_awaiting_confirmations_status_id'])) {
                $this->model_localisation_order_status->addOrderStatus([
                    'order_status' => [
                        $lang['language_id'] => ['name' => 'PayBear: Awaiting Confirmations']
                    ]
                ]);

                $defaultParams['payment_paybear_awaiting_confirmations_status_id'] = $this->db->getLastId();
            }

            if (!isset($defaultParams['payment_paybear_mispaid_status_id'])) {
                $this->model_localisation_order_status->addOrderStatus([
                    'order_status' => [
                        $lang['language_id'] => ['name' => 'PayBear: Mispaid']
                    ]
                ]);

                $defaultParams['payment_paybear_mispaid_status_id'] = $this->db->getLastId();
            }

            if (!isset($defaultParams['payment_paybear_late_payment_status_id'])) {
                $this->model_localisation_order_status->addOrderStatus([
                    'order_status' => [
                        $lang['language_id'] => ['name' => 'PayBear: Late Payment']
                    ]
                ]);

                $defaultParams['payment_paybear_late_payment_status_id'] = $this->db->getLastId();
            }

            break;
        }

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('payment_paybear', $defaultParams);

        $this->load->model('extension/payment/paybear');
        $this->model_extension_payment_paybear->install();
    }

    public function uninstall()
    {
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/language');
        $this->load->model('setting/setting');

        $this->model_setting_setting->deleteSetting('payment_paybear');

        $this->db->query("DELETE FROM " . DB_PREFIX . "order_status WHERE name = 'PayBear: Payment Accepted' ");
    }

    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/paybear')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }

    public function clearLog()
    {
        $json = [];
        $this->load->language('extension/payment/paybear');
        if ($this->validatePermission()) {
            if (is_file(DIR_LOGS . 'paybear.log')) {
                @unlink(DIR_LOGS . 'paybear.log');
            }
            $json['success'] = $this->language->get('text_clear_log_success');
        } else {
            $json['error'] = $this->language->get('error_permission');
        }

        $this->response->addHeader('Content-Type: applicationbn/json');
        $this->response->setOutput(json_encode($json));
    }

    protected function validatePermission()
    {
        return $this->user->hasPermission('modify', 'extension/payment/paybear');
    }

    protected function readLastLines($filename, $lines)
    {
        if (!is_file($filename)) {
            return [];
        }
        $handle = @fopen($filename, "r");
        if (!$handle) {
            return [];
        }
        $linecounter = $lines;
        $pos = -1;
        $beginning = false;
        $text = [];

        while ($linecounter > 0) {
            $t = " ";

            while ($t != "\n") {
                /* if fseek() returns -1 we need to break the cycle*/
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }

            $linecounter--;

            if ($beginning) {
                rewind($handle);
            }

            $text[$lines - $linecounter - 1] = fgets($handle);

            if ($beginning) {
                break;
            }
        }
        fclose($handle);

        return array_reverse($text);
    }
}
