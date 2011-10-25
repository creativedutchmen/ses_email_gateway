<?php

require_once(TOOLKIT . '/class.emailgateway.php');
require_once(TOOLKIT . '/class.emailhelper.php');
require_once(dirname(__FILE__) . '/../lib/sdk.class.php');
require_once(dirname(__FILE__) . '/../lib/services/ses.class.php');

Class Amazon_SESGateway extends EmailGateway{

	protected $_amazon_ses;
	
	protected $_aws_key;
	protected $_aws_secret_key;
	protected $_return_path;
	protected $_fallback;

	public function __construct(){
		parent::__construct();
		$this->setSenderEmailAddress(Symphony::Configuration()->get('from_address', 'email_amazon_ses') ? Symphony::Configuration()->get('from_address', 'email_amazon_ses') : 'noreply@' . HTTP_HOST);
		$this->setSenderName(Symphony::Configuration()->get('from_name', 'email_amazon_ses') ? Symphony::Configuration()->get('from_name', 'email_amazon_ses') : 'Symphony');
		$this->setAwsKey(Symphony::Configuration()->get('aws_key', 'email_amazon_ses'));
		$this->setAwsSecretKey(Symphony::Configuration()->get('aws_secret_key', 'email_amazon_ses'));
		$this->setFallback(Symphony::Configuration()->get('fallback', 'email_amazon_ses'));
		$this->setReturnPath(Symphony::Configuration()->get('return_path', 'email_amazon_ses'));
		if(Symphony::Configuration()->get('aws_key', 'email_amazon_ses') && Symphony::Configuration()->get('aws_secret_key', 'email_amazon_ses')){
			$this->_amazon_ses = new AmazonSES(Symphony::Configuration()->get('aws_key', 'email_amazon_ses'), Symphony::Configuration()->get('aws_secret_key', 'email_amazon_ses'));
		}
	}

	public function about(){
		return array(
			'name' => 'Amazon SES'
		);
	}

	public function getPreferencesPane(){
		$group = new XMLElement('fieldset');
		$group->setAttribute('class', 'settings pickable');
		$group->setAttribute('id', 'amazon_ses');
		$group->appendChild(new XMLElement('legend', __('Amazon SES')));
		
		$div = new XMLElement('div');
		$div->setAttribute('class', 'group');
		$label = Widget::Label(__('AWS Key'));
		$label->appendChild(Widget::Input('settings[email_amazon_ses][aws_key]', $this->_aws_key));
		$div->appendChild($label);

		$label = Widget::Label(__('AWS Secret Key'));
		$label->appendChild(Widget::Input('settings[email_amazon_ses][aws_secret_key]', $this->_aws_secret_key));
		$div->appendChild($label);
		$group->appendChild($div);

		$group->appendChild(new XMLElement('p', 'The aws_key and aws_secret key are stored in plain text. It is strongly recommended to use a different aws key for each application within Amazon.', array('class' => 'help')));

		$div = new XMLElement('div');
		$div->setAttribute('class', 'group');
		$label = Widget::Label(__('From Name'));
		$label->appendChild(Widget::Input('settings[email_amazon_ses][from_name]', $this->_sender_name));
		$div->appendChild($label);

		$label = Widget::Label(__('From Email Address'));
		$label->appendChild(Widget::Input('settings[email_amazon_ses][from_address]', $this->_sender_address));
		$div->appendChild($label);
		$group->appendChild($div);

		if($this->_aws_key && $this->_aws_secret_key){
			if(is_null($this->_amazon_ses)){
				$this->_amazon_ses = new AmazonSES($this->_aws_key, $this->_aws_secret_key);
			}
			$list = $this->_amazon_ses->list_verified_email_addresses();
			$in_array = false;
			foreach($list->body->ListVerifiedEmailAddressesResult->VerifiedEmailAddresses->member as $email){
				if($email == $this->_sender_address){
					$in_array = true;
				}
			}
			if($in_array == true){
				$group->appendChild(new XMLElement('p', 'This address is confirmed. You can send emails using this address.', array('class' => 'help')));
			}
			else{
				$group->appendChild(new XMLElement('p', 'This email address is not yet confirmed. An email is sent with instructions on how to confirm.', array('class' => 'help')));
				$this->_amazon_ses->verify_email_address($this->_sender_address);
			}
			var_dump($this->_amazon_ses->get_send_quota()->body->GetSendQuotaResult);
		}

		$label = Widget::Label(__('Return Path'));
		$label->appendChild(new XMLElement('i', __('Optional')));
		$label->appendChild(Widget::Input('settings[email_amazon_ses][return_path]', $this->_return_path));
		$group->appendChild($label);
		$group->appendChild(new XMLElement('p', 'This address will be used to send bounces to.', array('class' => 'help')));

		$email_gateway_manager = new EmailGatewayManager();
		$gateways = $email_gateway_manager->listAll();
		unset($gateways['email_amazon_ses']);
		$gateways = array_merge(array('none'=>array('name'=>'None','handle'=>'none')), $gateways);

		$label = Widget::Label(__('Fallback Gateway'));

		// Get gateway names
		ksort($gateways);

		$fallback = $this->_fallback;

		$options = array();
		foreach($gateways as $handle => $details) {
			$options[] = array($handle, ($fallback == null) && $handle == 'none', $details['name']);
		}
		$select = Widget::Select('settings[email_amazon_ses][fallback]', $options);
		$label->appendChild($select);
		$group->appendChild($label);
		$group->appendChild(new XMLElement('p', 'When the sending limit has been reached, Symphony will use this gateway instead of giving up. If no gateway is selected, the email will not be sent.', array('class' => 'help')));
	
		return $group;
	}
	
	public function send(){
	
		$this->validate();
	
		try{
			// Encode the subject
			$this->_subject	 = EmailHelper::qEncode($this->_subject);

			// Encode the sender name if it's not empty
			$this->_sender_name = empty($this->_sender_name) ? NULL : EmailHelper::qEncode($this->_sender_name);

			// Build the 'From' header field body
			$from = empty($this->_sender_name)
					? $this->_sender_email_address
					: $this->_sender_name . ' <' . $this->_sender_email_address . '>';

			// Encode recipient names (but not any numeric array indexes)
			foreach($this->_recipients as $name => $email){
				$name = is_numeric($name) ? $name : EmailHelper::qEncode($name);
				$recipients[$name] =  $email;
			}
			$recipient_list = EmailHelper::arrayToList($recipients);

			// Build the 'Reply-To' header field body
			if(!empty($this->_reply_to_email_address)){
				if(!empty($this->_reply_to_name)){
					$reply_to = EmailHelper::qEncode($this->_reply_to_name) . ' <'.$this->_reply_to_email_address.'>';
				}
				else{
					$reply_to = $this->_reply_to_email_address;
				}
			}
			if(!empty($reply_to)){
				$this->_header_fields = array_merge(array(
					'Reply-To' => $reply_to,
				),$this->_header_fields);
			}

			if(!empty($this->_return_path)){
				$this->_header_fields = array_merge(array(
					'Return-Path' => $this->_return_path,
				),$this->_header_fields);
			}

			// Build the body text using attachments, html-text and plain-text.
			$this->prepareMessageBody();

			// Build the header fields
			$this->_header_fields = array_merge(Array(
				'Message-ID'   => sprintf('<%s@%s>', md5(uniqid()) , HTTP_HOST),
				'Date'		   => date('r'),
				'From'		   => $from,
				'Subject'	   => $this->_subject,
				'To'		   => $recipient_list,
				'X-Mailer'	   => 'Symphony Email Module',
				'MIME-Version' => '1.0'
			),$this->_header_fields);

			$message = null;
			foreach($this->_header_fields as $header=>$value){
				$message .= $header . ': ' . $value . "\r\n";
			}

			$message .= "\r\n";

			// Because the message can contain \n as a newline, replace all \r\n with \n and explode on \n.
			// The send() function will use the proper line ending (\r\n).
			$data = str_replace("\r\n", "\n", $this->_body);
			$data_arr = explode("\n", $data);
			foreach($data_arr as $line){
				// Escape line if first character is a period (dot). http://tools.ietf.org/html/rfc2821#section-4.5.2
				if(strpos($line, '.') === 0){
					$line = '.' . $line;
				}
				$message .= $line;
			}
			//var_dump($message);
			//die();
			$result = $this->_amazon_ses->send_raw_email(array('Data'=>base64_encode($message)));
			if(!$result->isOK()){
				throw new EmailGatewayException($result->body->Error->Code->to_string() . ' - ' . $result->body->Error->Message->to_string());
			}
		}
		catch(EmailException $e){
			throw $e;
		}
	}

	public function setAwsKey($key){
		$this->_aws_key = $key;
	}

	public function setAwsSecretKey($key){
		$this->_aws_secret_key = $key;
	}

	public function setFallback($fallback){
		$this->_fallback = $fallback;
	}

	public function setReturnPath($path){
		$this->_return_path = $path;
	}

}