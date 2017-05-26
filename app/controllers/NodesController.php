<?php

use Phalcon\Mvc\Controller;

class NodesController extends Controller
{
	// do not let anonymous users pass
	public function initialize(){
		$security = new Security();
		$security->enforceLogin();
	}

	/**
	 * Show output nodes and emails
	 * @author salvipascual
	 */
	public function indexAction()
	{
		// measure the effectiveness of each promoter
		$connection = new Connection();
		$nodes = $connection->query("
			SELECT * FROM nodes A JOIN nodes_output B
			ON A.`key` = B.node
			ORDER BY A.`name`");

		// format data for the view
		foreach ($nodes as $node) {
			$node->paused = empty($node->active) || strtotime($node->blocked_until) > strtotime(date('Y-m-d H:i:s'));
		}

		// get number of email in the queque
		$totalQuequed = $connection->query("
			SELECT COUNT(id) as total FROM delivery_received
			WHERE tries < 3 AND ((`status` = 'new' AND TIMESTAMPDIFF(MINUTE, inserted, NOW()) > 5)
			OR `status` = 'error')")[0]->total;

		// send data to the view
		$this->view->title = "Output emails";
		$this->view->nodes = $nodes;
		$this->view->totalQuequed = $totalQuequed;
		$this->view->currentNode = "";
		$this->view->setLayout('manage');
	}

	/**
	 * Show list of input emails
	 * @author salvipascual
	 */
	public function inputAction()
	{
		// measure the effectiveness of each promoter
		$connection = new Connection();
		$emails = $connection->query("SELECT * FROM nodes_input");

		// get number of email in the queque
		$totalQuequed = $connection->query("
			SELECT COUNT(id) as total FROM delivery_received
			WHERE tries < 3 AND ((`status` = 'new' AND TIMESTAMPDIFF(MINUTE, inserted, NOW()) > 5)
			OR `status` = 'error')")[0]->total;

		// send data to the view
		$this->view->title = "Input emails";
		$this->view->totalQuequed = $totalQuequed;
		$this->view->emails = $emails;
		$this->view->setLayout('manage');
	}

	/**
	 * Create or update an email inside a node
	 * @author salvipascual
	 */
	public function saveAction()
	{
		// empty params
		$email = $this->request->get("email");
		$node = ""; $user = ""; $pass = "";
		$host = "smtp.gmail.com";
		$limit = "50";
		$group = "apretaste";

		// get the list of nodes
		$connection = new Connection();
		$nodes = $connection->query("SELECT * FROM nodes ORDER BY name");

		// in case is it an update
		if($email) {
			$n = $connection->query("SELECT * FROM nodes_output WHERE email = '$email'");
			$node = $n[0]->node;
			$host = $n[0]->host;
			$user = $n[0]->user;
			$pass = $n[0]->pass;
			$limit = $n[0]->limit;
			$group = $n[0]->group;
		}

		// values for the view
		$this->view->title = "New email";
		$this->view->email = $email;
		$this->view->node = $node;
		$this->view->host = $host;
		$this->view->user = $user;
		$this->view->pass = $pass;
		$this->view->limit = $limit;
		$this->view->group = $group;
		$this->view->nodes = $nodes;
		$this->view->setLayout('manage');
	}

	/**
	 * Submit for the save action
	 * @author salvipascual
	 */
	public function saveSubmitAction()
	{
		// get params from the url
		$id = $this->request->get("id");
		$email = $this->request->get("email");
		$node = $this->request->get("node");
		$host = $this->request->get("host");
		$user = $this->request->get("user");
		$pass = $this->request->get("pass");
		$limit = $this->request->get("limit");
		$group = $this->request->get("group");

		// get the list of nodes
		$connection = new Connection();
		if($id) {
			$connection->query("UPDATE nodes_output SET
				email='$email', node='$node', host='$host', user='$user',
				pass='$pass', `limit`='$limit', `group`='$group'
				WHERE email='$id'");
		} else {
			$connection->query("INSERT INTO nodes_output (email, node, host, user, pass, `limit`, `group`)
				VALUES ('$email','$node','$host','$user','$pass','$limit','$group')");
		}

		// go to the list of nodes
		$this->response->redirect('nodes');
	}

	/**
	 * Save a new input email to the list
	 * @author salvipascual
	 */
	public function saveInputSubmitAction()
	{
		// get params from the url
		$email = $this->request->get("email");

		// get the list of nodes
		$connection = new Connection();
		$connection->query("INSERT INTO nodes_input (email) VALUES ('$email')");

		// go to the list of nodes
		$this->response->redirect('nodes/input');
	}

	/**
	 * Submit to activate an account
	 * @author salvipascual
	 */
	public function statusSubmitAction()
	{
		// get params from the url
		$email = $this->request->get("email");
		$status = $this->request->get("status");

		// get the list of nodes
		$connection = new Connection();
		$connection->query("UPDATE nodes_output SET active='$status', blocked_until=NULL, last_error=NULL WHERE email='$email'");

		// go to the list of nodes
		$this->response->redirect('nodes');
	}

	/**
	 * Delete an account
	 * @author salvipascual
	 */
	public function deleteSubmitAction()
	{
		// get params from the url
		$email = $this->request->get("email");

		// get the list of nodes
		$connection = new Connection();
		$connection->query("DELETE FROM nodes_output WHERE email='$email'");

		// go to the list of nodes
		$this->response->redirect('nodes');
	}

	/**
	 * Delete an input email
	 * @author salvipascual
	 */
	public function deleteInputSubmitAction()
	{
		// get params from the url
		$email = $this->request->get("email");

		// get the list of nodes
		$connection = new Connection();
		$connection->query("DELETE FROM nodes_input WHERE email='$email'");

		// go to the list of nodes
		$this->response->redirect('nodes/input');
	}

	/**
	 * Show emails waiting in the queque to be re-send
	 * @author salvipascual
	 */
	public function quequeAction()
	{
		// measure the effectiveness of each promoter
		$connection = new Connection();
		$emails = $connection->query("
			SELECT * FROM delivery_received
			WHERE tries < 3
			AND ((`status` = 'new' AND TIMESTAMPDIFF(MINUTE, inserted, NOW()) > 5)
			OR `status` = 'error')
			ORDER BY inserted ASC");

		// get the total of emails
		$total = count($emails);

		// send data to the view
		$this->view->title = "Waiting queque ($total)";
		$this->view->emails = $emails;
		$this->view->setLayout('manage');
	}

	/**
	 * Remove an email from the queque
	 * @author salvipascual
	 */
	public function sendFromQuequeSubmitAction()
	{
		// get params from the url
		$id = $this->request->get("id");

		// get all details about the email
		$connection = new Connection();
		$res = $connection->query("SELECT * FROM delivery_received WHERE id='$id'");

		// run the request and get the service and responses
		$utils = new Utils();
		$attachEmail = explode(",", $res[0]->attachments);
		$ret = $utils->runRequest($res[0]->user, $res[0]->subject, $res[0]->body, $attachEmail);
		$service = $ret->service;
		$responses = $ret->responses;

		// create the new Email object
		$email = new Email();
		$email->id = $res[0]->id;
		$email->to = $res[0]->user;
		$email->replyId = $res[0]->messageid;
		$email->group = $service->group;

		// render the body and send the response emails
		$render = new Render();
		foreach($responses as $rs)
		{
			if($rs->email) $email->to = $rs->email;
			$email->subject = $rs->subject;
			$email->images = $rs->images;
			$email->attachments = $rs->attachments;
			$email->body = $render->renderHTML($service, $rs);
			$email->send();
		}

		// go to the list of nodes
		$this->response->redirect('nodes/queque');
	}

	/**
	 * Remove an email from the queque
	 * @author salvipascual
	 */
	public function removeFromQuequeSubmitAction()
	{
		// get params from the url
		$id = $this->request->get("id");

		// block the email so it won't try to send it again
		$connection = new Connection();
		$connection->query("UPDATE delivery_received SET `status`='block' WHERE id='$id'");

		// go to the list of nodes
		$this->response->redirect('nodes/queque');
	}
}
