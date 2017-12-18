<?php

/**
 * NautaClient
 *
 * Client for horde webmail
 *
 * @author  kumahacker
 * @author  salvipascual
 * @version 2.0
 */

class NautaClient
{
	private $user = null;
	private $pass = null;
	private $client = null;
	private $cookieFile = "";
	private $sessionFile = "";
	private $logoutToken = "";
	private $composeToken = "";

	private $uriGame = [
		0 => [
			'base' => 'http://webmail.nauta.cu/',
			'captcha' => false,
			'login' => 'horde/login.php',
			'loginParams' => "app=&login_post=1&url=&anchor_string=&ie_version=&horde_user={user}&horde_pass={pass}&horde_select_view=mobile&new_lang=en_US",
			'compose' => 'horde/imp/compose-mimp.php?u={token}',
			'composePost' => 'horde/imp/compose-mimp.php',
			'logout' => 'horde/imp/login.php?horde_logout_token={token}'
		],
		1 => [
			'base' => 'https://webmail.nauta.cu/',
			'captcha' => false,
			'login' => 'horde/login.php',
			'loginParams' => "app=&login_post=1&url=&anchor_string=&ie_version=&horde_user={user}&horde_pass={pass}&horde_select_view=mobile&new_lang=en_US",
			'compose' => 'horde/imp/compose-mimp.php?u={token}',
			'composePost' => 'horde/imp/compose-mimp.php',
			'logout' => 'horde/imp/login.php?horde_logout_token={token}'
		],
		2 => [
			'base' => 'https://webmail.nauta.cu/',
			'captcha' => '/securimage/securimage_show.php',
			'login' => 'login.php',
			'loginParams' => "app=&login_post=1&url=&anchor_string=&ie_version=&horde_user={user}&horde_pass={pass}&captcha_code={captcha}&horde_select_view=mobile&new_lang=en_US",
			'compose' => 'imp/minimal.php?page=compose&u={token}',
			"composePost' => 'imp/minimal.php?page=compose&u={token}",
			'logout' => "login.php?horde_logout_token={token}&logout_reason=4"
		]
	];

	private $currentUriGame = 0;

	/**
	 * NautaClient constructor.
	 *
	 * @param string $user
	 * @param string $pass
	 */
	public function __construct($user = null, $pass = null)
	{
		// save global user/pass
		$this->user = $user;
		$this->pass = $pass;

		// save cookie file
		$utils = new Utils();
		$this->sessionFile = $utils->getTempDir() . "nautaclient/{$this->user}.session";
		$this->cookieFile  = $utils->getTempDir() . "nautaclient/{$this->user}.cookie";

		$this->loadSession();

		// init curl
		$this->client = curl_init();
		curl_setopt($this->client, CURLOPT_FOLLOWLOCATION, 1);
		curl_setopt($this->client, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($this->client, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($this->client, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($this->client, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($this->client, CURLOPT_COOKIEJAR, $this->cookieFile);
		curl_setopt($this->client, CURLOPT_COOKIEFILE, $this->cookieFile);

		// add default headers
		$this->setHttpHeaders();

		$this->detectUriGame();
	}

	public function detectUriGame()
	{
		foreach($this->uriGame as $key => $urls)
		{
			try
			{
				//echo "checking {$urls['base']}\n";
				$check= $this->checkLogin();
				$this->currentUriGame = $key;
				return $check;
			} catch (Exception $e)
			{
				continue;
			}
		}
	}
	/**
	 * Return the current set of URL of webmail
	 *
	 * @return mixed
	 */
	public function getUriGame()
	{
		return $this->uriGame[$this->currentUriGame];
	}

	/**
	 * Replace string with key/value pairs
	 *
	 * @param $str
	 * @param $params
	 *
	 * @return bool|mixed
	 */
	public function replaceParams($str, $params)
	{
		if ($str == false) return false;
		foreach($params as $var => $value)
		{
			$str = str_replace('{'.$var.'}', $value, $str);
		}
		return $str;
	}

	/**
	 * Return base URL of webmail
	 *
	 * @return mixed
	 */
	public function getBaseUrl()
	{
		return $this->getUriGame()['base'];
	}

	/**
	 * Return the captcha URL
	 * @return mixed
	 */
	public function getCaptchaUrl()
	{
		$uri = $this->getUriGame()['captcha'];
		if ($uri == false) return false;
		return $this->getBaseUrl().$uri;
	}

	/**
	 * Return base URL for login
	 * @return mixed
	 */
	public function getLoginUrl()
	{
		return $this->getBaseUrl().$this->getUriGame()['login'];
	}

	/**
	 * Return login URI with replaced params
	 *
	 * @param array $params
	 *
	 * @return mixed
	 */
	public function getLoginParams($params = [])
	{
		return $this->replaceParams($this->getUriGame()['loginParams'], $params);
	}

	/**
	 * Return the compose URL
	 *
	 * @param $params
	 *
	 * @return mixed
	 */
	public function getComposeUrl($params = [])
	{
		return $this->getBaseUrl().$this->replaceParams($this->getUriGame()['compose'], $params);
	}

	/**
	 * Return compost post url
	 *
	 * @param $params
	 *
	 * @return string
	 */
	public function getComposePostUrl($params = [])
	{
		return $this->getBaseUrl().$this->replaceParams($this->getUriGame()['composePost'], $params);
	}

	/**
	 * Return logout url
	 *
	 * @param array $params
	 *
	 * @return string
	 */
	public function getLogoutUrl($params = [])
	{
		return $this->getBaseUrl().$this->replaceParams($this->getUriGame()['logout'], $params);
	}

	/**
	 * Set proxy
	 *
	 * @param string $host
	 * @param int $type
	 */
	public function setProxy($host = "localhost:8082", $type = CURLPROXY_SOCKS5)
	{
		// @TODO add a SOCKS proxy
		// https://www.privateinternetaccess.com/pages/buy-vpn/
		// https://www.socks-proxy.net/
		curl_setopt($this->client, CURLOPT_PROXY, $host);
		curl_setopt($this->client, CURLOPT_PROXYTYPE, $type);
	}

	/**
	 * Login webmail
	 *
	 * @param bool $cliOfflineTest
	 *
	 * @return bool
	 */
	public function login($cliOfflineTest = false)
	{
		if ($this->checkLogin()) return true;

		// save the captcha image in the temp folder
		$utils = new Utils();
		$captchaImage = $utils->getTempDir() . "capcha/" . $utils->generateRandomHash() . ".jpg";
		$captchaUrl = $this->getCaptchaUrl();

		$img = false; // maybe, uri game have captcha url and webmail not
		if ($captchaUrl !== false)
		{
			curl_setopt($this->client, CURLOPT_URL, $captchaUrl);
			$img = curl_exec($this->client);
		}

		$captchaText = '';

		if ($img !== false)
		{
			file_put_contents($captchaImage, $img);

			if($cliOfflineTest)
			{
				echo "[INFO] Captcha image store in: $captchaImage \n";
				echo "Please enter captcha test:";
				$cli = fopen("php://stdin", "r");
				$captchaText = fgets($cli);
			}
			else
			{
				// break the captcha
				$captcha = $this->breakCaptcha($captchaImage);
				if($captcha->code == "200")
				{
					$captchaText = $captcha->message;
					rename($captchaImage, $utils->getTempDir() . "capcha/$captchaText.jpg");
				}
				else
				{
					return $utils->createAlert("[NautaClient] Captcha error {$captcha->code} with message {$captcha->message}");
				}
			}
		}

		// send details to login
		curl_setopt($this->client, CURLOPT_URL, $this->getLoginUrl());
		curl_setopt($this->client, CURLOPT_POSTFIELDS, $this->getLoginParams([
			'user' => urlencode($this->user),
            'pass' => urlencode($this->pass),
            'captcha' => urlencode($captchaText)
		]));

		$response = curl_exec($this->client);

		if ($response === false) return false;

		if (stripos($response, 'digo de verificaci') !== false &&
			stripos($response, 'n incorrecto') !== false)
			return false;

		// get tokens
		$this->logoutToken  = php::substring($response, 'horde_logout_token=', '&');
		$this->composeToken = php::substring($response, 'u=', '">New');

		$this->saveSession();
		return true;
	}

	/**
	 * Check keep alive
	 *
	 * @throws Exception
	 * @return bool
	 */
	public function checkLogin()
	{
		$this->loadSession();

		curl_setopt($this->client, CURLOPT_URL, $this->getComposeUrl([
			'token' => $this->composeToken
		]));

		$html = curl_exec($this->client);
		$html = "$html";

		if (stripos($html, 'Message Composition') === false) return false;
		else return true;
	}

	/**
	 * Save session
	 */
	public function saveSession()
	{
		$sessionData = [
			'logoutToken' => $this->logoutToken,
			'composeToken' => $this->composeToken
		];

		file_put_contents($this->sessionFile, serialize($sessionData));
	}

	/**
	 * Load session
	 *
	 * @return mixed
	 */
	public function loadSession()
	{
		if( ! file_exists($this->sessionFile)) $this->saveSession();

		$sessionData = unserialize(file_get_contents($this->sessionFile));

		$this->logoutToken  = $sessionData['logoutToken'];
		$this->composeToken = $sessionData['composeToken'];
		return $sessionData;
	}

	/**
	 * Send an email
	 *
	 * @param String $to
	 * @param String $subject
	 * @param String $body
	 * @param mixed  $attachment
	 * @return mixed
	 */
	public function send($to, $subject, $body, $attachment = false)
	{
		// get the HTML of the compose window

		curl_setopt($this->client, CURLOPT_URL, $this->getComposeUrl([
			'token' => $this->composeToken
		]));

		$html = curl_exec($this->client);

		// get the value of hidden fields from the HTML
		$action = php::substring($html, 'u=', '"');
		$composeCache = php::substring($html, 'composeCache" value="', '"');
		$compose_formToken = php::substring($html, 'compose_formToken" value="', '"');
		$compose_requestToken = php::substring($html, 'compose_requestToken" value="', '"');
		$composeHmac = php::substring($html, 'composeHmac" value="', '"');
		$user = php::substring($html, 'user" value="', '"');

		// create the body of the image
		$data['composeCache'] = $composeCache;
		$data['composeHmac'] = $composeHmac;
		$data['compose_formToken'] = $compose_formToken;
		$data['compose_requestToken'] = $compose_requestToken;
		$data['user'] = $user;
		$data['to'] = $to;
		$data['cc'] = "";
		$data['bcc'] = "";
		$data['subject'] = $subject;
		$data['priority'] = "normal";
		$data['message'] = $body;
		if($attachment) $data['upload_1'] = new CURLFile($attachment);
		$data['a'] = 'Send';

		// set headers
		$this->setHttpHeaders(["Content-Type" => "multipart/form-data"]);

		// send email
		curl_setopt($this->client, CURLOPT_URL, $this->getComposeUrl([
			'token' => $action
		]));

		curl_setopt($this->client, CURLOPT_SAFE_UPLOAD, true);
		curl_setopt($this->client, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($this->client, CURLOPT_POSTFIELDS, $data);
		curl_exec($this->client);

		//var_dump($data);

		// alert if there are errors
		if(curl_errno($this->client)) {
			$utils = new Utils();
			return $utils->createAlert("[NautaClient] Error sending email: " . curl_error($this->client) . " (to: $to, subject: $subject)");
		}

		return true;
	}

	/**
	 * Logout from webmail
	 */
	public function logout()
	{
		if($this->client)
		{
			curl_setopt($this->client, CURLOPT_URL, $this->getLogoutUrl([
				'token' => $this->logoutToken
			]));

			curl_exec($this->client);
			curl_close($this->client);
		}
	}

	/**
	 * Set more http headers
	 *
	 * @param array $headers
	 */
	private function setHttpHeaders($headers = [])
	{
		// set default headers
		$default_headers = [
			"Cache-Control" => "max-age=0",
			"Origin" => $this->getBaseUrl(),
			"User-Agent" => "Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/36.0.1985.125 Safari/537.36",
			"Content-Type" => "application/x-www-form-urlencoded",
			"Connection" => "Keep-Alive",
			"Keep-Alive" => 86400 //secs
		];

		// add custom headers
		$default_headers = array_merge($default_headers, $headers);

		// convert headers array into string
		$headerStr = [];
		foreach($default_headers as $key => $val) $headerStr[] = "$key:$val";

		// add headers to cURL
		curl_setopt($this->client, CURLOPT_HTTPHEADER, $headerStr);
	}

	/**
	 * Breaks an image captcha using human labor. Takes ~15sec to return
	 *
	 * @author salvipascual
	 * @param String $image
	 * @return String
	 */
	private function breakCaptcha($image)
	{
		// get path to root and the key from the configs
		$di = \Phalcon\DI\FactoryDefault::getDefault();
		$wwwroot = $di->get('path')['root'];
		$key = $di->get('config')['anticaptcha']['key'];

		// include captcha libs
		require_once("$wwwroot/lib/anticaptcha-php/anticaptcha.php");
		require_once("$wwwroot/lib/anticaptcha-php/imagetotext.php");

		// set the file
		$api = new ImageToText();
		$api->setVerboseMode(true);
		$api->setKey($key);
		$api->setFile($image);

		// create the task
		if( ! $api->createTask())
		{
			$ret = new stdClass();
			$ret->code = "500";
			$ret->message = "API v2 send failed: " . $api->getErrorMessage();
			return $ret;
		}

		// wait for results
		$taskId = $api->getTaskId();
		if( ! $api->waitForResult())
		{
			$ret = new stdClass();
			$ret->code = "510";
			$ret->message = "Could not solve captcha: " . $api->getErrorMessage();
			return $ret;
		}

		// return the solution
		$ret = new stdClass();
		$ret->code = "200";
		$ret->message = $api->getTaskSolution();
		return $ret;
	}
}
