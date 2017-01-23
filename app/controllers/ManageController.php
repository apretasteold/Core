<?php

use Phalcon\Mvc\Controller;

class ManageController extends Controller
{
	private $currentUser = false;
	private $currentPerson = null;

    /**
     * Index for the manage system
     * */
    public function indexAction()
    {
        $connection = new Connection();
        $utils = new Utils();

		// START delivery status widget
		$delivered = $connection->deepQuery("SELECT COUNT(id) as sent FROM delivery_sent WHERE inserted > DATE_SUB(NOW(), INTERVAL 7 DAY)");
		$dropped = $connection->deepQuery("SELECT COUNT(*) AS number, reason FROM delivery_dropped  WHERE inserted > DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY reason");
		$delivery = array("delivered"=>$delivered[0]->sent);
		foreach ($dropped as $r) $delivery[$r->reason] = $r->number;
		$failurePercentage = $delivered[0]->sent > 0 ? ((isset($delivery['hardfail']) ? $delivery['hardfail'] : 0) * 100) / $delivered[0]->sent : 0;
		// END delivery status widget

		// START tasks status widget
		$tasks = $connection->deepQuery("SELECT task, DATEDIFF(CURRENT_DATE, executed) as days, delay, frequency FROM task_status");
		// END tasks status widget

		// START measure the effectiveness of each promoter
		$promoters = $connection->deepQuery("
		SELECT A.email, A.active, A.last_usage, B.total
		FROM promoters A LEFT JOIN (SELECT source, COUNT(source) AS total FROM first_timers WHERE paid=0 GROUP BY source) B
		ON A.email = B.source
		ORDER BY B.total DESC");
		// END measure the effectiveness of each promoter

		$this->view->totalUsers =  $utils->getStat('person.count');
		$this->view->sumCredit = $utils->getStat('person.credit.sum');
		$this->view->utilization = $utils->getStat('utilization.count');
		$this->view->promoters = $promoters;
		$this->view->delivery = $delivery;
		$this->view->deliveryFailurePercentage = number_format($failurePercentage, 2);
		$this->view->tasks = $tasks;
	}

	public function beforeExecuteRoute($dispatcher)
	{
		$utils = new Utils();
		$this->startSession();

		if ($dispatcher->getActionName() !== 'login' && $dispatcher->getActionName() !== 'logout')
		{
			if ($this->getCurrentUser() == false)
			{
				return $dispatcher->forward(array("controller"=> "manage", "action" => "login"));
			}
		}

		$this->view->currentUser = $this->getCurrentPerson();
		$this->view->notifications = $utils->getUnreadNotifications($this->getCurrentUser(), 5);
		$this->view->totalNotifications = $utils->getNumberOfNotifications($this->getCurrentUser());
	}

	private function startSession()
	{
		if ( ! isset($_SESSION)) {
			@session_start();
			@session_name("apretaste.manage");
		}
	}

	/**
	 * Return current user email
	 *
	 * @author kuma
	 * @return mixed
	 */
	private function getCurrentUser()
	{
		$this->startSession();

		if (isset($_SESSION['user']))
			return $_SESSION['user'];

		return false;
	}

	/**
	 * Return current person logged
	 *
	 * @author kuma
	 * @return object
	 */
	private function getCurrentPerson()
	{
		$this->startSession();
		$utils = new Utils();
		$email = $this->getCurrentUser();
		if (is_null($this->currentPerson))
			$this->currentPerson =  $utils->getPerson($email);

		return $this->currentPerson;
	}

	/**
	 * Login in manage
	 *
	 * @author kuma
	 */
	public function loginAction()
	{
		$this->view->loginFail = false;
		if ($this->request->isPost())
		{
			$email = $this->request->getPost('email');

			if ( ! is_null($email) && ! empty($email))
			{
				$pass = sha1($this->request->getPost('password'));

				$sql = "SELECT * FROM manage_users WHERE email = '$email' and password = '$pass';";
				$connection = new Connection();
				$r = $connection->deepQuery($sql);

				if (is_array($r))
					if (isset($r[0]))
						if ($r[0]->email == $email)
						{
							$this->startSession();
							$_SESSION['user'] = $email;
							return $this->dispatcher->forward(array("controller"=> "manage", "action" => "index"));
						}
					$this->view->loginFail = true;
			}
		}
		$this->view->setLayout('login');
	}

	public function logoutAction()
	{
		unset($_SESSION['user']);
		return $this->dispatcher->forward(array("controller"=> "manage", "action" => "index"));
	}

	/**
	 * Audience
	 * */
	public function audienceAction()
	{
		$connection = new Connection();

		// START weekly visitors
		$query =
			"SELECT A.received, B.sent, A.inserted
			FROM (SELECT count(*) as received, DATE(request_time) as inserted FROM utilization GROUP BY DATE(request_time) ORDER BY inserted DESC LIMIT 7) A
			LEFT JOIN (SELECT count(*) as sent, DATE(inserted) as inserted FROM delivery_sent GROUP BY DATE(inserted) ORDER BY inserted DESC LIMIT 7) B
			ON A.inserted = B.inserted";
		$visits = $connection->deepQuery($query);
		$visitorsWeecly = array();
		foreach($visits as $visit)
		{
			if( ! $visit->received) $visit->received = 0;
			if( ! $visit->sent) $visit->sent = 0;
			$visitorsWeecly[] = ["date"=>date("D jS", strtotime($visit->inserted)), "received"=>$visit->received, "sent"=>$visit->sent];
		}
		$visitorsWeecly = array_reverse($visitorsWeecly);
		// END weekly visitors


		// START monthly visitors
		$query =
			"SELECT A.received, B.sent, A.inserted
			FROM (SELECT count(*) as received, DATE_FORMAT(request_time,'%Y-%m') as inserted FROM utilization GROUP BY DATE_FORMAT(request_time,'%Y-%m') ORDER BY inserted DESC LIMIT 30) A
			LEFT JOIN (SELECT count(*) as sent, DATE_FORMAT(inserted,'%Y-%m') as inserted FROM delivery_sent GROUP BY DATE_FORMAT(inserted,'%Y-%m') ORDER BY inserted DESC LIMIT 30) B
			ON A.inserted = B.inserted";
		$visits = $connection->deepQuery($query);
		$visitorsMonthly = array();
		foreach($visits as $visit)
		{
			if( ! $visit->received) $visit->received = 0;
			if( ! $visit->sent) $visit->sent = 0;
			$visitorsMonthly[] = ["date"=>date("M Y", strtotime($visit->inserted)), "received"=>$visit->received, "sent"=>$visit->sent];
		}
		$visitorsMonthly = array_reverse($visitorsMonthly);
		// End monthly Visitors


		// START monthly unique visitors
		$query =
			"SELECT A.unique_visitors, B.new_visitors, A.inserted
			FROM (SELECT COUNT(DISTINCT requestor) as unique_visitors, DATE_FORMAT(request_time,'%Y-%m') as inserted FROM utilization GROUP BY DATE_FORMAT(request_time,'%Y-%m') ORDER BY inserted DESC LIMIT 30) A
			JOIN (SELECT COUNT(DISTINCT email) as new_visitors, DATE_FORMAT(insertion_date,'%Y-%m') as inserted FROM person GROUP BY DATE_FORMAT(insertion_date,'%Y-%m') ORDER BY inserted DESC LIMIT 30) B
			ON A.inserted = B.inserted";
		$visits = $connection->deepQuery($query);
		$newUsers = array();
		foreach($visits as $visit)
		{
			$newUsers[] = ["date"=>date("M Y", strtotime($visit->inserted)), "unique_visitors"=>$visit->unique_visitors, "new_visitors"=>$visit->new_visitors];
		}
		$newUsers = array_reverse($newUsers);
		// END monthly unique visitors


		// START current number of Users
		$queryCurrentNoUsers = "SELECT COUNT(email) as CountUsers FROM person WHERE active=1";
		$currentNoUsers = $connection->deepQuery($queryCurrentNoUsers);
		// END Current number of Users


		// START monthly services usage
		$query = "SELECT service, COUNT(service) as times_used FROM utilization WHERE request_time > DATE_SUB(NOW(), INTERVAL 1 MONTH) GROUP BY service DESC";
		$visits = $connection->deepQuery($query);
		$servicesUsageMonthly = array();
		foreach($visits as $visit)
		{
			$servicesUsageMonthly[] = ["service"=>$visit->service, "usage"=>$visit->times_used];
		}
		// END monthly services usage


		// START active domains last 4 months
		$query =
			"SELECT domain, count(domain) as times_used
			FROM utilization
			WHERE PERIOD_DIFF(DATE_FORMAT(NOW(), '%Y%m'), DATE_FORMAT(request_time, '%Y%m')) < 4
			GROUP BY domain
			ORDER BY times_used DESC";
		$visits = $connection->deepQuery($query);
		$activeDomainsMonthly = array();
		foreach($visits as $visit)
		{
			$activeDomainsMonthly[] = ["domain"=>$visit->domain, "usage"=>$visit->times_used];
		}
		// END active domains last 4 months


		// START bounce rate
		$query = "SELECT B.* FROM (";
		for($i=0; $i<12; $i++)
		{
			$date = date("Y-m", strtotime("-$i months"));
			$query .= "SELECT COUNT(A.b) as bounced, '$date' as date FROM (SELECT COUNT(requestor) as b FROM utilization WHERE DATE_FORMAT(request_time,'%Y-%m') = '$date' GROUP BY requestor HAVING b = 1) A";
			if($i!=11) $query .= " UNION ";
		}
		$query .= ") B WHERE bounced > 0 ORDER BY date";
		$visits = $connection->deepQuery($query);
		$bounceRateMonthly = array();
		foreach($visits as $visit)
		{
			$bounceRateMonthly[] = ["date"=>$visit->date, "bounced"=>$visit->bounced];
		}
		//End bounce rate


		// START updated profiles
		$query =
		"SELECT count(email) as num_profiles, DATE_FORMAT(last_update_date,'%Y-%m') as last_update
		FROM person
		WHERE last_update_date IS NOT NULL
		GROUP BY last_update
		ORDER BY last_update DESC
		LIMIT 30";
		$visits = $connection->deepQuery($query);
		$updatedProfilesMonthly = array();
		foreach($visits as $visit)
		{
			$updatedProfilesMonthly[] = ["date"=>date("M Y", strtotime($visit->last_update)), "profiles"=>$visit->num_profiles];
		}
		$updatedProfilesMonthly = array_reverse($updatedProfilesMonthly);
		// END updated profiles


		// START current number of running ads
		$queryRunningAds = "SELECT COUNT(active) AS CountAds FROM ads WHERE active=1";
		$runningAds = $connection->deepQuery($queryRunningAds);
		// END current number of running ads


		// send variables to the view
		$this->view->title = "Audience";
		$this->view->visitorsWeecly = $visitorsWeecly;
		$this->view->visitorsMonthly = $visitorsMonthly;
		$this->view->newUsers = $newUsers;
		$this->view->currentNumberOfActiveUsers = $currentNoUsers[0]->CountUsers;
		$this->view->servicesUsageMonthly = $servicesUsageMonthly;
		$this->view->activeDomainsMonthly = $activeDomainsMonthly;
		$this->view->bounceRateMonthly = $bounceRateMonthly;
		$this->view->updatedProfilesMonthly = $updatedProfilesMonthly;
		$this->view->currentNumberOfRunningaAds = $runningAds[0]->CountAds;
	}

	/**
	 * Profile
	 * */
	public function profileAction()
	{
		$connection = new Connection();

		// Users with profile vs users without profile
		//Users with profiles
		$queryUsersWithProfile = "SELECT COUNT(email) AS PersonWithProfiles FROM person WHERE updated_by_user = 1";
		$usersWithProfile = $connection->deepQuery($queryUsersWithProfile);

		//Users without profiles
		$queryUsersWithOutProfile = "SELECT COUNT(email) AS PersonWithOutProfiles FROM person WHERE updated_by_user = 0";
		$usersWithOutProfile = $connection->deepQuery($queryUsersWithOutProfile);
		//End Users with profile vs users without profile

		// Profile completion
		$queryProfileData = "
			SELECT 'Name' AS Caption, COUNT(first_name) AS Number FROM person WHERE updated_by_user IS NOT NULL AND (first_name IS NOT NULL OR last_name IS NOT NULL OR middle_name IS NOT NULL OR mother_name IS NOT NULL)
			UNION
			SELECT 'DOB' AS Caption, COUNT(date_of_birth) AS Number FROM person WHERE updated_by_user IS NOT NULL AND date_of_birth IS NOT NULL
			UNION
			SELECT 'Gender' AS Caption, COUNT(gender) AS Number FROM person WHERE updated_by_user IS NOT NULL AND gender IS NOT NULL
			UNION
			SELECT 'Phone' AS Caption, COUNT(phone) AS Number FROM person WHERE updated_by_user IS NOT NULL AND phone IS NOT NULL
			UNION
			SELECT 'Eyes' AS Caption, COUNT(eyes) AS Number FROM person WHERE updated_by_user IS NOT NULL AND eyes IS NOT NULL
			UNION
			SELECT 'Skin' AS Caption, COUNT(skin) AS Number FROM person WHERE updated_by_user IS NOT NULL AND skin IS NOT NULL
			UNION
			SELECT 'Body' AS Caption, COUNT(body_type) AS Number FROM person
			UNION
			SELECT 'Picture' AS Picture, COUNT(picture) AS Number FROM person WHERE picture=1";
		$profileData = $connection->deepQuery($queryProfileData);

		foreach($profileData as $profilesList)
		{
			$percent = ($profilesList->Number * 100)/$usersWithProfile[0]->PersonWithProfiles;
			$percentFormated = number_format($percent, 2);
			$profilesData[] = ["caption"=>$profilesList->Caption, "number"=>$profilesList->Number, "percent"=>$percentFormated];
		}
		//End Profile completion

		// Numbers of profiles per province
		// https://en.wikipedia.org/wiki/ISO_3166-2:CU
		$queryPrefilesPerPravince =
		"SELECT c.ProvCount,
			CASE c.mnth
				WHEN 'PINAR_DEL_RIO' THEN 'Pinar del Río'
				WHEN 'LA_HABANA' THEN 'Ciudad de La Habana'
				WHEN 'ARTEMISA' THEN 'CU-X01'
				WHEN 'MAYABEQUE' THEN 'CU-X02'
				WHEN 'MATANZAS' THEN 'Matanzas'
				WHEN 'VILLA_CLARA' THEN 'Villa Clara'
				WHEN 'CIENFUEGOS' THEN 'Cienfuegos'
				WHEN 'SANCTI_SPIRITUS' THEN 'Sancti Spíritus'
				WHEN 'CIEGO_DE_AVILA' THEN 'Ciego de Ávila'
				WHEN 'CAMAGUEY' THEN 'Camagüey'
				WHEN 'LAS_TUNAS' THEN 'Las Tunas'
				WHEN 'HOLGUIN' THEN 'Holguín'
				WHEN 'GRANMA' THEN 'Granma'
				WHEN 'SANTIAGO_DE_CUBA' THEN 'Santiago de Cuba'
				WHEN 'GUANTANAMO' THEN 'Guantánamo'
				WHEN 'ISLA_DE_LA_JUVENTUD' THEN 'Isla de la Juventud'
			END as NewProv
		FROM (SELECT count(b.province) as ProvCount, a.mnth
				FROM(
					SELECT 'PINAR_DEL_RIO' mnth
					UNION ALL
					SELECT 'LA_HABANA' mnth
					UNION ALL
					SELECT 'ARTEMISA' mnth
					UNION ALL
					SELECT 'MAYABEQUE' mnth
					UNION ALL
					SELECT 'MATANZAS' mnth
					UNION ALL
					SELECT 'VILLA_CLARA' mnth
					UNION ALL
					SELECT 'CIENFUEGOS' mnth
					UNION ALL
					SELECT 'SANCTI_SPIRITUS' mnth
					UNION ALL
					SELECT 'CIEGO_DE_AVILA' mnth
					UNION ALL
					SELECT 'CAMAGUEY' mnth
					UNION ALL
					SELECT 'LAS_TUNAS' mnth
					UNION ALL
					SELECT 'HOLGUIN' mnth
					UNION ALL
					SELECT 'GRANMA' mnth
					UNION ALL
					SELECT 'SANTIAGO_DE_CUBA' mnth
					UNION ALL
					SELECT 'GUANTANAMO' mnth
					UNION ALL
					SELECT 'ISLA_DE_LA_JUVENTUD' mnth
				) a
				LEFT JOIN person b
					ON BINARY a.mnth = BINARY b.province AND
						b.province IS not NULL AND
						b.province IN ('PINAR_DEL_RIO', 'LA_HABANA', 'ARTEMISA', 'MAYABEQUE', 'MATANZAS', 'VILLA_CLARA', 'CIENFUEGOS', 'SANCTI_SPIRITUS', 'CIEGO_DE_AVILA', 'CAMAGUEY', 'LAS_TUNAS', 'HOLGUIN', 'GRANMA', 'SANTIAGO_DE_CUBA', 'GUANTANAMO', 'ISLA_DE_LA_JUVENTUD')
			GROUP  BY b.province) as c";
		$prefilesPerPravinceList = $connection->deepQuery($queryPrefilesPerPravince);

		foreach($prefilesPerPravinceList as $profilesList)
		{
			if($profilesList->ProvCount != 0)
				$profilesPerProvince[] = ["region"=>$profilesList->NewProv, "profiles"=>$profilesList->ProvCount];
			else
				$profilesPerProvince[] = ["region"=>$profilesList->NewProv, "profiles"=>0];
		}
		// numbers of profiles per province

		// send variables to the view
		$this->view->title = "Profile";
		$this->view->usersWithProfile = $usersWithProfile[0]->PersonWithProfiles;
		$this->view->usersWithoutProfile = $usersWithOutProfile[0]->PersonWithOutProfiles;
		$this->view->profilesData = $profilesData;
		$this->view->profilesPerProvince = $profilesPerProvince;
	}

	/**
	 * Profile search
	 * */
	public function profilesearchAction()
	{
		$email = $this->request->get("email");
		if($email)
		{
			// get the email passed by post
			$connection = new Connection();

			// search for the user
			$querryProfileSearch = "SELECT active, first_name, middle_name, last_name, mother_name, date_of_birth, TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) AS Age, gender, phone, eyes, skin, body_type, hair, city, province, about_me, credit, picture, email FROM person WHERE email = '$email'";
			$profileSearch = $connection->deepQuery($querryProfileSearch);

			if($profileSearch)
			{
				//If the picture exist return the email, if not, return 0
				if($profileSearch[0]->picture == 1)
				{
					$this->view->picture = true;
					$this->view->email = $profileSearch[0]->email;
				}

				$this->view->email = $email;
				$this->view->active = $profileSearch[0]->active;
				$this->view->firstName = $profileSearch[0]->first_name;
				$this->view->middleName = $profileSearch[0]->middle_name;
				$this->view->lastName = $profileSearch[0]->last_name;
				$this->view->motherName = $profileSearch[0]->mother_name;
				$this->view->dob = $profileSearch[0]->date_of_birth;
				$this->view->age = $profileSearch[0]->Age;
				$this->view->gender = $profileSearch[0]->gender;
				$this->view->phone = $profileSearch[0]->phone;
				$this->view->eyes = $profileSearch[0]->eyes;
				$this->view->skin = $profileSearch[0]->skin;
				$this->view->body = $profileSearch[0]->body_type;
				$this->view->hair = $profileSearch[0]->hair;
				$this->view->city = $profileSearch[0]->city;
				$this->view->province = $profileSearch[0]->province;
				$this->view->aboutMe = $profileSearch[0]->about_me;
				$this->view->credit = $profileSearch[0]->credit;
			}
			else
			{
				$this->view->profileNotFound = "Profile not found for user <b>$email</b>";
			}
		}

		$this->view->title = "Search for a profile";
	}

	/**
	 * Exclude an user from Apretaste and make promotional emails stop
	 *
	 * @author salvipascual
	 * @param String $email
	 */
	public function excludeAction()
	{
		$email = $this->request->get("email");

		// unsubscribe from the emails
		$utils = new Utils();
		$utils->unsubscribeFromEmailList($email);

		// mark the user inactive in the database
		$connection = new Connection();
		$connection->deepQuery("UPDATE person SET active=0 WHERE email='$email'");

		// email the user user letting him know
		$email = new Email();
		$email->sendEmail($email, "Siento ver que se nos va", "Hola. A peticion suya le he excluido de Apretaste. Ahora no debera recibir mas nuestra correspondencia. Si desea volver a usar Apretaste en un futuro, acceda a nuestro sistema nuevamente y sera automaticamente incluido. Disculpa si le hemos causamos alguna molestia, y gracias por usar Apretaste, siempre es bienvenido nuevamente.");

		// redirect back
		header("Location: profilesearch?email=$email");
	}

	/**
	 * List of raffles
	 * */
	public function rafflesAction()
	{
		$connection = new Connection();

		// List of raffles
		$query =
			"SELECT A.item_desc, A.start_date, A.end_date, A.winner_1, A.winner_2, A.winner_3, count(B.raffle_id) as tickets
			FROM raffle A
			LEFT JOIN ticket B
			ON A.raffle_id = B.raffle_id
			GROUP BY B.raffle_id
			ORDER BY end_date DESC";
		$visits = $connection->deepQuery($query);
		$raffleListCollection = array();
		foreach($visits as $visit)
		{
			$raffleListCollection[] = ["itemDesc"=>$visit->item_desc, "startDay"=>$visit->start_date, "finishDay"=>$visit->end_date, "winner1"=>$visit->winner_1, "winner2"=>$visit->winner_2, "winner3"=>$visit->winner_3, "tickets"=>$visit->tickets];
		}

		// get the current number of tickets
		$raffleCurrentTickets = $connection->deepQuery("SELECT count(ticket_id) as tickets FROM ticket WHERE raffle_id IS NULL");
		if($raffleListCollection[0]['tickets'] == 0) $raffleListCollection[0]['tickets'] = $raffleCurrentTickets[0]->tickets;

		// send values to the template
		$this->view->title = "List of raffles";
		$this->view->raffleListData = $raffleListCollection;
	}

	/**
	 * create raffle
	 * */
	public function createraffleAction()
	{
		if($this->request->isPost())
		{
			$description = $this->request->getPost("description");
			$startDate = $this->request->getPost("startDate") . " 00:00:00";
			$endDate = $this->request->getPost("endDate") . " 23:59:59";

			//Insert the Raffle
			$connection = new Connection();
			$queryInsertRaffle = "INSERT INTO raffle (item_desc, start_date, end_date) VALUES ('$description','$startDate','$endDate')";
			$insertRaffle = $connection->deepQuery($queryInsertRaffle);

			if($insertRaffle)
			{
				// get the last inserted raffle's id
				$queryGetRaffleID = "SELECT raffle_id FROM raffle WHERE item_desc = '$description' ORDER BY raffle_id DESC LIMIT 1";
				$getRaffleID = $connection->deepQuery($queryGetRaffleID);

				// get the picture name and path
				$wwwroot = $this->di->get('path')['root'];
				$fileName = md5($getRaffleID[0]->raffle_id);
				$picPath = "$wwwroot/public/raffle/$fileName.jpg";
				move_uploaded_file($_FILES["picture"]["tmp_name"], $picPath);

				// optimize the image
				$utils = new Utils();
				$utils->optimizeImage($picPath, 400);

				$this->view->raffleMessage = "Raffle inserted successfully";
			}
			else
			{
				$this->view->raffleError = "We had an error creating the raffle, please try again";
			}
		}

		$this->view->title = "Create raffle";
	}

	/**
	 * List of services
	 * */
	public function servicesAction()
	{
		$connection = new Connection();

		$queryServices =
			"SELECT A.name, A.description, A.creator_email, A.category, A.insertion_date, A.listed, B.times_used, B.avg_latency
			FROM service A
			LEFT JOIN (SELECT service, COUNT(service) as times_used, AVG(response_time) as avg_latency FROM utilization WHERE request_time > DATE_SUB(NOW(), INTERVAL 1 MONTH) GROUP BY service) B
			ON A.name = B.service
			ORDER BY B.times_used DESC";
		$services = $connection->deepQuery($queryServices);

		$this->view->title = "List of services (".count($services).")";
		$this->view->services = $services;
	}

	/**
	 * List of ads
	 * */
	public function adsAction()
	{
		$connection = new Connection();

		$queryAdsActive = "SELECT id, owner, time_inserted, title, clicks, impresions, paid_date, expiration_date FROM ads";
		$ads = $connection->deepQuery($queryAdsActive);

		$this->view->title = "List of ads";
		$this->view->ads = $ads;
	}

	/**
	 * Manage the ads
	 * */
	public function createadAction()
	{
		// handle the submit if an ad is posted
		// @TODO: move post process to other action?
		if($this->request->isPost())
		{
			// getting post data
			$adsOwner = $this->request->getPost("owner");
			$adsTittle = $this->request->getPost("title");
			$adsDesc = $this->request->getPost("description");
			$adsPrice = $this->request->getPost('price');
			$today = date("Y-m-d H:i:s"); // date the ad was posted
			$expirationDay = date("Y-m-d H:i:s", strtotime("+1 months"));

			// insert the ad
			$connection = new Connection();
			$queryInsertAds = "INSERT INTO ads (owner, title, description, expiration_date, paid_date, price)
							   VALUES ('$adsOwner','$adsTittle','$adsDesc', '$expirationDay', '$today', '$adsPrice')";
			$insertAd = $connection->deepQuery($queryInsertAds);

			if($insertAd)
			{
				if($_FILES["picture"]['error'] === 0)
				{
					$queryGetAdsID = "SELECT id FROM ads WHERE owner='$adsOwner' ORDER BY id DESC LIMIT 1";
					$getAdID = $connection->deepQuery($queryGetAdsID);

					// save the image
					$fileName = md5($getAdID[0]->id); //Generate the picture name
					$wwwroot = $this->di->get('path')['root'];
					$picPath = "$wwwroot/public/ads/$fileName.jpg";
					move_uploaded_file($_FILES["picture"]["tmp_name"], $picPath);

					// optimize the image
					$utils = new Utils();
					$utils->optimizeImage($picPath, 728, 90);
				}

				// confirm by email that the ad was inserted
				$email = new Email();
				$email->sendEmail($adsOwner, "Your ad is now running on Apretaste", "<h1>Your ad is running</h1><p>Your ad <b>$adsTittle</b> was set to run $today.</p><p>Thanks for advertising using Apretaste.</p>");

				$this->view->adMesssage = "Your ad was posted successfully";
			}
			else
			{
				$this->view->adError = "We had an error posting your ad, please try again";
			}
		}

		$this->view->title = "Create ad";
	}

	/**
	 * Jumper
	 * */
	public function jumperAction()
	{
		$connection = new Connection();

		$queryJumper = "SELECT email, last_usage, sent_count, blocked_domains, status FROM jumper ORDER BY last_usage DESC";
		$jumperData = $connection->deepQuery($queryJumper);

		$this->view->title = "Jumper";
		$this->view->jumperData = $jumperData;
	}

	/**
	 * Deploy a new service or update an old one
	 * */
	public function deployAction()
	{
		$this->view->title = "Deploy a service";

		// handle the submit if a service is posted
		if($this->request->isPost())
		{
			// check the file is a valid zip
			$fileNameArray = explode(".", $_FILES["service"]["name"]);
			$extensionIsZip = strtolower(end($fileNameArray)) == "zip";
			if ( ! $extensionIsZip)
			{
				$this->view->deployingError = "The file is not a valid zip";
				return;
			}

			// check the service zip size is less than 1MB
			if ($_FILES["service"]["size"] > 1048576)
			{
				$this->view->deployingError = "The file is too big. Our limit is 1 MB";
				return;
			}

			// check for errors
			if ($_FILES["service"]["error"] > 0)
			{
				$this->view->deployingError = "Unknow errors uploading your service. Please try again";
				return;
			}

			// include and initialice Deploy class
			$deploy = new Deploy();

			// get the zip name and path
			$utils = new Utils();
			$wwwroot = $this->di->get('path')['root'];
			$zipPath = "$wwwroot/temp/" . $utils->generateRandomHash() . ".zip";
			$zipName = basename($zipPath);

			// save file
			if (isset($_FILES["service"]["name"])) $zipName = $_FILES["service"]["name"];
			move_uploaded_file($_FILES["service"]["tmp_name"], $zipPath);
			chmod($zipPath, 0777);

			// check if the file was moved correctly
			if ( ! file_exists($zipPath))
			{
				$this->view->deployingError = "There was a problem uploading the file";
				return;
			}

			// deploy the service
			try
			{
				$deployResults = $deploy->deployServiceFromZip($zipPath, $zipName);
			}
			catch (Exception $e)
			{
				$error = preg_replace("/\r|\n/", "", $e->getMessage());
				$this->view->deployingError = $error;
				return;
			}

			// send email to the user with the deploy key
			$today = date("Y-m-d H:i:s");
			$serviceName = $deployResults["serviceName"];
			$creatorEmail = $deployResults["creatorEmail"];
			$email = new Email();
			$email->sendEmail($creatorEmail, "Your service $serviceName was deployed", "<h1>Service deployed</h1><p>Your service $serviceName was deployed on $today.</p>");

			// redirect to the upload page with success message
			$this->view->deployingMesssage = "Service <b>$serviceName</b> deployed successfully.";
		}
	}

	/**
	 * Show the dropped emails for the last 7 days
	 * */
	public function droppedAction()
	{
		$connection = new Connection();

		// create the sql for the graph
		$sql = "";
		foreach (range(0,7) as $day)
		{
			$sql .= "
			SELECT DATE(inserted) as moment,
				SUM(case when reason = 'hard-bounce' then 1 else 0 end) as hardbounce,
				SUM(case when reason = 'soft-bounce' then 1 else 0 end) as softbounce,
				SUM(case when reason = 'spam' then 1 else 0 end) as spam,
				SUM(case when reason = 'no-reply' then 1 else 0 end) as noreply,
				SUM(case when reason = 'loop' then 1 else 0 end) as `loop`,
				SUM(case when reason = 'failure' then 1 else 0 end) as failure,
				SUM(case when reason = 'temporal' then 1 else 0 end) as temporal,
				SUM(case when reason = 'unknown' then 1 else 0 end) as unknown,
				SUM(case when reason = 'hardfail' then 1 else 0 end) as hardfail
			FROM delivery_dropped
			WHERE DATE(inserted) = DATE(DATE_SUB(NOW(), INTERVAL $day DAY))
			GROUP BY moment";
			if($day < 7) $sql .= " UNION ";
		}

		// get the delivery status per code
		$dropped = $connection->deepQuery($sql);

		// create the array for the view
		$emailsDroppedChart = array();
		foreach($dropped as $d)
		{
			$emailsDroppedChart[] = [
				"date" => date("D j", strtotime($d->moment)),
				"hardbounce" => $d->hardbounce,
				"softbounce" => $d->softbounce,
				"spam" => $d->spam,
				"noreply" => $d->noreply,
				"loop" => $d->loop,
				"failure" => $d->failure,
				"temporal" => $d->temporal,
				"unknown" => $d->unknown,
				"hardfail" => $d->hardfail
			];
		}

		// get last 7 days of dropped emails
		$sql = "SELECT * FROM delivery_dropped WHERE inserted > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY inserted DESC";
		$dropped = $connection->deepQuery($sql);

		// get last 7 days of emails received
		$sql = "SELECT COUNT(id) as total FROM delivery_sent WHERE inserted > DATE_SUB(NOW(), INTERVAL 7 DAY)";
		$sent = $connection->deepQuery($sql)[0]->total;

		$this->view->title = "Dropped emails (Last 7 days)";
		$this->view->emailsDroppedChart = array_reverse($emailsDroppedChart);
		$this->view->droppedEmails = $dropped;
		$this->view->sentEmails = $sent;
		$this->view->failurePercentage = (count($dropped)*100)/$sent;
	}

	/**
	 * Remove dropped action async
	 *
	 * @author salvipascual
	 */
	public function removeDroppedAction()
	{
		$userEmail = $this->request->get('email');
		$removed = "no";

		if ($userEmail)
		{
			// delete the block
			$connection = new Connection();
			$connection->deepQuery("DELETE FROM delivery_dropped WHERE email='$userEmail'");

			// email the user user letting him know
			$email = new Email();
			$email->sendEmail($userEmail, "Arregle un problema con su email", "Hola. Trabajo en Apretaste y me he percatado que por error su direccion de email estaba bloqueada en nuestro sistema. He corregido este error y ahroa deberia poder usar Apretaste sin problemas. Siento mucho este inconveniente, y muchas gracias por usar Apretaste!. Un saludo.");

			$removed = "ok";
		}

		echo $removed;
		$this->view->disable();
	}

	/**
	 * Delivery status
	 *
	 * @author kuma
	 */
	public function deliveryAction()
	{
		$connection = new Connection();
		$userEmail = $this->request->get('email');
		$delivery = array();

		if ( ! empty("$userEmail"))
		{
			$delivery = $connection->deepQuery("
				SELECT * FROM (
					(SELECT 'received' as type,
							user,
							id,
							inserted,
							subject,
							attachments_count as attachments,
							mailbox
					FROM delivery_received
					WHERE user = '$userEmail'
					LIMIT 50)
					UNION
					(SELECT 'sent' as type,
							user,
							id,
							inserted,
							subject,
							attachments,
							mailbox
					FROM delivery_sent
					WHERE user = '$userEmail'
					LIMIT 50)
				) AS subq1
				ORDER BY inserted, type desc;");

			$r = $connection->deepQuery("SELECT * FROM delivery_dropped WHERE email = '$userEmail';");
		}

		$this->view->delivery = $delivery;
		$this->view->title = 'Delivery';
		$this->view->userEmail = $userEmail;
	}

	/**
	 * Show the error log
	 * */
	public function errorsAction()
	{
		// get the error logs file
		$wwwroot = $this->di->get('path')['root'];
		$logfile = "$wwwroot/logs/error.log";

		// tail the log file
		$numlines = "50";
		$cmd = "tail -$numlines '$logfile'";
		$errors = explode('<br />', nl2br(shell_exec($cmd)));

		// format output to look better
		$output = array();
		foreach ($errors as $err)
		{
			if(strlen($err) < 5) continue;
			$line = htmlentities($err);
			$line = "<b>".substr_replace($line,"]</b>",strpos($line, "]"),1);
			$output[] = $line;
		}

		// reverse to show latest first
		$output = array_reverse($output);

		$this->view->title = "Lastest $numlines errors";
		$this->view->output = $output;
	}

	/**
	 * List of surveys
	 *
	 * @author kuma
	 */
	public function surveysAction()
	{
		$connection = new Connection();
		$this->view->message = false;
		$this->view->message_type = 'success';
		$option = $this->request->get('option');
		$sql = false;

		if($this->request->isPost())
		{
			switch ($option){
				case 'addSurvey':
					$customer = $this->request->getPost("surveyCustomer");
					$title = $this->request->getPost("surveyTitle");
					$deadline = $this->request->getPost("surveyDeadline");
					$sql = "INSERT INTO _survey (customer, title, deadline) VALUES ('$customer', '$title', '$deadline'); ";
					$this->view->message = 'The survey was inserted successfull';

					break;
				case 'setSurvey':
					$customer = $this->request->getPost("surveyCustomer");
					$title = $this->request->getPost("surveyTitle");
					$deadline = $this->request->getPost("surveyDeadline");
					$id = $this->request->get('id');
					$sql = "UPDATE _survey SET customer = '$customer', title = '$title', deadline = '$deadline' WHERE id = '$id'; ";
					$this->view->message = 'The survey was updated successfull';
					break;
			}
		}

		switch ($option){
			case "delSurvey":
				$id = $this->request->get('id');
				$sql = "START TRANSACTION;
						DELETE FROM _survey_answer WHERE question = (SELECT id FROM _survey_question WHERE _survey_question.survey = '$id');
						DELETE FROM _survey_question WHERE survey = '$id';
						DELETE FROM _survey WHERE id = '$id';
						COMMIT;";
				$this->view->message = 'The survey #'.$delete.' was deleted successfull';
				break;

			case "disable":
				$id = $this->request->get('id');
				$sql = "UPDATE _survey SET active = 0 WHERE id ='$id';";
				break;
			case "enable":
				$id = $this->request->get('id');
				$sql = "UPDATE _survey SET active = 1 WHERE id ='$id';";
				break;
		}

		if ($sql!==false) $connection->deepQuery($sql);

		$querySurveys = "SELECT * FROM _survey ORDER BY ID";

		$surveys = $connection->deepQuery($querySurveys);

		$this->view->title = "List of surveys (".count($surveys).")";
		$this->view->surveys = $surveys;
	}

	/**
	 * Manage survey's questions and answers
	 *
	 * @author kuma
	 */
	public function surveyQuestionsAction()
	{
		$connection = new Connection();
		$this->view->message = false;
		$this->view->message_type = 'success';

		$option = $this->request->get('option');
		$sql = false;
		if ($this->request->isPost()){

			switch($option){
				case "addQuestion":
					$survey = $this->request->getPost('survey');
					$title = $this->request->getPost('surveyQuestionTitle');
					$sql ="INSERT INTO _survey_question (survey, title) VALUES ('$survey','$title');";
					$this->view->message = "Question <b>$title</b> was inserted successfull";
				break;
				case "setQuestion":
					$question_id = $this->request->get('id');
					$title = $this->request->getPost('surveyQuestionTitle');
					$sql ="UPDATE _survey_question SET title = '$title' WHERE id = '$question_id';";
					$this->view->message = "Question <b>$title</b> was updated successfull";
					break;
				case "addAnswer":
					$question_id = $this->request->get('question');
					$title = $this->request->getPost('surveyAnswerTitle');
					$sql ="INSERT INTO _survey_answer (question, title) VALUES ('$question_id','$title');";
					$this->view->message = "Answer <b>$title</b> was inserted successfull";
				break;
				case "setAnswer":
					$answer_id = $this->request->get('id');
					$title = $this->request->getPost('surveyAnswerTitle');
					$sql = "UPDATE _survey_answer SET title = '$title' WHERE id = '$answer_id';";
					$this->view->message = "The answer was updated successfull";
				break;
			}
		}

		switch($option)
		{
			case "delAnswer":
				$answer_id = $this->request->get('id');
				$sql = "DELETE FROM _survey_answer WHERE id ='{$answer_id}'";
				$this->view->message = "The answer was deleted successfull";
			break;

			case "delQuestion":
				$question_id = $this->request->get('id');
				$sql = "START TRANSACTION;
						DELETE FROM _survey_question WHERE id = '{$question_id}';
						DELETE FROM _survey_answer WHERE question ='{$question_id}';
						COMMIT;";
				$this->view->message = "The question was deleted successfull";
			break;
		}

		if ($sql!=false) $connection->deepQuery($sql);

		$survey = $this->request->get('survey');

		$r = $connection->deepQuery("SELECT * FROM _survey WHERE id = '{$survey};'");
		if ($r !== false) {
			$sql = "SELECT * FROM _survey_question WHERE survey = '$survey' order by id;";
			$survey = $r[0];
			$questions = $connection->deepQuery($sql);
			if ($questions !== false) {

				foreach ($questions as $k=>$q){
					$answers = $connection->deepQuery("SELECT * FROM _survey_answer WHERE question = '{$q->id}';");
					if ($answers==false) $answers = array();
					$questions[$k]->answers=$answers;
				}

				$this->view->title = "Survey's questions";
				$this->view->survey = $survey;
				$this->view->questions = $questions;
			}
		}
	}

	/**
	 * Remarket
	 *
	 * @author salvipascual
	 * */
	public function remarketingAction()
	{
		// create the sql for the graph
		$sqlSent = $sqlOpened = "";
		foreach (range(0,7) as $day)
		{
			$sqlSent .= "
				SELECT
					DATE(sent) as moment,
					SUM(case when type = 'REMINDER1' then 1 else 0 end) as reminder1,
					SUM(case when type = 'REMINDER2' then 1 else 0 end) as reminder2,
					SUM(case when type = 'EXCLUDED' then 1 else 0 end) as excluded,
					SUM(case when type = 'INVITE' then 1 else 0 end) as invite,
					SUM(case when type = 'AUTOINVITE' then 1 else 0 end) as autoinvite,
					SUM(case when type = 'SURVEY' then 1 else 0 end) as survey,
					SUM(case when type = 'ERROR' then 1 else 0 end) as error,
					SUM(case when type = 'LESSUSAGE' then 1 else 0 end) as lessusage
				FROM remarketing
				WHERE DATE(sent) = DATE(DATE_SUB(NOW(), INTERVAL $day DAY))
				GROUP BY moment";
			$sqlOpened .= "
				SELECT
					DATE(opened) as moment,
					SUM(case when type = 'REMINDER1' then 1 else 0 end) as reminder1,
					SUM(case when type = 'REMINDER2' then 1 else 0 end) as reminder2,
					SUM(case when type = 'EXCLUDED' then 1 else 0 end) as excluded,
					SUM(case when type = 'INVITE' then 1 else 0 end) as invite,
					SUM(case when type = 'AUTOINVITE' then 1 else 0 end) as autoinvite,
					SUM(case when type = 'SURVEY' then 1 else 0 end) as survey,
					SUM(case when type = 'ERROR' then 1 else 0 end) as error,
					SUM(case when type = 'LESSUSAGE' then 1 else 0 end) as lessusage
				FROM remarketing
				WHERE DATE(opened) = DATE(DATE_SUB(NOW(), INTERVAL $day DAY))
				GROUP BY moment";
			if($day < 7) { $sqlSent .= " UNION "; $sqlOpened .= " UNION "; }
		}

		// get the delivery status per code
		$connection = new Connection();
		$sent = $connection->deepQuery($sqlSent);
		$opened = $connection->deepQuery($sqlOpened);

		// pass info to the view
		$this->view->title = "Remarketing";
		$this->view->sent = array_reverse($sent);
		$this->view->opened = array_reverse($opened);
	}

	/**
	 * add credits
	 *
	 * @author kuma
	 * */
	public function addcreditAction()
	{
		$this->view->person = false;
		$this->view->title = "Add credit";
		$this->view->message = false;
		$this->view->message_type = 'success';

		if ($this->request->isPost())
		{
			$email = $this->request->getPost('email');
			$credit = $this->request->getPost('credit');

			if (is_null($credit) || $credit == 0)
			{
				$this->view->message = "Please, type the credit";
				$this->view->message_type = 'danger';
			}
			elseif ( ! is_null($email))
			{
				$utils = new Utils();
				$person = $utils->getPerson($email);

				if ($person !== false)
				{
					$confirm = $this->request->getPost('confirm');
					if (is_null($confirm))
					{
						if ($person->credit + $credit < 0)
						{
							$this->view->person = false;
							$this->view->message = "It is not possible to decrease <b>".number_format($credit, 2)."</b> from user's credit";
							$this->view->message_type = 'danger';
						}
						else
						{
							$this->view->person = $person;
							$this->view->credit = $credit;
							$this->view->newcredit = $credit + $person->credit;
						}
					}
					else
					{
						$db = new Connection();
						$sql = "UPDATE person SET credit = credit + $credit WHERE email = '$email';";
						$db->deepQuery($sql);
						$this->view->message = "User's credit updated successfull";
					}
				}
				else
				{
					$this->view->message = "User <b>$email</b> not found";
					$this->view->message_type = 'danger';
				}
			}
		}
	}

	/**
	 * Reports for the ads
	 *
	 * @author kuma
	 * */
	public function adReportAction()
	{
		// getting ad's id
		$url = $_GET['_url'];
		$id =  explode("/",$url);
		$id = intval($id[count($id)-1]);

		$db = new Connection();

		$ad = $db->deepQuery("SELECT * FROM ads WHERE id = $id;");
		$this->view->ad = false;

		if ($ad !== false)
		{
			$week = array();

			// @TODO fix field name in database: ad_bottom to ad_bottom
			$sql = "SELECT WEEKDAY(request_time) as w,
					count(usage_id) as total
					FROM utilization
					WHERE (ad_top = $id OR ad_bottom = $id)
					and service <> 'publicidad'
					and DATE(request_time) >= CURRENT_DATE - 6
					GROUP BY w
					ORDER BY w";

			$r = $db->deepQuery($sql);

			if (is_array($r))
			{
				foreach($r as $i)
				{
					if ( ! isset($week[$i->w])) $week[$i->w] = array('impressions'=>0,'clicks'=>0);
					$week[$i->w]['impressions'] = $i->total;
				}
			}

			$sql = "
				SELECT
				WEEKDAY(request_time) as w,
				count(usage_id) as total
				FROM utilization
				WHERE service = 'publicidad'
				and (subservice = '' OR subservice is NULL)
				and query * 1 = $id
				and YEAR(request_time) = YEAR(CURRENT_DATE)
				GROUP BY w";

			$r = $db->deepQuery($sql);
			if (is_array($r))
			{
				foreach($r as $i)
				{
					if ( ! isset($week[$i->w])) $week[$i->w] = array('impressions'=>0,'clicks'=>0);
					$week[$i->w]['clicks'] = $i->total;
				}
			}

			$this->view->weekly = $week;

			$month = array();

			$sql = "
				SELECT
				MONTH(request_time) as m, count(usage_id) as total
				FROM utilization WHERE (ad_top = $id OR ad_bottom = $id)
				and service <> 'publicidad'
				and YEAR(request_time) = YEAR(CURRENT_DATE)
				GROUP BY m";

			$r = $db->deepQuery($sql);

			if (is_array($r))
			{
				foreach($r as $i)
				{
					if ( ! isset($month[$i->m]))
						$month[$i->m] = array('impressions'=>0,'clicks'=>0);
					$month[$i->m]['impressions'] = $i->total;
				}
			}

			$sql = "
				SELECT
				MONTH(request_time) as m,
				count(usage_id) as total
				FROM utilization
				WHERE service = 'publicidad'
				and (trim(subservice) = '' OR subservice is NULL)
				and query * 1 = $id
				and YEAR(request_time) = YEAR(CURRENT_DATE)
				GROUP BY m";

			$r = $db->deepQuery($sql);
			if (is_array($r))
			{
				foreach($r as $i)
				{
					if ( ! isset($month[$i->m]))
						$month[$i->m] = array('impressions'=>0,'clicks'=>0);
						$month[$i->m]['clicks'] = $i->total;

				}
			}

			// join sql
			$jsql = "SELECT * FROM utilization INNER JOIN person ON utilization.requestor = person.email
			WHERE service = 'publicidad'
				and (subservice = '' OR subservice is NULL)
				and query * 1 = $id
				and YEAR(request_time) = YEAR(CURRENT_DATE)";

			// usage by age
			$sql = "SELECT IFNULL(YEAR(CURDATE()) - YEAR(subq.date_of_birth), 0) as a, COUNT(*) as t FROM ($jsql) AS subq GROUP BY a;";
			$r = $db->deepQuery($sql);

			$usage_by_age = array(
				'0-16' => 0,
				'17-21' => 0,
				'22-35' => 0,
				'36-55' => 0,
				'56-130' => 0
			);

			if ($r != false)
			{
				foreach($r as $item)
				{
					$a = $item->a;
					$t = $item->t;
					if ($a < 17) $usage_by_age['0-16'] += $t;
					if ($a > 16 && $a < 22) $usage_by_age['17-21'] += $t;
					if ($a > 21 && $a < 36) $usage_by_age['22-35'] += $t;
					if ($a > 35 && $a < 56) $usage_by_age['36-55'] += $t;
					if ($a > 55) $usage_by_age['56-130'] += $t;
				}
			}

			$this->view->usage_by_age = $usage_by_age;

			// usage by X (enums)
			$X = array('gender','skin','province','highest_school_level','marital_status','sexual_orientation','religion');

			foreach($X as $xx)
			{
				$usage = array();
				$r = $db->deepQuery("SELECT subq.$xx as a, COUNT(*) as t FROM ($jsql) AS subq WHERE subq.$xx IS NOT NULL GROUP BY subq.$xx;");

				if ($r != false)
				{
					foreach($r as $item) $usage[$item->a] = $item->t;
				}

				$p = "usage_by_$xx";
				$this->view->$p = $usage;
			}

			$this->view->weekly = $week;
			$this->view->monthly = $month;
			$this->view->title = "Ad report";
			$this->view->ad = $ad[0];
		}
	}

	/**
	 * Show the ads target
	 *
	 * @author kuma
	 * */
	public function adTageringAction()
	{
		// getting ad's id
		$url = $_GET['_url'];
		$id =  explode("/",$url);
		$id = intval($id[count($id)-1]);
		$db = new Connection();
		$ad = $db->deepQuery("SELECT * FROM ads WHERE id = $id;");
		$this->view->ad = false;

		if ($ad !== false)
		{
			if ($this->request->isPost())
			{
				$sql = "UPDATE ads SET ";
				$go = false;
				foreach($_POST as $key => $value)
				{
					if (isset($ad[0]->$key))
					{
						$go  = true;
						$sql .= " $key = '{$value}', ";
					}
				}

				if ($go)
				{
					$sql = substr($sql,0,strlen($sql)-2);
					$sql .= "WHERE id = $id;";
					$db->deepQuery($sql);
				}

				$ad = $db->deepQuery("SELECT * FROM ads WHERE id = $id;");
			}

			$this->view->title ="Ad targeting";
			$this->view->ad = $ad[0];
		}
	}

	/**
	 * Survey reports
	 */
	public function surveyReportAction(){
		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$id =  explode("/",$url);
		$id = intval($id[count($id)-1]);

		$report = $this->getSurveyResults($id);

		if ($report !== false){
			$db = new Connection();
			$survey = $db->deepQuery("SELECT * FROM _survey WHERE id = $id;");
			$this->view->results = $report;
			$this->view->survey = $survey[0];
			$this->view->title = 'Survey report';
		} else {
			$this->survey = false;
		}
	}

	/**
	 * Calculate and return survey's results
	 *
	 * @author kuma
	 * @param integer $id
	 */
	private function getSurveyResults($id){
		$db = new Connection();
		$survey = $db->deepQuery("SELECT * FROM _survey WHERE id = $id;");

		$by_age = array(
				'0-16' => 0,
				'17-21' => 0,
				'22-35' => 0,
				'36-55' => 0,
				'56-130' => 0
		);

		if ($survey !== false){

			$enums = array(
					'person.age' => 'By age',
					'person.province' => "By location",
					'person.gender' => 'By gender',
					'person.highest_school_level' => 'By level of education'
			);

			$report = array();

			foreach ($enums as $field => $enum_label){
				$sql = "
				SELECT
				_survey.id AS survey_id,
				_survey.title AS survey_title,
				_survey_question.id AS question_id,
				_survey_question.title AS question_title,
				_survey_answer.id AS answer_id,
				_survey_answer.title AS answer_title,
				IFNULL($field,'_UNKNOW') AS pivote,
				Count(_survey_answer_choosen.email) AS total
				FROM
				_survey Inner Join (_survey_question inner join ( _survey_answer inner join (_survey_answer_choosen inner join (select *, YEAR(CURDATE()) - YEAR(person.date_of_birth) as age from person) as person ON _survey_answer_choosen.email = person.email) on _survey_answer_choosen.answer = _survey_answer.id) ON _survey_question.id = _survey_answer.question)
				ON _survey_question.survey = _survey.id
				WHERE _survey.id = $id
				AND trim($field) <> ''
				GROUP BY
				_survey.id,
				_survey.title,
				_survey_question.id,
				_survey_question.title,
				_survey_answer.id,
				_survey_answer.title,
				$field
				ORDER BY _survey.id, _survey_question.id, _survey_answer.id, pivote";

				$r = $db->deepQuery($sql);

				$pivots = array();
				$totals = array();
				$results = array();
				if ($r!==false){
					foreach($r as $item){
						$item->total = intval($item->total);
						$q = intval($item->question_id);
						$a = intval($item->answer_id);
						if (!isset($results[$q]))
							$results[$q] = array(
									"i" => $q,
									"t" => $item->question_title,
									"a" => array(),
									"total" => 0
							);

							if (!isset($results[$q]['a'][$a]))
								$results[$q]['a'][$a] = array(
										"i" => $a,
										"t" => $item->answer_title,
										"p" => array(),
										"total" => 0
								);

								$pivot = $item->pivote;

								if ($field == 'person.age'){
									if (trim($pivot)=='' || $pivot=='0' || $pivot =='NULL') $pivot='_UNKNOW';
									elseif ($pivot*1 < 17) $pivot = '0-16';
									elseif ($pivot*1 > 16 && $pivot*1 < 22) $pivot = '17-21';
									elseif ($pivot*1 > 21 && $pivot*1 < 36) $pivot = '22-35';
									elseif ($pivot*1 > 35 && $pivot*1 < 56) $pivot = '36-55';
									elseif ($pivot*1 > 55) $pivot = '56-130';
								}

								$results[$q]['a'][$a]['p'][$pivot] = $item->total;

								if (!isset($totals[$a]))
									$totals[$a] = 0;

									$totals[$a] += $item->total;
									$results[$q]['a'][$a]['total'] += $item->total;
									$results[$q]['total'] += $item->total;
									$pivots[$pivot] = str_replace("_"," ", $pivot);
					}
				}

				// fill details...
				$sql = "
				SELECT
				_survey.id AS survey_id,
				_survey.title AS survey_title,
				_survey_question.id AS question_id,
				_survey_question.title AS question_title,
				_survey_answer.id AS answer_id,
				_survey_answer.title AS answer_title
				FROM
				_survey Inner Join (_survey_question inner join
				_survey_answer ON _survey_question.id = _survey_answer.question)
				ON _survey_question.survey = _survey.id
				WHERE _survey.id = $id
				ORDER BY _survey.id, _survey_question.id, _survey_answer.id";

				$survey_details = $db->deepQuery($sql);

				foreach($survey_details as $item){
					$q = intval($item->question_id);
					$a = intval($item->answer_id);
					if (!isset($results[$q]))
						$results[$q] = array(
								"i" => $q,
								"t" => $item->question_title,
								"a" => array()
						);

						if (!isset($results[$q]['a'][$a]))
							$results[$q]['a'][$a] = array(
									"i" => $a,
									"t" => $item->answer_title,
									"p" => array(),
									"total" => 0
							);
							if (!isset($totals[$a]))
								$totals[$a] = 0;
				}



				asort($pivots);
				unset($pivots['_UNKNOW']);
				$pivots['_UNKNOW'] = 'UNKNOW';

				$report[$field] = array(
						'label' => $enum_label,
						'results' => $results,
						'pivots' => $pivots,
						'totals' => $totals
				);

				// adding unknow labels

				foreach ($report[$field]['results'] as $k => $question){
					foreach($question['a'] as $kk => $ans){
						$report[$field]['results'][$k]['a'][$kk]['p']['_UNKNOW'] = $totals[$ans['i']*1];
						foreach($ans['p'] as $kkk => $pivot){
							$report[$field]['results'][$k]['a'][$kk]['p']['_UNKNOW'] -= $pivot;
						}
					}
				}
			}

			return $report;
		}

		return false;
	}

	/**
	 * Download survey's results as CSV
	 *
	 * @author kuma
	 */
	public function surveyResultsCSVAction()
	{
		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$id =  explode("/",$url);
		$id = intval($id[count($id)-1]);
		$db = new Connection();
		$survey = $db->deepQuery("SELECT * FROM _survey WHERE id = $id;");
		$survey = $survey[0];
		$results = $this->getSurveyResults($id);
		$csv = array();

		$csv[0][0] = $survey->title;
		$csv[1][0] = "";

		 foreach ($results as $field => $result){

			$csv[][0] = $result['label'];
			$row = array('','Total','Percentage');

			foreach ($result['pivots'] as $pivot => $label)
				$row[] = $label;

			$csv[] = $row;

			foreach($result['results'] as $question){
				$csv[][0] = $question['t'];
				foreach($question['a'] as $ans) {

					if (!isset($ans['total'])) $ans['total'] = 0;
					if (!isset($question['total'])) $question['total'] = 0;

					$row = array($ans['t'], $ans['total'], ($question['total'] ===0?0:number_format($ans['total'] / $question['total'] * 100, 1)));
					foreach ($result['pivots'] as $pivot => $label) {
						if (!isset($ans['p'][$pivot])) {
							$row[] = "0.0";
						} else {
							$part = intval($ans['p'][$pivot]);
							$total = intval($ans['total']);
							$percent = $total === 0?0:$part/$total*100;
							$row[] = number_format($percent,1);
						}
					}
					$csv[] = $row;
				}
				$csv[][0] = '';
			}
			$csv[][0] = '';
		 }

		$csvtext = '';
		foreach($csv as $i => $row){
			foreach ($row as $j => $cell){
				$csvtext .= '"'.$cell.'";';
			}
			$csvtext .="\n";
		}

		header("Content-type: text/csv");
		header("Content-Type: application/force-download");
		header("Content-Type: application/octet-stream");
		header("Content-Type: application/download");
		header("Content-Disposition: attachment; filename=\"ap-survey-$id-results-".date("Y-m-d-h-i-s").".csv\"");
		header("Content-Length: ".strlen($csvtext));
		header("Pragma: public");
		header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
		header("Accept-Ranges: bytes");

		echo $csvtext;

		$this->view->disable();
	}

	public function surveyWhoUnfinishedAction()
	{
		// getting ad's id
		$url = $_GET['_url'];
		$id =  explode("/",$url);
		$id = intval($id[count($id)-1]);
		$db = new Connection();
		$survey = $db->deepQuery("SELECT * FROM _survey WHERE id = $id;");
		if ($survey!==false){
			$survey = $survey[0];

			$sql = "SELECT * FROM (SELECT email, survey, (SELECT count(*)
					  FROM _survey_question
					  WHERE _survey_question.survey = _survey_answer_choosen.survey) as total,
					count(question) as choosen from _survey_answer_choosen GROUP BY email, survey) subq
					WHERE subq.total>subq.choosen AND subq.survey = $id;";

			$r = $db->deepQuery($sql);

			$this->view->results = $r;
			$this->view->title = "Who unfinished the survey";
			$this->view->survey = $survey;
		}
	}

	/**
	 * Download survey's results as PDF
	 *
	 * @author kuma
	 */
	public function surveyReportPDFAction()
	{
		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$id =  explode("/",$url);
		$id = intval($id[count($id)-1]);

		$db = new Connection();
		$survey = $db->deepQuery("SELECT * FROM _survey WHERE id = $id;");
		$survey = $survey[0];

 		$csv = array();
 		$title = "{$survey->title} - ".date("d M, Y");

 		$html = '<html><head><title>'.$title.'</title><style>
 				h1 {color: #5EBB47;text-decoration: underline;font-size: 24px; margin-top: 0px;}
 				h2{ color: #5EBB47; font-size: 16px; margin-top: 0px; }
 				body{font-family:Verdana;}</style><body>';

 		$html .= "<br/><h1>$title</h1>";

		$questions = $db->deepQuery("SELECT * FROM _survey_question WHERE survey = $id;");

		$i = 0;
		$total = count($questions);
		foreach($questions as $question)
		{
			//$html .= "<h2>". $question->title . "</h2>";
			$answers = $db->deepQuery("SELECT *, (SELECT count(*) FROM _survey_answer_choosen WHERE _survey_answer_choosen.answer = _survey_answer.id) as choosen FROM _survey_answer WHERE question = {$question->id};");

			$values = '';
			foreach($answers as $ans){
				$values[wordwrap($ans->title,50)." ({$ans->choosen})"] = $ans->choosen;
			}

			$PieChart = null;
			$chart = $this->getPieChart($question->title, $values, $PieChart);

			$html .= '<table width="100%"><tr><td valign="top" width="250">';
			$html .= '<thead><caption>'.$question->title.'</caption></thead>';
			$html .= '<img src="data:image/png;base64,'.$chart.'"><br/>';
			$html .="</td><td valign=\"top\">";

			$Data	= $PieChart->pDataObject->getData();
			$Palette = $PieChart->pDataObject->getPalette();

			$html .= "<table width=\"100%\">";
			foreach($Data["Series"][$Data["Abscissa"]]["Data"] as $Key => $Value)
			{

				$R = $Palette[$Key]["R"];
				$G = $Palette[$Key]["G"];
				$B = $Palette[$Key]["B"];
				$html .= "<tr><td>";
				$html .= "<tr><td><span style=\"width:30px;height:30px;background:rgb($R,$G,$B);\">&nbsp;&nbsp;</span></td><td>$Value</td></tr>";
			}
			$html .= "</table>";

			$html .= "</td></tr></table><br/>";

			$i++;
			//if ($i % 4 == 0 && $i < $total) $html .= '<pagebreak />';
		}

 		$html .= '</body></html>';

 		//die($html);

		$mpdf = new mPDF('','A4', 0, '', 10, 10, 10, 10, 1, 1, 'P');
		$mpdf->WriteHTML(trim($html));
		$mpdf->Output("$title.pdf", 'D');
		$this->view->disable();
	}

	/**
	 * Get image with a pie chart
	 *
	 * @author kuma
	 * @param string $title
	 * @param array $values
	 */
	private function getPieChart($title, $values, &$chartObj){

		include_once "../lib/pChart2.1.4/class/pData.class.php";
		include_once "../lib/pChart2.1.4/class/pDraw.class.php";
		include_once "../lib/pChart2.1.4/class/pPie.class.php";
		include_once "../lib/pChart2.1.4/class/pImage.class.php";

		$MyData = new pData();
		$MyData->addPoints($values,"ScoreA");
		$MyData->setSerieDescription("ScoreA",$title);
		$MyData->addPoints(array_keys($values),"Labels");
		$MyData->setAbscissa("Labels");

		$myPicture = new pImage(250,150,$MyData);
		$myPicture->setFontProperties(array(
			"FontName" => "../lib/pChart2.1.4/fonts/verdana.ttf",
			"FontSize" => 13,
			"R" => 0,
			"G" => 0,
			"B" => 0
		));

		$myPicture->drawText(10, 23, $title, array(
			"R" => 255,
			"G" => 255,
			"B" => 255
		));

		$myPicture->setShadow(TRUE, array(
			"X" => 2,
			"Y" => 2,
			"R" => 0,
			"G" => 0,
			"B" => 0,
			"Alpha" => 50
		));

		$PieChart = new pPie($myPicture,$MyData);
		$PieChart->draw2DPie(125, 80, array(
			"Radius" => 50,
			"WriteValues" => PIE_VALUE_PERCENTAGE,
			"ValuePadding" => 10,
			"DataGapAngle" => 0,
			"DataGapRadius" => 0,
			"Border" => FALSE,
			"BorderR" => 0,
			"BorderG" => 0,
			"BorderB"=> 0,
			"ValueR"=> 0,
			"ValueG" => 0,
			"ValueB" => 0,
			"Shadow" => FALSE
		));
		/*
		$PieChart->drawPieLegend(300, 18, array(
			"Style" => LEGEND_NOBORDER,
			"Mode" => LEGEND_VERTICAL,
			"BoxSize" => 25,
			"FontSize" => 10,
			"Margin" => 20
		));
*/
		$chartObj = $PieChart;

		ob_start();
		imagepng($myPicture->Picture);
		$img = ob_get_contents();
		ob_end_clean();

		return base64_encode($img);
	}

    /**
     * Top menu
     *
     * @param string $name
     */
    private function setMenu($name = 'default')
    {
        switch ($name)
        {
            case 'market':
                $this->view->menu = [
                       ['caption' => 'Market', 'href' => '/manage/market', 'icon' => 'shopping-cart'],
                       ['caption' => 'Orders', 'href' => '/manage/marketOrders', 'icon' => 'bell'],
                       ['caption' => 'Stats', 'href' => '/manage/marketStats', 'icon' => 'stats']
                ];
            case 'school':
                $this->view->menu = [
                    ['caption' => 'Courses', 'href' => '/manage/school', 'icon' => 'book'],
                    ['caption' => 'Teachers', 'href' => '/manage/schoolTeachers', 'icon' => 'user'],
                ];
        }
    }

	/**
	 * Market
	 *
	 * @author kuma
	 */
	public function marketAction()
	{
		$connection = new Connection();
		$sql = "SELECT * FROM _tienda_products ORDER BY name;";
		$products = $connection->deepQuery($sql);

		if (!is_array($products))
			$products = array();

            $this->view->products = $products;
            $this->view->title = "Market's products";
            $this->view->breadcrumb = array(
                "admin" => "Admin",
                "admin/market" => "Market"
            );
            $this->setMenu('market');
    }

	/**
	 * New product
	 *
	 * @author kuma
	 */
	public function marketNewProductAction()
	{
		$connection = new Connection();

		if($this->request->isPost())
		{
			// generate code
			$code = substr(date("Ymdhi"), 2);

			// get data from post
			$name = $this->request->getPost('edtName');
			$description = $this->request->getPost('edtDesc');
			$category = $this->request->getPost('edtCategory');
			$price = $this->request->getPost('edtPrice') * 1;
			$shipping_price = $this->request->getPost('edtShippingPrice') * 1;
			$credits = $this->request->getPost('edtCredits') * 1;
			$agency = $this->request->getPost('edtAgency');
			$owner = $this->request->getPost('edtOwner');

			// add product
			$sql = "INSERT INTO _tienda_products (code, name, description, category, price, shipping_price, credits, agency, owner)
					VALUES ('$code', '$name', '$description','$category','$price','$shipping_price','$credits','$agency','$owner');";

			$connection->deepQuery($sql);

			// add inventory
			$sql = "INSERT INTO inventory (code, price, name, seller, service, active)
					VALUES ('$code','$credits','$name','$owner','MERCADO',0);";

			$connection->deepQuery($sql);

			// redirect to edit product page
			$this->view->code = $code;
			return $this->dispatcher->forward(array("controller"=> "manage", "action" => "marketDetail"));
		}
	}

	/**
	 * Update product
	 *
	 * @author kuma
	 */
	public function marketUpdateAction()
	{
		$connection = new Connection();

		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$code =  explode("/",$url);
		$code = $code[count($code)-1];

		if ($this->request->isPost())
		{
			$name = $this->request->getPost('edtName');
			$description = $this->request->getPost('edtDesc');
			$category = $this->request->getPost('edtCategory');
			$price = $this->request->getPost('edtPrice') * 1;
			$shipping_price = $this->request->getPost('edtShippingPrice') * 1;
			$credits = $this->request->getPost('edtCredits') * 1;
			$agency = $this->request->getPost('edtAgency');
			$owner = $this->request->getPost('edtOwner');

			$sql = "
			UPDATE _tienda_products
			SET	   name = '$name',
			   description = '$description',
				  category = '$category',
					 price = '$price',
			shipping_price = '$shipping_price',
				   credits = '$credits',
					agency = '$agency',
					 owner = '$owner'
			WHERE code = '$code';";

			$connection->deepQuery($sql);

			// update inventory
			$sql = "
			UPDATE inventory
			SET name = '$name',
			   price = '$credits',
			  seller = '$owner'
			WHERE code = '$code';";

			$connection->deepQuery($sql);

			$this->view->message = 'The product was updated';
			$this->view->message_type = "success";
			$this->view->code = $code;
			return $this->dispatcher->forward(array("controller"=> "manage", "action" => "marketDetail"));
		}
	}

	/**
	 * Edit product
	 *
	 * @author kuma
	 */
	public function marketDetailAction()
	{
		$connection = new Connection();
		$wwwroot = $this->di->get('path')['root'];

		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$code =  explode("/",$url);
		$code = $code[count($code)-1];

		if ($code == 'marketNewProduct' || $code == 'marketDetail' || empty(trim($code)))
			$code = null;

		if (is_null($code))
			if (isset($this->view->code))
				$code = $this->view->code;
			else
			{
				$this->view->message_type = "danger";
				$this->view->message = "Missing product's code";
				return $this->dispatcher->forward(array("controller"=> "manage", "action" => "market"));
			}

		$sql = "SELECT * FROM _tienda_products WHERE code = '$code';";

		$product = $connection->deepQuery($sql);

		if ( ! is_array($product))
		{
			$this->view->message_type = "danger";
			$this->view->message = "Product <b>$code</b> not exists";
			return $this->dispatcher->forward(array("controller"=> "manage", "action" => "market"));
		}

            $this->view->product = $product[0];
            $this->view->wwwroot = $wwwroot;
            $this->view->title = "Product's details";
            $this->view->breadcrumb = array(
                    'admin' => 'Admin',
                    'market' => 'Market',
                    'marketDetail/'.$code => 'Product '.$code,
            );
            $this->setMenu('market');
    }

	/**
	 * Set product's picture
	 *
	 * @author kuma
	 */
	public function marketPictureAction()
	{
		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$code =  explode("/",$url);
		$code = $code[count($code)-1];

		$wwwroot = $this->di->get('path')['root'];
		$fname = "$wwwroot/public/products/$code.jpg";
		copy($_FILES['file_data']['tmp_name'], $fname);
		$utils = new Utils();
		$utils->optimizeImage($fname, '', '', 100, 'image/jpeg');

		echo '{}';
		$this->view->disable();
	}

	/**
	 * Delete product's picture
	 *
	 * @author kuma
	 */
	public function marketPictureDeleteAction()
	{
		$code = $this->request->getPost('code');
		$wwwroot = $this->di->get('path')['root'];

		$fn = "$wwwroot/public/products/$code";
		if (file_exists($fn))
			unlink($fn);

		echo '{result: true}';
		$this->view->disable();
	}

	/**
	 * Delete product
	 *
	 * @author kuma
	 */
	public function marketDeleteAction()
	{
		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$code =  explode("/",$url);
		$code = $code[count($code)-1];

		$connection = new Connection();
		$wwwroot = $this->di->get('path')['root'];

		// delete record from database
		$sql = "DELETE FROM _tienda_products WHERE code = '$code';";
		$connection->deepQuery($sql);

		// delete record from inventory
		$sql = "DELETE FROM inventory WHERE code = '$code';";
		$connection->deepQuery($sql);

		// delete related picture
		$fn = "$wwwroot/public/products/$code";
		if (file_exists($fn)) unlink($fn);

		$this->view->message = "The product $code was deleted";
		$this->view->message_type = "success";
		return $this->dispatcher->forward(array("controller"=> "manage", "action" => "market"));
	}

	/**
	 * Toggle product's activation
	 *
	 * @author kuma
	 */
	public function marketToggleActivationAction()
	{
		$connection = new Connection();
		$code = $this->request->getPost('code');
		$product = $connection->deepQuery("SELECT active FROM _tienda_products WHERE code = '$code';");
		if (is_array($product))
		{
			$product = $product[0];
			$active = $product->active;
			$toggle = '1';
			if ($active == '1')
				$toggle = '0';

			$sql = "UPDATE _tienda_products SET active = '$toggle' WHERE code = '$code';";
			$connection->deepQuery($sql);

			$sql = "UPDATE inventory SET active = '$toggle' WHERE code = '$code';";
			$connection->deepQuery($sql);

			echo $toggle;
			$this->view->disable();
		}
	}

	/**
	 * Retrieve transfer into market's orders
	 *
	 * @author kuma
	 */
	private function updateMarketOrders()
	{
		$sql = "INSERT INTO _tienda_orders (id, product, email, inserted_date)
				SELECT id, inventory_code, sender, transfer_time
				FROM transfer INNER JOIN inventory on transfer.inventory_code = inventory.code
				WHERE inventory.service = 'MERCADO' AND transfer.transfered = '1'
					AND NOT EXISTS (SELECT * FROM _tienda_orders WHERE _tienda_orders.id = transfer.id);";

		$connection = new Connection();
		$connection->deepQuery($sql);
	}

	/**
	 * Manage market's orders
	 *
	 * @author kuma
	 */
	public function marketOrdersAction()
	{
		$this->setMenu('market');
		$this->updateMarketOrders();
		$connection = new Connection();
		$sql = "SELECT *, (SELECT name FROM _tienda_products WHERE code = _tienda_orders.product) as product_name FROM _tienda_orders WHERE received = 0;";
		$orders = $connection->deepQuery($sql);

		if (!is_array($orders))
			$orders = array();

		foreach ($orders as $k => $v)
		{
			$orders[$k]->ready = false;
			if (trim($v->ci) !== '' && trim($v->name) !== '' && trim($v->address) !== '' && trim($v->province) !== '' )
				$orders[$k]->ready = true;
		}

        $this->view->orders = $orders;
        $this->view->title = "Market's orders";
        $this->view->breadcrumb = array(
                'admin' => 'Admin',
                'market' => 'Market',
                'marketOrders' =>'Orders'
        );
    }

	/**
	 * Edit product's destination data
	 *
	 *  @author kuma
	 */
	public function marketDestinationAction()
	{
		$this->setMenu('market');

		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$id =  explode("/",$url);
		$id = $id[count($id)-1];

		$wwwroot = $this->di->get('path')['root'];
		$connection = new Connection();

		if ($this->request->isPost())
		{
			$ci = $this->request->getPost('edtCI');
			$name = $this->request->getPost('edtName');
			$address = $this->request->getPost('edtAddress');
			$province = $this->request->getPost('edtProvince');
			$phone = $this->request->getPost('edtPhone');

			$sql = "UPDATE _tienda_orders SET ci = '$ci', name = '$name', address = '$address', province = '$province', phone = '$phone'
					WHERE id = '$id';";

			$connection->deepQuery($sql);
		}

		$sql = "SELECT * FROM _tienda_orders WHERE id = '$id';";
		$order = $connection->deepQuery($sql);

		if (is_array($order))
		{
			$order = $order[0];
			$order->ready = false;
			if (trim($order->ci) !== '' && trim($order->name) !== '' && trim($order->address) !== '' && trim($order->province) !== '' )
			$order->ready = true;

			$sql = "SELECT * FROM _tienda_products WHERE code = '$order->product';";
			$product = $connection->deepQuery($sql);

			if (is_array($product))
			{
				$product = $product[0];

				$product->image = false;

				if (file_exists("$wwwroot/public/products/{$product->code}.jpg"))
					$product->image = true;

                    $this->view->product = $product;
                    $this->view->order = $order;
                    $this->view->title = "Product's destination";
                    $this->view->breadcrumb = array(
                            'admin' => 'Admin',
                            'market' => 'Market',
                            'marketOrders' => 'Orders',
                            'marketDetail/' . $product->code => substr($product->name, 0, 30),
                            'marketDestination/' . $id => "Destination"
                );
            }
        }
    }

	/**
	 * Edit product's destination data
	 *
	 *  @author kuma
	 */
	public function marketOrderReceivedAction()
	{
		$this->setMenu('market');

		// getting ad's id
		// @TODO: improve this!
		$url = $_GET['_url'];
		$id =  explode("/",$url);
		$id = $id[count($id)-1];
		$id = $id * 1;
		$wwwroot = $this->di->get('path')['root'];
		$connection = new Connection();
		$connection->deepQuery("UPDATE _tienda_orders SET received = 1 WHERE id = $id;");
		$this->view->message = "Order <a href=\"/manage/marketDestination/$id\">{$id}</a> was set as sent";
		$this->view->message_type = "success";
		return $this->dispatcher->forward(array("controller"=> "manage", "action" => "marketOrders"));
	}

	/**
	 * Market statistics
	 *
	 * @author kuma
	 */
	public function marketStatsAction()
	{
		$this->updateMarketOrders();
		$utils = new Utils();
		$this->view->maxCredit = $utils->getStat('person.credit.max');
		$this->view->avgCredit = $utils->getStat('person.credit.avg');
		$this->view->sumCredit = $utils->getStat('person.credit.sum');
		$this->view->minCredit = $utils->getStat('person.credit.min');
		$this->view->monthlySells = $utils->getStat('market.sells.monthly');
		$this->view->totalUsersWidthCredit = $utils->getStat('person.credit.count');
		$this->view->totalUsers =  $utils->getStat('person.count');
		$this->view->sellsByProduct = $utils->getStat('market.sells.byproduct.last30days');
		$this->view->title = "Market' stats";
	}

	/**
	 * List all campaigns
	 *
	 * @author salvipascual
	 */
	public function campaignsAction()
	{
		// get the list of campaigns
		$connection = new Connection();
		$campaigns = $connection->deepQuery("
			SELECT id, subject, sending_date, status, sent, opened, bounced
			FROM campaign
			ORDER BY sending_date DESC");

		// send variables to the view
		$this->view->title = "List of campaigns";
		$this->view->campaigns = $campaigns;
	}

	/**
	 * Show campaign reports
	 *
	 * @author salvipascual
	 */
	public function campaignReportsAction()
	{
		$connection = new Connection();

		// get the list of current suscribers
		$suscribers = $connection->deepQuery("SELECT COUNT(email) AS suscribers FROM person WHERE active=1 AND mail_list=1");
		$suscribers = $suscribers[0]->suscribers;

		// get the last 10 campaigns
		$campaigns = $connection->deepQuery("
			SELECT id, subject, sending_date, status, sent, opened, bounced
			FROM campaign
			WHERE status = 'SENT'
			ORDER BY sending_date ASC
			LIMIT 10");

		// send variables to the view
		$this->view->title = "Campaign reports";
		$this->view->suscribers = $suscribers;
		$this->view->campaigns = $campaigns;
	}

	/**
	 * Show the html for one campaign
	 *
	 * @author salvipascual
	 */
	public function viewCampaignAction()
	{
		$id = $this->request->get("id");

		// get the list of campaigns
		$connection = new Connection();
		$campaign = $connection->deepQuery("
			SELECT subject, content, sending_date, status, sent, opened, bounced
			FROM campaign
			WHERE id = $id");

		// replace the template variables
		$email = $_SESSION['user'];
		$utils = new Utils();
		$campaign[0]->content = $utils->campaignReplaceTemplateVariables($email, $campaign[0]->content);

		// send variables to the view
		$this->view->title = "View campaign";
		$this->view->email = $email;
		$this->view->campaign = $campaign[0];
		$this->view->setLayout('empty');
	}

	/**
	 * Creates a new campaign to send bulk email
	 *
	 * @author salvipascual
	 */
	public function removeCampaignAction()
	{
		$id = $this->request->get("id");

		// remove the campaign
		$connection = new Connection();
		$connection->deepQuery("DELETE FROM campaign WHERE id = $id");

		// go back to the list of campaigns
		$this->response->redirect('manage/campaigns');
	}

	/**
	 * Creates a new campaign to send bulk email
	 *
	 * @author salvipascual
	 */
	public function newCampaignAction()
	{
		// get the email campaign layout
		$wwwroot = $this->di->get('path')['root'];
		$layout = file_get_contents("$wwwroot/app/layouts/email_campaign.tpl");

		// send variables to the view
		$this->view->title = "New campaign";
		$this->view->intent = "create";
		$this->view->email = $_SESSION['user'];
		$this->view->id = "";
		$this->view->subject = "";
		$this->view->date = date("Y-m-d\T23:00");
		$this->view->layout = $layout;
	}

	/**
	 * Updates a campaign in the database
	 *
	 * @author salvipascual
	 */
	public function updateCampaignAction()
	{
		$id = $this->request->get("id");

		// insert the new campaignin the database
		$connection = new Connection();
		$campaign = $connection->deepQuery("SELECT * FROM campaign WHERE id=$id");
		$campaign = $campaign[0];

		// send variables to the view
		$this->view->title = "Edit campaign";
		$this->view->intent = "update";
		$this->view->email = $_SESSION['user'];
		$this->view->id = $campaign->id;
		$this->view->subject = $campaign->subject;
		$this->view->layout = $campaign->content;
		$this->view->date = date("Y-m-d\TH:i", strtotime($campaign->sending_date));
		$this->view->pick("manage/newCampaign");
	}

	/**
	 * Creates save a new the campaign in the database
	 *
	 * @author salvipascual
	 */
	public function newCampaignSubmitAction()
	{
		// get variales from POST
		$id = $this->request->getPost("id"); // for when is updating
		$subject = $this->request->getPost("subject");
		$content = $this->request->getPost("content");
		$date = $this->request->getPost("date");

		// minify the html and remove dangerous characters
		$content = str_replace("'", "&#39;", $content);
		$content = preg_replace('/\s+/S', " ", $content);

		// insert or update the campaign
		$connection = new Connection();
		if(empty($id)) $connection->deepQuery("INSERT INTO campaign (subject, content, sending_date) VALUES ('$subject', '$content', '$date')");
		else $connection->deepQuery("UPDATE campaign SET subject='$subject', content='$content', sending_date='$date' WHERE id=$id");

		// go to the list of campaigns
		$this->response->redirect('manage/campaigns');
	}

	/**
	 * Email a test campaign
	 *
	 * @author salvipascual
	 */
	public function testCampaignAsyncAction()
	{
		// get the variables from the POST
		$email = $this->request->getPost("email");
		$subject = $this->request->getPost("subject");
		$content = $this->request->getPost("content");

		// replace the template variables
		$utils = new Utils();
		$content = $utils->campaignReplaceTemplateVariables($email, $content);

		// send test email
		$sender = new Email();
		$sender->sendEmail($email, $subject, $content);

		// send the response
		echo '{result: true}';
		$this->view->disable();
	}

    /**
     * List of school's courses
     *
     * @author kuma
     */
    public function schoolAction()
    {
        $this->setMenu('school');
        $this->view->breadcrumb = array(
                "admin" => "Admin",
                "school" => "School"
            );

        $connection = new Connection();
        $email = $this->getCurrentUser();
        $teachers = $connection->deepQuery("SELECT * FROM _escuela_teacher");

        $this->view->message = false;
        $this->view->message_type = 'success';
        $option = $this->request->get('option');
        $sql = false;

        if($this->request->isPost())
        {
            $title = $connection->escape($this->request->getPost("courseTitle"));
            $teacher = $connection->escape($this->request->getPost("courseTeacher"));

            if ( ! empty("$teacher"))
            {
                $content = $connection->escape($this->request->getPost("courseContent"));

                switch ($option){
                    case 'add':
                        $sql = "INSERT INTO _escuela_course (title, teacher, content, email, active) VALUES ('$title', '$teacher','$content','$email',0); ";
                        $this->view->message = 'The course was inserted successfull';
                        break;
                    case 'set':
                        $id = $this->request->get('id');

                        $setContent = "";
                        if (isset($_POST['courseContent']))
                        {
                            $setContent = ", content = '$content'";
                        }

                        $sql = "UPDATE _escuela_course SET title = '$title', teacher = '$teacher' $setContent WHERE id = '$id'; ";

                        $this->view->message = "The course <b>$title</b> was updated successfull";
                        break;
                }
            }
            else
            {
                $this->view->message_type = 'danger';
                $this->view->message = 'You must select a teacher';
            }
        }

        switch ($option){
            case "del":
                $id = $this->request->get('id');
                $sql = "START TRANSACTION;
                        DELETE FROM _escuela_answer WHERE course = '$id';
                        DELETE FROM _escuela_question WHERE course = '$id';
                        DELETE FROM _escuela_chapter WHERE course = '$id';
                        DELETE FROM _escuela_course WHERE id = '$id';
                        COMMIT;";
                $this->view->message = "The course #$id was deleted successfull";
                break;

            case "disable":
                $id = $this->request->get('id');
                $sql = "UPDATE _escuela_course SET active = 0 WHERE id ='$id';";
                break;
            case "enable":
                $id = $this->request->get('id');
                $sql = "UPDATE _escuela_course SET active = 1 WHERE id ='$id';";
                break;
        }

        if ($sql !== false)
        {
            $connection->deepQuery($sql);
        }

        $isAdmin = Utils::haveManagePermission($email, 'manage') ? 'TRUE' : 'FALSE';
        $queryCourses = "SELECT * FROM _escuela_course WHERE email = '$email' OR $isAdmin ORDER BY ID";

        $courses = $connection->deepQuery($queryCourses);

        $this->view->title = "School";
        $this->view->courses = $courses;
	$this->view->teachers = $teachers;
    }

    public function schoolTeachersAction()
    {
        $this->setMenu('school');
        $this->view->breadcrumb = array(
            "admin" => "Admin",
            "school" => "School",
            "schoolTeachers" => "Teachers"
        );
        $connection = new Connection();
        $this->view->message = false;
        $this->view->message_type = 'success';
        $option = $this->request->get('option');
        $sql = false;

        if($this->request->isPost())
        {
           $name = $connection->escape($this->request->getPost("teacherName"));
           $title = $connection->escape($this->request->getPost("teacherTitle"));
           $email = $connection->escape($this->request->getPost("teacherEmail"));

           switch ($option)
           {
            case 'add':
                $sql = "INSERT INTO _escuela_teacher (name, title, email) VALUES ('$name', '$title', '$email'); ";
                $this->view->message = 'The teacher was inserted successful';
                break;
            case 'set':
                $id = $this->request->get('id');
                $sql = "UPDATE _escuela_teacher SET name = '$name', title = '$title', email = '$email' WHERE id = '$id'; ";
                $this->view->message = 'The teacher was updated successful';
                break;
           }
        }

        switch ($option)
        {
            case "del":
                $id = $this->request->get('id');
                $sql = "START TRANSACTION;
                        DELETE FROM _escuela_teacher WHERE id = '$id';
                        UPDATE _escuela_course SET teacher = null WHERE teacher = '$id';
                        COMMIT;";
                $this->view->message = "The teacher #$id was deleted successful";
                break;
        }

        if ($sql !== false)
        {
            $connection->deepQuery($sql);
        }

         $teachers = $connection->deepQuery("SELECT * FROM _escuela_teacher;");

         if (!is_array($teachers))
         {
             $teachers = [];
         }

         $this->view->teachers = $teachers;
         $this->view->title = "School";
    }

    /**
     * List of chapters
     *
     * @author kuma
     */
    public function schoolChaptersAction()
    {
        $this->setMenu('school');

        $wwwroot = $this->di->get('path')['root'];
        $connection = new Connection();
        $utils = new Utils();
        $this->view->message = false;
        $this->view->message_type = 'success';

        $course_id = intval($this->request->get('course'));
        $option = $this->request->get('option');

        switch ($option)
        {
            case "up":
                $id = $this->request->get('id');
                $r = $connection->deepQuery("SELECT * FROM _escuela_chapter WHERE id = '$id';");
                if ($r !== false && isset($r[0]))
                {
                    $chapter = $r[0];
                    $connection->deepQuery("UPDATE _escuela_chapter SET xorder = xorder + 1 WHERE course = {$chapter->course} AND xorder = ". ($chapter->xorder - 1));
                    $connection->deepQuery("UPDATE _escuela_chapter SET xorder = xorder - 1 WHERE id = $id AND xorder > 1;");
                }
                break;
            case "down":
                $id = $this->request->get('id');
                $r = $connection->deepQuery("SELECT * FROM _escuela_chapter WHERE id = '$id';");
                if ($r !== false && isset($r[0]))
                {
                    $chapter = $r[0];
                    $max = $connection->deepQuery("SELECT max(xorder) as m FROM _escuela_chapter WHERE course = {$chapter->course};");
                    $max = $max[0]->m;
                    $connection->deepQuery("UPDATE _escuela_chapter SET xorder = xorder - 1 WHERE course = {$chapter->course} AND xorder = ". ($chapter->xorder + 1));
                    $connection->deepQuery("UPDATE _escuela_chapter SET xorder = xorder + 1 WHERE id = $id AND xorder < $max;");

                }
                break;

            case "del":
                $id = $this->request->get('id');

                $r = $connection->deepQuery("SELECT * FROM _escuela_chapter WHERE id = '$id';");
                if ($r !== false && isset($r[0]))
                {
                    $chapter = $r[0];

                    // remove images
                    $utils->rmdir("$wwwroot/public/courses/{$chapter->course}/$id");

                    $sql =
                    "START TRANSACTION;" .
                    "UPDATE _escuela_chapter SET xorder = xorder - 1 WHERE xorder > {$chapter->xorder} AND course = {$chapter->course};" .
                    "DELETE FROM _escuela_chapter WHERE id = '$id';" .
                    "DELETE FROM _escuela_question WHERE chapter = '$id';" .
                    "DELETE FROM _escuela_images WHERE chapter = '$id';" .
                    "COMMIT;";

                    $connection->deepQuery($sql);
                    $this->view->message = "The chapter #$id was deleted successful";
                }
                break;
        }

        $chapters = $connection->deepQuery("SELECT *, (SELECT count(*) FROM _escuela_question WHERE chapter = s1.id) as questions FROM _escuela_chapter s1 WHERE course = '$course_id' ORDER BY xorder;");
        $r = $connection->deepQuery("SELECT * FROM _escuela_course WHERE id = '$course_id';");
        $course = $r[0];

        if (!is_array($chapters))
        {
            $chapters = [];
        }

        $this->view->course = $course;
        $this->view->chapters = $chapters;
        $this->view->title = 'Course: <i>' . $course->title . '</i>';
        $this->view->breadcrumb = array(
            "admin" => "Admin",
            "school" => "School",
            "schoolChapters?course={$course->id}" => "Chapters"
        );
    }

    /**
     * New chapter page
     *
     * @author kuma
     */
    public function schoolNewChapterAction()
    {
        $this->setMenu('school');

        $connection = new Connection();
        $this->view->message = false;
        $this->view->message_type = 'success';

        $course_id = intval($this->request->get('course'));
        $type = $this->request->get('type');

        if ($type !== 'CAPITULO' && $type !== 'PRUEBA')
        {
            $type = 'CAPITULO';
        }
        $r = $connection->deepQuery("SELECT * FROM _escuela_course WHERE id = '$course_id';");
        $course = $r[0];

        $this->view->course = $course;
        $this->view->type = $type;
        $this->view->course_id = $course_id;
        $this->view->title = $type == 'CAPITULO'?
                                'New chapter for course <i>' . $course->title . '</i>':
                                'New test for course <i>' . $course->title . '</i>';
    }

    public function schoolNewChapterPostAction()
    {
        $this->setMenu('school');
        $wwwroot = $this->di->get('path')['root'];
        if ($this->request->isPost())
        {
            $connection = new Connection();
            $utils = new Utils();

            $chapterTitle = $connection->escape($this->request->getPost('title'));
            $chapterContent = $this->request->getPost('content');
            $images  = $utils->getInlineImagesFromHTML($chapterContent);
            $chapterContent = $connection->escape($chapterContent);
            $chapterType = $this->request->getPost('type');
            $course_id = intval($this->request->get('course'));
            $coursesFolder = $wwwroot."/public/courses";

            if ( ! file_exists($coursesFolder))
            {
                @mkdir($coursesFolder);
            }

            if ( ! file_exists("$coursesFolder/$course_id"))
            {
                @mkdir("$coursesFolder/$course_id");
            }

            $r = $connection->deepQuery("SELECT count(*) as total FROM _escuela_chapter WHERE course = '$course_id';");
            $order = intval($r[0]->total) + 1;

            if (isset($_GET['id']))
            {
                $id = $this->request->get('id');
                $sql = "UPDATE _escuela_chapter SET title = '$chapterTitle', content = '$chapterContent', xtype = '$chapterType' WHERE id = '$id';";
                $connection->deepQuery($sql);

                // clear old images
                $utils->rmdir("$wwwroot/public/courses/{$course_id}/$id");
            }
            else
            {
                $r = $connection->deepQuery("SELECT max(id) as m FROM _escuela_chapter;");
                $id = $r[0]->m + 1;
                $sql = "INSERT INTO _escuela_chapter (id, title, content, course, xtype, xorder) VALUES ($id, '$chapterTitle', '$chapterContent', '$course_id', '$chapterType', $order);";
                $connection->deepQuery($sql);
                //$r = $connection->deepQuery("SELECT LAST_INSERT_ID();");
                //$id = $id[0]->id;
            }

            // save images
            $chapterFolder = $coursesFolder."/$course_id/$id";
            if (!file_exists($chapterFolder))
                @mkdir($chapterFolder);

            if (file_exists($chapterFolder))
            {
                foreach($images as $idimg => $img)
                {
                    file_put_contents($chapterFolder."/$idimg", base64_decode($img['content']));
                    $connection->deepQuery("INSERT INTO _escuela_images (id, filename, mime_type, chapter, course) VALUES ('$idimg','{$img['filename']}','{$img['type']}','$id','$course_id');");
                }
            }

            $this->view->chapter_id = $id;
            return $this->dispatcher->forward(array("controller"=> "manage", "action" => "schoolChapter"));
        }
    }

    public function schoolEditChapterAction()
    {
        $this->setMenu('school');

        $url = $_GET['_url'];
        $id =  explode("/", $url);
        $id = $id[count($id) - 1];

        $connection = new Connection();
        $utils = new Utils();
        $this->view->message = false;
        $this->view->message_type = 'success';
        $this->view->title = "Edit chapter";

        $r = $connection->deepQuery("SELECT * FROM _escuela_chapter WHERE id = '$id';");

        if (isset($r[0]))
        {
            $chapter = $r[0];
            $images = $this->getChapterImages($id);
            $chapter->content = $utils->putInlineImagesToHTML($chapter->content, $images);
            $this->view->chapter = $chapter;
        }
        else
            $this->dispatcher->forward(array("controller"=> "manage", "action" => "pageNotFound"));
    }

    public function schoolChapterAction()
    {
        $this->setMenu('school');

        if (isset($this->view->chapter_id))
        {
            $id =  $this->view->chapter_id;
        }
        else
        {
            $url = $_GET['_url'];
            $id =  explode("/",$url);
            $id = $id[count($id)-1];
        }

        $connection = new Connection();
        $utils = new Utils();

        $r = $connection->deepQuery("SELECT * FROM _escuela_chapter WHERE id = '$id';");
        $chapter = $r[0];

        $images = $this->getChapterImages($id);
        $chapter->content = $utils->putInlineImagesToHTML($chapter->content, $images);

        $this->view->message = "The chapter <i>{$chapter->title}</i> was successful inserted";
        $this->view->message_type = 'success';
        $this->view->chapter = $chapter;
        $this->view->title = ($chapter->xtype=='CAPITULO'? "Chapter" : "Test") . ": {$chapter->title}";
        $this->view->breadcrumb = array(
            "admin" => "Admin",
            "school" => "School's courses",
            "schoolChapters?course={$chapter->course}" => "Course",
            "schoolChapter?id={$chapter->id}" => "Chapter"
        );
    }

    private function getChapterImages($chapter_id)
    {
        $connection = new Connection();
        $r = $connection->deepQuery("SELECT * FROM _escuela_images WHERE chapter = '$chapter_id';");
        $wwwroot = $this->di->get('path')['root'];
        $images = [];
        if ($r !== false)
        {
            foreach ($r as $row)
            {   $imageContent = file_get_contents($wwwroot."/public/courses/{$row->course}/$row->chapter/{$row->id}");
                $images[$row->id] = ['filename' => $row->filename, 'type' => $row->mime_type, 'content' => base64_encode($imageContent)];
            }
        }
        return $images;
    }
    /**
     * Manage test's questions and answers
     *
     * @author kuma
     */
    public function schoolQuestionsAction()
    {
        $this->setMenu('school');
        $connection = new Connection();
        $this->view->message = false;
        $this->view->message_type = 'success';

        $chapter = intval($this->request->get('chapter'));
        $r = $connection->deepQuery("SELECT * FROM _escuela_course WHERE _escuela_course.id = (SELECT course FROM _escuela_chapter WHERE _escuela_chapter.id = '$chapter');");
        $course = $r[0];
        $course_id = $course->id;

        $this->view->course = $course;
        $option = $this->request->get('option');
        $sql = false;

        if ($this->request->isPost()){

            switch($option){
                case "addQuestion":
                        $chapter = $this->request->getPost('chapter');
                        $title = $this->request->getPost('chapterQuestionTitle');
                        $r = $connection->deepQuery("SELECT max(xorder) as m FROM _escuela_question WHERE chapter = '$chapter';");
                        $order = $r[0]->m + 1;
                        $sql ="INSERT INTO _escuela_question (course, chapter, title, xorder) VALUES ('$course_id', '$chapter', '$title', '$order');";
                        $this->view->message = "Question <b>$title</b> was inserted successfull";
                break;
                case "setQuestion":
                        $question_id = $this->request->get('id');
                        $title = $this->request->getPost('chapterQuestionTitle');
                        $answer = $this->request->getPost('answer');
                        $sql = "UPDATE _escuela_question SET title = '$title', answer = $answer WHERE id = '$question_id';";
                        $this->view->message = "Question <b>$title</b> was updated successfull";
                        break;
                case "addAnswer":
                        $question_id = $this->request->get('question');
                        $title = $this->request->getPost('chapterAnswerTitle');
                        $sql ="INSERT INTO _escuela_answer (course, chapter, question, title) VALUES ('$course_id', '$chapter', '$question_id', '$title');";
                        $this->view->message = "Answer <b>$title</b> was inserted successfull";
                break;
                case "setAnswer":
                        $answer_id = $this->request->get('id');
                        $title = $this->request->getPost('chapterAnswerTitle');
                        $sql = "UPDATE _escuela_answer SET title = '$title' WHERE id = '$answer_id';";
                        $this->view->message = "The answer was updated successfull";
                break;
            }
        }

        switch($option)
        {
            case "delAnswer":
                $answer_id = $this->request->get('id');
                $sql = "DELETE FROM _escuela_answer WHERE id ='{$answer_id}'";
                $this->view->message = "The answer was deleted successfull";
            break;

            case "delQuestion":
                $question_id = $this->request->get('id');
                $sql = "START TRANSACTION;
                        DELETE FROM _escuela_question WHERE id = '{$question_id}';
                        DELETE FROM _escuela_answer WHERE question ='{$question_id}';
                        COMMIT;";
                $this->view->message = "The question was deleted successfull";
            break;
        }

        if ($sql!=false) $connection->deepQuery($sql);

        $chapter = $this->request->get('chapter');

        $r = $connection->deepQuery("SELECT * FROM _escuela_chapter WHERE id = '{$chapter};'");
        if ($r !== false) {
            $sql = "SELECT * FROM _escuela_question WHERE chapter = '$chapter' order by xorder;";
            $chapter = $r[0];
            $questions = $connection->deepQuery($sql);
            if ($questions !== false) {

                foreach ($questions as $k=>$q){
                    $answers = $connection->deepQuery("SELECT * FROM _escuela_answer WHERE question = '{$q->id}';");
                    if ($answers==false) $answers = array();
                    $questions[$k]->answers=$answers;
                }

                $this->view->title = "Test: ".$chapter->title;
                $this->view->chapter = $chapter;
                $this->view->questions = $questions;
            }
        }

        $this->view->breadcrumb = array(
            "admin" => "Admin",
            "school" => "School",
            "schoolChapters?course={$chapter->course}" => "Chapters",
            "schoolChapter/{$chapter->id}" => "Test",
            "schoolQuestions?chapter={$chapter->id}" => "Questions"
        );
    }

    public function testAction()
    {

    }
}
