<?php

use Nette\Mail\Message;

class Email
{
	public $id;
	public $from;
	public $to;
	public $subject;
	public $body;
	public $replyId; // id to reply
	public $attachments = array(); // array of paths
	public $images = array(); // array of paths
	public $group = 'apretaste';
	public $status = "new"; // new, sent, bounced
	public $message;
	public $tries = 0;
	public $app = false; // if sending to email or app
	public $created; // date
	public $sent; // date

	/**
	 * Select a provider automatically and send an email
	 * @author salvipascual
	 * @return {"code", "message"}
	 */
	public function send()
	{
		// validate email before sending
		$utils = new Utils();
		$status = $utils->deliveryStatus($this->to);
		if($status != 'ok') {
			$output = new stdClass();
			$output->code = "500";
			$output->message = "Email failed with status: $status";
			return $output;
		}

		// check if the email is from Nauta or Cuba
		$isNauta = substr($this->to, -9) === "@nauta.cu";
		$isCuba = substr($this->to, -3) === ".cu";

		// respond via Amazon to people outside Cuba
		if( ! $isCuba)
		{
			$res = $this->sendEmailViaAmazon();
		}
/*
		// if responding to the Support
		elseif($this->group == 'support')
		{
			$res = $this->sendEmailViaNode();
		}
*/
		// for all Nauta emails (via app or email)
		elseif($isNauta)
		{
			$res = $this->sendEmailViaWebmail();
			if($res->code != "200") $res = $this->sendEmailViaNode();
		}
		// for all other Cuban emails
		else
		{
			$res = $this->sendEmailViaAlias();
		}

		// update the object
		$this->tries++;
		$this->message = str_replace("'", "", $res->message); // single quotes break the SQL
		$this->status = $res->code == "200" ? "sent" : "error";
		if($res->code == "200") $this->sent = date("Y-m-d H:i:s");

		// update the database with the email sent
		$connection = new Connection();
		$sentDate = $res->code == "200" ? "sent=CURRENT_TIMESTAMP," : "";
		$connection->query("UPDATE delivery_received SET $sentDate status='{$this->status}', message='{$this->message}', tries=tries+1 WHERE id='{$this->id}'");

		// save a trace that the email was sent
		if($res->code == "200")
		{
			$subject = str_replace("'", "", $this->subject);
			$attachments = count($this->attachments);
			$images = count($this->images);
			$connection->query("INSERT INTO delivery_sent (mailbox, user, subject, images, attachments, `group`, origin) VALUES ('{$this->from}','{$this->to}','$subject','$images','$attachments','{$this->group}','{$this->id}')");
		}
		// save a trace that the email failed and alert
		else
		{
			$connection->query("INSERT INTO delivery_dropped (email,sender,reason,`code`,description) VALUES ('{$this->to}','{$this->from}','failed','{$res->code}','{$this->message}')");
			$utils->createAlert("Sending failed MESSAGE:{$res->message} | FROM:{$this->from} | TO:{$this->to} | ID:{$this->id}", "ERROR");
		}

		// return {code, message} structure
		return $res;
	}

	/**
	 * Overload of the send () function for backward compatibility
	 *
	 * @author salvipascual
	 * @param String $to, email address of the receiver
	 * @param String $subject, subject of the email
	 * @param String $body, body of the email in HTML
	 * @param Array $images, paths to the images to embeb
	 * @param Array $attachments, paths to the files to attach
	 */
	public function sendEmail($to, $subject, $body, $images=array(), $attachments=array())
	{
		$this->to = $to;
		$this->subject = $subject;
		$this->body = $body;
		$this->images = $images;
		$this->attachments = $attachments;
		return $this->send();
	}

	/**
	 * Sends an email using Amazon SES
	 *
	 * @author salvipascual
	 * @return {"code", "message"}
	 */
	public function sendEmailViaAmazon()
	{
		// get the Amazon params
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$host = "email-smtp.us-east-1.amazonaws.com";
		$user = $di->get('config')['amazon']['access'];
		$pass = $di->get('config')['amazon']['secret'];
		$port = '465';
		$security = 'ssl';

		// select the from part if empty
		if(empty($this->from)) $this->from = 'noreply@apretaste.com';

		// send the email using smtp
		return $this->smtp($host, $user, $pass, $port, $security);
	}

	/**
	 * Sends an email using a Gmail alias via Amazon SES
	 *
	 * @author salvipascual
	 * @return {"code", "message"}
	 */
	public function sendEmailViaAlias()
	{
		// list of aliases
		$aliases = array('apre.taste+nenito','apretaste+ahora','apretaste+alfa','apretaste+aljuarismi','apretaste+angulo','apretaste+arquimedes','apretaste+beta','apretaste+bolzano','apretaste+bool','apretaste+brahmagupta','apretaste+brutal','apretaste+cantor','apretaste+cauchy','apretaste+chi','apretaste+colonia','apretaste+david','apretaste+delta','apretaste+descartes','apretaste+elias','apretaste+epsilon','apretaste+euclides','apretaste+euler','apretaste+fermat','apretaste+fibonacci','apretaste+fourier','apretaste+francisco','apretaste+gamma','apretaste+gauss','apretaste+gonzalo','apretaste+hilbert','apretaste+hipatia','apretaste+homero','apretaste+imperator','apretaste+isaac','apretaste+james','apretaste+jey','apretaste+kappa','apretaste+kepler','apretaste+key','apretaste+lambda','apretaste+leibniz','apretaste+lota','apretaste+luis','apretaste+manuel','apretaste+mu','apretaste+newton','apretaste+nombre','apretaste+nu','apretaste+ohm','apretaste+omega','apretaste+omicron','apretaste+oscar','apretaste+pablo','apretaste+peta','apretaste+phi','apretaste+pi','apretaste+poincare','apretaste+psi','apretaste+quote','apretaste+ramon','apretaste+rho','apretaste+riemann','apretaste+salomon','apretaste+sigma','apretaste+tales','apretaste+theta','apretaste+travis','apretaste+turing','apretaste+upsilon','apretaste+uva','apretaste+vacio','apretaste+viete','apretaste+weierstrass','apretaste+working','apretaste+xenon','apretaste+xi','apretaste+yeah','apretaste+zeta');

		// select an alias based on your personal email
		$percent = 0; $alias = NULL;
		$user = str_replace(array(".","+"), "", explode("@", $this->to)[0]);
		foreach ($aliases as $a) {
			similar_text ($a, $user, $p);
			if($p > $percent) {
				$percent = $p;
				$alias = $a;
			}
		}

		// send the email using Amazon
		$this->from = "$alias@gmail.com";
		return $this->sendEmailViaAmazon();
	}

	/**
	 * Sends an email using Gmail
	 *
	 * @author salvipascual
	 * @return {"code", "message"}
	 */
	public function sendEmailViaGmail()
	{
		// every new day set the daily counter back to zero
		$connection = new Connection();
		$connection->query("UPDATE nodes_output SET daily=0 WHERE DATE(last_sent) < DATE(CURRENT_TIMESTAMP)");

		// get the node of the from address
		if($this->from) {
			$node = $connection->query("SELECT * FROM nodes_output WHERE email = '{$this->from}'");
			if(isset($node[0])) $node = $node[0];
		}
		// if no from is passed, calculate
		else {
			// get the list of available nodes to use
			$nodes = $connection->query("
				SELECT * FROM nodes_output
				WHERE active = '1'
				AND `limit` > daily
				AND `group` LIKE '%{$this->group}%'
				AND (blocked_until IS NULL OR CURRENT_TIMESTAMP >= blocked_until)");

			// get your personal email
			$percent = 0; $node = false;
			$user = str_replace(array(".","+"), "", explode("@", $this->to)[0]);
			foreach ($nodes as $n) {
				$temp = str_replace(array(".","+"), "", explode("@", $n->email)[0]);
				similar_text ($temp, $user, $p);
				if($p > $percent) {
					$percent = $p;
					$node = $n;
				}
			}

			// save the from part in the object
			if($node) $this->from = $node->email;
		}

		// alert the team if no email can be used
		if(empty($node)) {
			$output = new stdClass();
			$output->code = "515";
			$output->message = "No active email to reach {$this->to}";
			return $output;
		}

		// send the email using smtp
		$output = $this->smtp($node->host, $node->user, $node->pass, '', 'ssl');

		// update delivery time if OK
		if($output->code == "200") {
			$connection->query("UPDATE nodes_output SET daily=daily+1, sent=sent+1, last_sent=CURRENT_TIMESTAMP, last_error=NULL WHERE email='{$node->email}'");
		// insert in drops emails and add 24h of waiting time
		}else{
			$lastError = str_replace("'", "", "CODE:{$output->code} | MESSAGE:{$output->message}");
			$blockedUntil = date("Y-m-d H:i:s", strtotime("+24 hours"));
			$connection->query("UPDATE nodes_output SET blocked_until='$blockedUntil', last_error='$lastError' WHERE email='{$node->email}'");
		}

		return $output;
	}

	/**
	 * Sends an email using Mailgun
	 *
	 * @author salvipascual
	 * @return {"code", "message"}
	 */
	public function sendEmailViaMailgun()
	{
		// get the from address
		$utils = new Utils();
		$this->from = $utils->randomSentence(1) . "@datacuba.com";

		// get the Mailgun params
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$pass = $di->get('config')['mailgun']['pass'];

		// send the email using smtp
		$host = "smtp.mailgun.org";
		$user = "postmaster@datacuba.com";
		$output = $this->smtp($host, $user, $pass, '465', 'ssl');
		return $output;
	}

	/**
	 * Sends an email using Postmark
	 *
	 * @author salvipascual
	 * @return {"code", "message"}
	 */
	public function sendEmailViaPostmark()
	{
		// get the from address
		$utils = new Utils();
		$this->from = $utils->randomSentence(1) . "@datacuba.com";

		// send the email using smtp
		$host = "smtp.postmarkapp.com";
		$user = "514aaca0-4e53-4e75-abf2-499419937e1c";
		$output = $this->smtp($host, $user, $user, '587', 'TLS');
		return $output;
	}

	/**
	 * Sends an email using our of our external nodes
	 *
	 * @author salvipascual
	 * @return {"code", "message"}
	 */
	public function sendEmailViaNode()
	{
		// every new day set the daily counter back to zero
		$connection = new Connection();
		$connection->query("UPDATE nodes_output SET daily=0 WHERE DATE(last_sent) < DATE(CURRENT_TIMESTAMP)");

		// get the node of the from address
		if($this->from) {
			$node = $connection->query("SELECT * FROM nodes_output A JOIN nodes B ON A.node = B.key WHERE A.email = '{$this->from}'");
			if(isset($node[0])) $node = $node[0];
		}
		// if no from is passed, calculate
		else {
			// get the list of available nodes to use
			$nodes = $connection->query("
				SELECT * FROM nodes_output A JOIN nodes B
				ON A.node = B.`key`
				WHERE A.active = '1'
				AND `group` LIKE '%{$this->group}%'
				AND A.`limit` > A.daily
				AND (A.blocked_until IS NULL OR CURRENT_TIMESTAMP >= A.blocked_until)");

			// get your personal email
			$percent = 0; $node = false;
			$user = str_replace(array(".","+"), "", explode("@", $this->to)[0]);
			foreach ($nodes as $n) {
				$temp = str_replace(array(".","+"), "", explode("@", $n->email)[0]);
				similar_text ($temp, $user, $p);
				if($p > $percent) {
					$percent = $p;
					$node = $n;
				}
			}

			// save the from part in the object
			if($node) $this->from = $node->email;
		}

		// alert the team if no Node could be used
		$utils = new Utils();
		if(empty($node)) {
			$output = new stdClass();
			$output->code = "515";
			$output->message = "NODE: No active node to email {$this->to}";
			return $output;
		}

		// transform images to base64
		$imagesToUpload = array();
		foreach ($this->images as $image) {
			$item = new stdClass();
			$item->type = file_exists($image) ? mime_content_type($image) : '';
			$item->name = basename($image);
			$item->content = file_exists($image) ? base64_encode(file_get_contents($image)) : '';
			$imagesToUpload[] = $item;
		}

		// transform attachments to base64
		$attachmentsToUpload = array();
		foreach ($this->attachments as $attachment) {
			$item = new stdClass();
			$item->type = file_exists($attachment) ? mime_content_type($attachment) : '';
			$item->name = basename($attachment);
			$item->content = file_exists($attachment) ? base64_encode(file_get_contents($attachment)) : '';
			$attachmentsToUpload[] = $item;
		}

		// create the email array request
		$params['key'] = $node->key;
		$params['from'] = $node->email;
		$params['host'] = $node->host;
		$params['user'] = $node->user;
		$params['pass'] = $node->pass;
		$params['id'] = $this->id;
		$params['messageid'] = $this->replyId;
		$params['to'] = $this->to;
		$params['subject'] = $this->subject;
		$params['body'] = base64_encode($this->body);
		$params['attachments'] = serialize($attachmentsToUpload);
		$params['images'] = serialize($imagesToUpload);

		// contact the Sender to send the email
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, "{$node->ip}/send.php");
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$output = json_decode(curl_exec($ch));
		curl_close ($ch);

		// treat node unreachable error
		if(empty($output)) {
			$output = new stdClass();
			$output->code = "504";
			$output->message = "Error reaching {$node->name} to email {$this->to} with ID {$this->id}";
		}

		// update delivery time if OK
		if($output->code == "200") {
			$connection->query("UPDATE nodes_output SET daily=daily+1, sent=sent+1, last_sent=CURRENT_TIMESTAMP, last_error=NULL WHERE email='{$node->email}'");
		// insert in drops emails and add 24h of waiting time
		}else{
			$lastError = str_replace("'", "", "CODE:{$output->code} | MESSAGE:{$output->message}");
			$blockedUntil = date("Y-m-d H:i:s", strtotime("+24 hours"));
			$connection->query("UPDATE nodes_output SET blocked_until='$blockedUntil', last_error='$lastError' WHERE email='{$node->email}'");
		}
		return $output;
	}

	/**
	 * Sends an email using Nauta webmail
	 *
	 * @author salvipascual
	 * @return {"code", "message"}
	 */
	public function sendEmailViaWebmail()
	{
		// check if we have the nauta pass for the user
		$connection = new Connection();
		$pass = $connection->query("SELECT pass FROM authentication WHERE email = '$this->to' AND appname = 'apretaste'");

		// if the password do not exist in our database, return false
		if(empty($pass)) {
			$output = new stdClass();
			$output->code = "300";
			$output->message = "No password for {$this->to}";
			return $output;
		}

		// decript password
		$utils = new Utils();
		$pass = $utils->decrypt($pass[0]->pass);

		// connect to the client
		$user = explode("@", $this->to)[0];
		$client = new NautaClient($user, $pass);

		// login and send the email
		if ($client->login())
		{
			// prepare the attachment
			$attach = false;
			if($this->attachments){
				$attach = array(
					"contentType" => mime_content_type($this->attachments[0]),
					"content" => file_get_contents($this->attachments[0]),
					"fileName" => basename($this->attachments[0])
				);
			}

			// send email and logout
			$client->sendEmail($this->to, $this->subject, $this->body, $attach);
			$client->logout();

			// create response
			$output = new stdClass();
			$output->code = "200";
			$output->message = "Sent to {$this->to}";
			return $output;
		}
		// if the client cannot login, send via Node
		else
		{
			$output = new stdClass();
			$output->code = "510";
			$output->message = "Error connecting to Webmail";
			return $output;
		}
	}

	/**
	 * Handler to send email using SMTP
	 *
	 * @author salvipascual
	 */
	private function smtp($host, $user, $pass, $port, $security)
	{
		// create mailer
		$mailer = new Nette\Mail\SmtpMailer([
			'host' => $host,
			'username' => $user,
			'password' => $pass,
			'port' => $port,
			'secure' => $security
		]);

		// subject has to be UTF-8
		$this->subject = utf8_encode($this->subject);

		// create message
		$mail = new Message;
		$mail->setFrom($this->from);
		$mail->addTo($this->to);
		$mail->setSubject($this->subject);
		$mail->setHtmlBody($this->body, false);
		$mail->setReturnPath($this->from);
		$mail->setHeader('X-Mailer', '');
		$mail->setHeader('Sender', $this->from);
		$mail->setHeader('In-Reply-To', $this->replyId);
		$mail->setHeader('References', $this->replyId);

		// add images to the template
		if(is_array($this->images)) foreach ($this->images as $image) {
			if (file_exists($image)) {
				$inline = $mail->addEmbeddedFile($image);
				$inline->setHeader("Content-ID", basename($image));
			}
		}

		// add attachments
		if(is_array($this->attachments)) foreach ($this->attachments as $attachment) {
			if (file_exists($attachment)) $mail->addAttachment($attachment);
		}

		// create the response code and message
		$output = new stdClass();
		$output->code = "200";
		$output->message = "Sent to {$this->to}";

		// send email
		try{
			$mailer->send($mail, false);
		}catch (Exception $e){
			$output->code = "500";
			$output->message = $e->getMessage();

			// log error with SMTP
			$utils = new Utils();
			$utils->createAlert($e->getMessage(), "ERROR");
		}

		return $output;
	}

	/**
	 * Configures the contents to be sent as a ZIP attached instead of directly in the body of the message
	 *
	 * @author salvipascual
	 */
	public function setContentAsZipAttachment()
	{
		// get a random name for the file and folder
		$utils = new Utils();
		$zipFile = $utils->getTempDir() . substr(md5(rand() . date('dHhms')), 0, 8) . ".zip";
		$htmlFile = substr(md5(date('dHhms') . rand()), 0, 8) . ".html";

		// create the zip file
		$zip = new ZipArchive;
		$zip->open($zipFile, ZipArchive::CREATE);
		$zip->addFromString($htmlFile, $this->body);

		if (is_array($this->images))
			foreach ($this->images as $i) $zip->addFile($i, basename($i));

		if (is_array($this->attachments))
			foreach ($this->attachments as $a) $zip->addFile($a, basename($a));

		$zip->close();

		// add to the attachments and clean the body
		$this->attachments = array($zipFile);
		$this->body = "";
	}

	/**
	 * Configures the contents to be sent as a PDF attached to the body
	 *
	 * @author salvipascual
	 */
	public function setContentAsPdfAttachment()
	{
		// get path to the root folder
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$wwwRoot = $di->get('path')['root'];

		// create a new path to save the pdf
		$utils = new Utils();
		$tmpFile = "$wwwRoot/temp/" . $utils->generateRandomHash() . ".pdf";

		// download the website as pdf
		$mpdf = new mPDF();
		$mpdf->WriteHTML($this->body);
		$mpdf->Output($tmpFile, 'F');

		// create the body part and attachments
		$this->body = "";
		$this->attachments[] = $tmpFile;
	}

	/**
	 * Randomize the subject and body of an email
	 *
	 * @author salvipascual
	 */
	public function setContentRandom()
	{
		// replace accents in the body by unicode chars
		$utils = new Utils();
		$this->body = $utils->removeTildes($this->body);

		// get the synonyms dictionary
		$connection = new Connection();
		$synonyms = $connection->query("SELECT * FROM synonyms");

		// get the service aliases
		$aliases = $connection->query("SELECT service as word, GROUP_CONCAT(alias) as synonyms FROM service_alias GROUP BY service");
		$synonyms = array_merge($synonyms, $aliases);

		// replace words in the body
		foreach ($synonyms as $key) {
			// get word and synonyms
			$regexp = "/\b{$key->word}\b/ui";
			$values = explode(",", $key->synonyms);
			$replacement = $values[rand(0, count($values)-1)];

			// do not replace the word 2/10 of the time
			if(rand(1, 10) <= 2) continue;

			// replace in the body
			$this->body = preg_replace($regexp, $replacement, $this->body);
		}

		// make the subject random as well
		$this->subject = $utils->randomSentence();

		// randomize the word Apretaste
		$apretaste = substr_replace("Apretaste", ".", rand(1,8), 0); // insert random dot
		$apretaste = substr_replace($apretaste, ".", rand(1,9), 0); // insert random dot
		$apretaste = substr_replace($apretaste, ".", rand(1,10), 0); // insert random dot
		$this->body = str_ireplace("Apretaste", $apretaste, $this->body);
	}
}
