<?php
if (!defined('_PS_VERSION_')) {
	exit;
}

class InstapunchoutPunchoutModuleFrontController extends ModuleFrontController
{
	/**
	 * @see FrontController::initContent()
	 */
	public function initContent()
	{
		$action = '';

		if (isset($_GET['action'])) {
			$action = $_GET['action'];
		}
		try {
			if ($action == 'options.json') {
				$this->options();
			} else if ($action == 'script') {
				$this->script();
			} else if ($action == 'message') {
				$this->message();
			} else if ($action == 'order.json') {
				$token = $_GET['token'];
				$res = $this->post('https://punchout.cloud/authorize', ["authorization" => $token]);
				if ($res["authorized"] != true) {
					echo json_encode(["error" => "You're not authorized"]);
					exit;
				}
				$new_order = json_decode(file_get_contents('php://input'), true);
				$this->create_order($new_order);
			} else if ($action == 'cart.json') {
				echo json_encode($this->get_cart());
				exit;
			} else {
				// no need for further sanization as we need to capture all the server data as is
				$server = json_decode(json_encode($_SERVER), true);
				// no need for further sanization as we need to capture all the query data as is
				$query = json_decode(json_encode($_GET), true);
				$data = array(
					'headers' => ['method' => 'GET'],
					'server' => $server,
					'body' => file_get_contents('php://input'),
					'query' => $query,
				);

				$res = $this->post('https://punchout.cloud/proxy', $data);
				if ($res['action'] == 'print') {
					header('content-type: application/xml');
					$xml = new SimpleXMLElement($res['body']);
					echo $xml->asXML();
				} else if ($res['action'] == 'login') {
					// logout existing user
					if ($this->context->customer->isLogged()) {
						$id_customer = $this->context->cookie->id_customer;
						$customer = new Customer($id_customer);
						$customer->logout();
					}

					$customer = $this->prepare_customer($res);
					$this->setCustomerAsLoggedIn($customer, $res['punchout_id']);
					header('Location: /');
					exit;
				}
			}
		} catch (Exception $e) {
			echo var_dump($e);
			return;
		}
	}

	private function prepare_customer($data)
	{
		Hook::exec('actionBeforeAuthentication');
		$customer = new Customer();
		$exists = $customer->getByEmail($data['email'], null);

		if (!$exists) {
			// create new customer
			$customer->firstname = $data['firstname'];
			$customer->lastname = $data['lastname'];
			$customer->email = $data['email'];
			$customer->passwd = md5(time() . _COOKIE_KEY_);
			$customer->is_guest = 0;
			$customer->active = 1;

			$customer->add();

			$customer->cleanGroups();

			// we add the guest customer in the default customer group
			$customer->addGroups(array((int) Configuration::get('PS_CUSTOMER_GROUP')));

		}
		$customer = new Customer();

		$exists = $customer->getByEmail($data['email'], null);

		return $customer;
	}


	private function prepare_address($data, $customer)
	{
		$id_country = Country::getByIso($data['country']);
		if (!$id_country) {
			echo "Error: country not found " . $data['country'];
			exit;
		}
		$id_state = State::getIdByIso($data['state']);
		if (!$id_state) {
			echo "Error: state not found " . $data['state'];
			exit;
		}
		$addresses = $customer->getAddresses($this->context->language->id);
		// find address in addresses where alias matches
		$address = null;
		foreach ($addresses as $a) {
			if ($a['alias'] == $data['alias']) {
				$address = $a;
				break;
			}
		}
		// if address is null then create new prestashop address from new_order dynamically
		// assign fields dynamically using $address->$key = $value
		// then add address to customer
		// then set $address->id to $id_address
		if ($address == null) {
			$address = new Address();
			foreach ($data as $key => $value) {
				$address->$key = $value;
			}
			$address->id_country = $id_country;
			$address->id_state = $id_state;
			$address->id_customer = $customer->id;
			$address->add();
			return $address;
		} else {
			$address = new Address($address['id_address']);
			// if address exists update it with new_order
			foreach ($data as $key => $value) {
				$address->$key = $value;
			}
			$address->id_country = $id_country;
			$address->id_state = $id_state;

			$address->update();
			return $address;
		}
	}

	private function options()
	{
		return [
			"carriers" => Carrier::getCarriers($this->context->language->id, true, false, false, null, Carrier::ALL_CARRIERS),
			"order_states" => OrderState::getOrderStates($this->context->language->id),

		];
	}

	private function create_order($new_order)
	{

		$customer = $this->prepare_customer($new_order["customer"]);

		$order = new Order();
		$order->id_shop = (int) $this->context->shop->id;
		$order->id_shop_group = (int) $this->context->shop->id_shop_group;


		$order->id_customer = (int) $customer->id;

		$shipping_address = $this->prepare_address($new_order["shipping"], $customer);
		$order->id_address_delivery = $shipping_address->id;

		$billing_address = $this->prepare_address($new_order["billing"], $customer);
		$order->id_address_invoice = $billing_address->id;

		$currency_id = Currency::getIdByIsoCode($new_order['currency']);
		$order->id_currency = (int) $currency_id;

		// Get the cart
		$cart = new Cart();
		$cart->id_currency = $currency_id;
		$cart->id_customer = $customer->id;
		$cart->id_address_delivery = $shipping_address->id;
		$cart->id_address_invoice = $billing_address->id;
		$cart->add();

		// Add the order details to the cart
		$orderDetailsData = $new_order['order_details'];
		foreach ($orderDetailsData as $orderDetailData) {
			// $product = new Product($orderDetailData['id_product'], false, $this->context->language->id);
			$cart->updateQty($orderDetailData['quantity'], $orderDetailData['id_product'], $orderDetailData['id_product_attribute'], false, 'up', 0, null, false);
			// $price = $orderDetailData['price'];
			//v$cart->updatePrice($price, $orderDetailData['id_product'], $orderDetailData['id_product_attribute'], null, null, '', null, false);
		}

		$cart->update();

		$this->context->cart = $cart;

		$order->id_lang = (int) $this->context->cart->id_lang;
		$order->id_cart = $cart->id;

		$order->payment = 'Purchase Order';
		$order->module = 'manual';


		foreach ($new_order['order'] as $key => $value) {
			$order->$key = $value;
		}

		$order->secure_key = md5(uniqid(rand(), true)); // pSQL($this->context->customer->secure_key);
		$order->product_list = $cart->getProducts();

		$result = $order->add();

		if (!$result) {
			throw new PrestaShopException('Can\'t save Order');
		}

		// Insert new Order detail list using cart for the current order
		$order_detail = new OrderDetail(null, null, $this->context);
		$order_detail->createList($order, $cart, $order->current_state, $order->product_list, 0, true, 1);

		header('Content-Type: application/json');
		echo json_encode($order);
		exit;

	}


	/**
	 * Updates customer in the context, updates the cookie and writes the updated cookie.
	 *
	 * @param Customer $customer Created customer
	 */
	public function updateCustomer(Customer $customer)
	{
		$this->context->cookie->id_compare = isset($this->context->cookie->id_compare) ? $this->context->cookie->id_compare : CompareProduct::getIdCompareByIdCustomer($customer->id);
		$this->context->cookie->id_customer = (int) ($customer->id);
		$this->context->cookie->customer_lastname = $customer->lastname;
		$this->context->cookie->customer_firstname = $customer->firstname;
		$this->context->cookie->logged = 1;
		$customer->logged = 1;
		$this->context->cookie->is_guest = $customer->isGuest();
		$this->context->cookie->passwd = $customer->passwd;
		$this->context->cookie->email = $customer->email;

		// Add customer to the context
		$this->context->customer = $customer;

		if (Configuration::get('PS_CART_FOLLOWING') && (empty($this->context->cookie->id_cart) || Cart::getNbProducts($this->context->cookie->id_cart) == 0) && $id_cart = (int) Cart::lastNoneOrderedCart($this->context->customer->id)) {
			$this->context->cart = new Cart($id_cart);
		} else {
			$id_carrier = (int) $this->context->cart->id_carrier;
			$this->context->cart->id_carrier = 0;
			$this->context->cart->setDeliveryOption(null);
			$this->context->cart->id_address_delivery = (int) Address::getFirstCustomerAddressId((int) ($customer->id));
			$this->context->cart->id_address_invoice = (int) Address::getFirstCustomerAddressId((int) ($customer->id));
		}
		$this->context->cart->id_customer = (int) $customer->id;
		$this->context->cart->secure_key = $customer->secure_key;

		if ($this->ajax && isset($id_carrier) && $id_carrier && Configuration::get('PS_ORDER_PROCESS_TYPE')) {
			$delivery_option = array($this->context->cart->id_address_delivery => $id_carrier . ',');
			$this->context->cart->setDeliveryOption($delivery_option);
		}

		$this->context->cart->save();
		$this->context->cookie->id_cart = (int) $this->context->cart->id;
		$this->context->cookie->write();
		$this->context->cart->autosetProductAddress();

	}

	private function setCustomerAsLoggedIn($customer, $punchout_id)
	{
		if (!$punchout_id) {
			die("Missing punchout_id");
		}
		Hook::exec('actionBeforeAuthentication');
		// set the user as logged in

		if (method_exists($this->context, 'updateCustomer')) {
			$this->context->updateCustomer($customer);
		} else {
			$this->updateCustomer($customer);
		}
		Hook::exec('actionAuthentication', ['customer' => $this->context->customer]);
		// reset cart
		CartRule::autoRemoveFromCart($this->context);
		CartRule::autoAddToCart($this->context);
		// save punchout_id in cookie
		$this->context->cookie->punchout_id = $punchout_id;
		$this->context->cookie->write();

	}

	// pull script from punchout.cloud
	private function script()
	{
		header("Cache-Control: no-cache");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");
		header('Content-Type: text/javascript');
		if (!$this->context->cookie->punchout_id) {
			exit;
		}
		$url = 'https://punchout.cloud/punchout.js?id=' . $this->context->cookie->punchout_id;
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_URL, $url);
		$res = curl_exec($ch);
		curl_close($ch);
		echo $res;
		exit;
	}

	private function get_cart()
	{
		return [
			"currency" => $this->context->currency->iso_code,
			"items" => $this->context->cart->getProducts()
		];
	}

	private function message()
	{
		if (!$this->context->cookie->punchout_id) {
			echo "no punchout id";
			exit;
		}

		$custom = json_decode(file_get_contents('php://input'), true);

		$body = ['cart' => ['Prestashop' => $this->get_cart()], 'custom' => $custom];
		$res = $this->post('https://punchout.cloud/cart/' . $this->context->cookie->punchout_id, $body);

		$products = $this->context->cart->getProducts();
		foreach ($products as $product) {
			$this->context->cart->deleteProduct($product["id_product"]);
		}

		header('Content-Type: application/json');
		echo json_encode($res);
		exit;
	}

	/**
	 * @todo send request to api url
	 * @param string $url
	 * @param array $data
	 * @param string $format
	 * @param string $response
	 * @return string|mixed
	 */
	private function post($url, $data = null, $format = 'json', $response = 'json')
	{
		$headers = [
			'Accept: application/' . $response,
			'Content-Type: application/' . $format,
		];
		$handle = curl_init();
		curl_setopt($handle, CURLOPT_URL, $url);
		curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($handle, CURLOPT_POST, true);
		curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($data));
		$res = curl_exec($handle);

		curl_close($handle);
		if ($response == 'json') {
			$res = json_decode($res, true);
		}
		return $res;
	}
}