<?php
use CardstreamPayment\Components\CardstreamPayment\PaymentResponse;
use CardstreamPayment\Components\CardstreamPayment\CardstreamPaymentService;
use Shopware\Components\CSRFWhitelistAware;

class Shopware_Controllers_Frontend_CardstreamPaymentCtrl
	extends Shopware_Controllers_Frontend_Payment
	implements CSRFWhitelistAware
{
	const PAYMENTSTATUSPAID = 12;
	// Gateway URL
	public $url = "https://gateway.cardstream.com/hosted/";

	public function indexAction()
	{
				/**
				* Check if Cardstream Payment is selected. Else return to default controller.
				*/
				switch ($this->getPaymentShortName()) {
					case 'payment_cardstream':
					return $this->redirect(['action' => 'direct', 'forceSecure' => true]);
					default:
					return $this->redirect(['controller' => 'checkout']);
				}
			}


		/**
		* Whitelist callback from csrf token checks
		*/
		public function getWhitelistedCSRFActions()
		{
			return ['return'];
		}


		/**
		 * Direct action method.
		 *
		 * Collects the payment information and transmit it to the payment provider.
		 */
		public function directAction()
		{
			$settings = Shopware()
			->Container()
			->get('shopware.plugin.config_reader')
			->getByPluginName('CardstreamPayment');

			$router = $this->Front()->Router();
			$user = $this->getUser();
			$basket = $this->getBasket();
			$billing = $user['billingaddress'];
			$key = trim($settings['sigKey']);

			//Prepare the request with required fields
			$req = array_filter(array(
				'merchantID'        => $settings['merchantID'],
				'action'            => 'SALE',
				'type'              => 1,
				'countryCode'       => 826,
				'currencyCode'      => 826,
				'amount'            => $this->getAmount() * 100,
				'token'             => $this->createPaymentToken(
					$this->getAmount(),
					$billing['customernumber']
				),
				'customerName'      => implode(" ",
					array(
						ucfirst($billing["salutation"]),
						$billing['firstname'],
						$billing['lastname']
					)
				),
				'customerAddress'   => implode(
					", \n",
					explode(
						', ', $billing['street'] . ', ' . $billing['city']
					)
				),
				'customerPostcode'  => $billing['zipcode'],
				'customerEmail'     => $user['additional']['user']['email'],
				'customerPhone'     => $billing['phone'],
				'transactionUnique' => uniqid(),
				'redirectURL'       => $router->assemble(
					array(
						'action' => 'return',
						'forceSecure' => true
					)
				),
				'callbackURL'       => $router->assemble(
					array(
						'action' => 'return',
						'forceSecure'=> true
						)
				),
				'__csrf_token'      => $_COOKIE['__csrf_token-1'],
				'threeDSCheckPref'  => 'authenticated',
				'sessionId'         => Shopware()->Session()->offsetGet('sessionId')
			));

			// Sign the request using the signature key defined above
			$req['signature'] = $this->createSignature($req, $key);

			// Populate and automatically submit POST request form
			echo '<a>Please wait while you are redirected...</a>';
			echo '<form action="' . $this->url . '" method="post" id="reqForm">' . PHP_EOL;
			foreach ($req as $field => $value) {
				echo ' <input type="hidden" name="' . $field . '" value="' . htmlentities($value) . '">' . PHP_EOL;
			}
			echo '</form>' . PHP_EOL;
			echo '<script type="text/javascript">';
			echo 'document.getElementById("reqForm").submit();';
			echo '</script>';
			die();
		}

 		// Redirect to the cart page upon any failure
		public function cancelAction()
		{
			die("Transaction Cancelled");
			return $this->redirect(['controller'=>'checkout' , 'action'=>'cart']);
		}



		/**
		 * Return action method.
		 *
		 * Deals with the response from the gateway through both the callback and redirect URLs.
		 * Validates the request and then comfirms the order or redirects as necessary
		 */
		public function returnAction()
		{
			$settings = Shopware()
			->Container()
			->get('shopware.plugin.config_reader')
			->getByPluginName('CardstreamPayment');
			$key = trim($settings['sigKey']);
			$router = $this->Front()->Router();
			$res = $_POST;

			/*
			 * Initialise signature as the response signature (if it exists) or
			 * null otherwise and unset the signature field of the response ready for rehashing
			 */
			$signature = null;
			if (isset($res['signature'])) {
				$signature = $res['signature'];
				unset($res['signature']);
			}
			// verify the signatures
			if (!$signature || $signature != $this->createSignature($res, $key)) {
				return $this->redirect(['controller'=>'checkout' , 'action'=>'cart']);
			}


			// Load the session from the SessionId in the response from the gateway
			session_write_close();
			session_id($_POST['sessionId']);
			session_start();
			Shopware()->Session()->resetSingleInstance();


			$user = $this->getUser();
			$billing = $user['billingaddress'];

			// Check order value and customerID token against response
			$token = $this->createPaymentToken($this->getAmount(), $billing['customernumber']);
			if (!$this->isValidToken($res["token"], $token)) {
				$this->forward('cancel');
				// The session has expired or is invalid
				//die('Invalid Token error. Click <a href="' . $router->assemble(['controller' => 'checkout', 'action' => 'cart', 'forceSecure' => true]) .'">here</a> to return to cart');
				return $this->redirect(['controller' => 'checkout', 'action' => 'cart']);
			}

			// Check the response code
			if ($res['responseCode'] === "0") {
				// If payment successfull then save order to database
				echo "<p>Thank you for your payment.</p>";
				echo "<p>You will be redirected shortly...</p>";
				$this->saveOrder(
					$res['transactionID'],
					$res['transactionUnique'],
					SELF::PAYMENTSTATUSPAID
					);
				// redirect to finish page (final saving of order happens there)
				return $this->redirect(['controller' => 'checkout', 'action' => 'finish', 'sUniqueID' => $_COOKIE['session-1']]);
			} else {

				// if the order fails then tell the user and go back to card
				echo "<p>Failed to take payment: " . htmlentities($res['responseMessage']) .
				"</p>";
				return $this->redirect(['controller'=>'checkout','action'=>'cart']);
			}
		}


		// Function to create a message signature
		public function createSignature(array $data, $key)
		{
			// Sort by field name
			ksort($data);
			// Create the URL encoded signature string
			$ret = http_build_query($data, '', '&');
			// Normalise all line endings (CRNL|NLCR|NL|CR) to just NL (%0A)
			$ret = str_replace(array('%0D%0A', '%0A%0D', '%0D'), '%0A', $ret);
			// Hash the signature string and the key together
			return hash('SHA512', $ret . $key);
		}

		public function isValidToken($restoken, $token)
		{
			return hash_equals($token, $restoken);
		}

		public function createPaymentToken($amount, $customerId)
		{
			return md5(implode('|', [$amount, $customerId]));
		}
	}
