<?php
App::uses('PaymentAppController', 'Payment.Controller');

require "InvoiceSDK/RestClient.php";
require "InvoiceSDK/common/SETTINGS.php";
require "InvoiceSDK/common/ORDER.php";
require "InvoiceSDK/CREATE_TERMINAL.php";
require "InvoiceSDK/CREATE_PAYMENT.php";

class InvoiceController extends PaymentAppController
{
    public $uses = array('PaymentMethod', 'Order');
    public $module_name = 'Invoice';
    public $icon = 'invoice.png';

    public function settings ()
    {
        $this->set('data', $this->PaymentMethod->findByAlias($this->module_name));
    }

    public function install()
    {
        $new_module = array();
        $new_module['PaymentMethod']['active'] = '1';
        $new_module['PaymentMethod']['name'] = Inflector::humanize($this->module_name);
        $new_module['PaymentMethod']['icon'] = $this->icon;
        $new_module['PaymentMethod']['alias'] = $this->module_name;

        $new_module['PaymentMethodValue'][0]['payment_method_id'] = $this->PaymentMethod->id;
        $new_module['PaymentMethodValue'][0]['key'] = 'api_key';
        $new_module['PaymentMethodValue'][0]['value'] = '';

        $new_module['PaymentMethodValue'][1]['payment_method_id'] = $this->PaymentMethod->id;
        $new_module['PaymentMethodValue'][1]['key'] = 'login';
        $new_module['PaymentMethodValue'][1]['value'] = '';

        $new_module['PaymentMethodValue'][2]['payment_method_id'] = $this->PaymentMethod->id;
        $new_module['PaymentMethodValue'][2]['key'] = 'default_terminal_name';
        $new_module['PaymentMethodValue'][2]['value'] = '';

        $this->PaymentMethod->saveAll($new_module);

        $this->Session->setFlash(__('Module Installed'));
        $this->redirect('/payment_methods/admin/');
    }

    public function uninstall()
    {
        $module_id = $this->PaymentMethod->findByAlias($this->module_name);

        $this->PaymentMethod->delete($module_id['PaymentMethod']['id'], true);

        $this->Session->setFlash(__('Module Uninstalled'));
        $this->redirect('/payment_methods/admin/');
    }

    public function before_process ()
    {
        $order = $this->Order->read(null,$_SESSION['Customer']['order_id']);

        $amount = number_format($order['Order']['total'], 2, '.', '');

        $invoice_order = new INVOICE_ORDER($amount);
        $invoice_order->id = $order['Order']['id'];

        try {
            $tid = $this->getTerminal();
        } catch (Exception $e) {
            return '<h1>Произошла ошибка при создании терминала! Обратитесь к администратору</h1>';
        }

        $settings = new SETTINGS($tid);
        $settings->success_url = ( ((!empty($_SERVER['HTTPS'])) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);

        $request = new CREATE_PAYMENT($invoice_order, $settings, []);
        $response = (new RestClient($this->getSettings('login'), $this->getSettings('api_key')))->CreatePayment($request);

        if($response == null or isset($response->error)) {
            return '<h1>Произошла ошибка при создании терминала! Обратитесь к администратору</h1>';
        }

        $content = '
		<form action="'.$response->payment_url.'" method="get">
			<button class="btn btn-default" type="submit" value="{lang}Confirm Order{/lang}"><i class="fa fa-check"></i> {lang}Confirm Order{/lang}</button>
        </form>';

        $default_status = $this->Order->OrderStatus->find('first', array('conditions' => array('default' => '1')));
        $order['Order']['order_status_id'] = $default_status['OrderStatus']['id'];

        $this->Order->save($order);

        return $content;

    }

    public function after_process()
    {
    }

    public function callback()
    {
        $postData = file_get_contents('php://input');
        $notification = json_decode($postData, true);


        $type = $notification["notification_type"];
        $id = $notification["order"]["id"];

        $order = $this->Order->read(null, $id);

        if(!$order) {
            die('order not found');
        }

        $signature = $notification["signature"];

        if($signature != $this->getSignature($notification["id"], $notification["status"], $this->getSettings('api_key'))) {
            die("Wrong signature");
        }

        if($type == "pay") {

            if($notification["status"] == "successful") {

                $payment_method = $this->PaymentMethod->find('first', array('conditions' => array('alias' => $this->module_name)));

                $order_data = $this->Order->find('first', array('conditions' => array('Order.id' => $id)));
                $order_data['Order']['order_status_id'] = $payment_method['PaymentMethod']['order_status_id'];

                $this->Order->save($order_data);

                die('payment successful');
            }
            if($notification["status"] == "error") {
                die('payment failed');
            }
        }

        die('null');
    }

    public function getTerminal() {
        if(!file_exists('invoice_tid')) file_put_contents('invoice_tid', '');
        $tid = file_get_contents('invoice_tid');

        if($tid == null or empty($tid)) {
            $request = new CREATE_TERMINAL($this->getSettings('default_terminal_name'));
            $response = (new RestClient($this->getSettings('login'), $this->getSettings('api_key')))->CreateTerminal($request);

            if($response == null or isset($response->error)) throw new Exception('Terminal error');

            $tid = $response->id;
            file_put_contents('invoice_tid', $tid);
        }

        return $tid;
    }

    public function getSettings($name) {
        return $this->PaymentMethod->PaymentMethodValue->find('first', array('conditions' => array('key' => $name)))['PaymentMethodValue']['value'];
    }

    public function getSignature($id, $status, $key) {
        return md5($id.$status.$key);
    }
}