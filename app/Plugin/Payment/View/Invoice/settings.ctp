<?php

echo $this->Form->input('invoice.api_key', array(
    'label' => 'API Key',
    'type' => 'text',
    'value' => $data['PaymentMethodValue'][0]['value']
));

echo $this->Form->input('invoice.login', array(
	'label' => 'Login',
	'type' => 'text',
	'value' => $data['PaymentMethodValue'][1]['value']
));

echo $this->Form->input('invoice.default_terminal_name', array(
	'label' => 'Terminal name',
	'type' => 'text',
	'value' => $data['PaymentMethodValue'][2]['value']
));

?>