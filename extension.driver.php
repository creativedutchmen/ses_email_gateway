<?php

class extension_ses_email_gateway extends Extension{

	public function about(){
			return array(
				'name' => 'Amazon SES - email gateway',
				'version' => '0.1',
				'release-date' => '2011-10-22',
				'author' => array(
					'name' => 'Huib Keemink',
					'website' => 'http://www.creativedutchmen.com',
					'email' => 'huib.keemink@creativedutchmen.com'
				)
			);
		}
}