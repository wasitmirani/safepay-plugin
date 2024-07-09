<?php

/**
 *
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	exit;
}



class WC_SafePay_Request
{

	protected $gateway;
	protected $notify_url;
	private $order;
	private $orderId;
	public $responseCallBackUrl;

	public function __construct($gateway, $orderId)
	{
		$this->gateway = $gateway;
		$this->orderId = $orderId;
		$this->order = wc_get_order($orderId);
	}

	private function get_payment_url($sandbox = false)
	{
		$payment_url = sprintf("%s/Ecommerce/api/Transaction/PostTransaction", $this->gateway->baseUrl);
		return $payment_url;
	}

	public function generate_safepay_form()
	{
		$safepay_args = $this->get_payment_parameters($this->order);
		$safepay_form[] = '<form action="' . esc_url($this->get_payment_url()) . '" method="post" id="safepay_payment_form" name="safepay_payment_form">';

		foreach ($safepay_args as $key => $value) {
			$safepay_form[] = sprintf('<input type="hidden" name="%s" value="%s" />', esc_attr($key), esc_attr($value));
		}

		$safepay_form[] = '</form>';

		$safepay_form[] = '<SCRIPT language="javascript">window.onload=function(){document.forms["safepay_payment_form"].submit();}</SCRIPT>';
		return implode('', $safepay_form);
	}

	private function get_payment_parameters($order)
	{
		$token = $this->safepay_payment_token($order->get_total(), $order->get_order_number());

		if (!$token) {
			wc_add_notice('Payment gateway could not be connected. Please contact merchant.');
			wp_redirect(wc_get_checkout_url());
			die();
		}

		$site_url = sprintf("/checkout/order-received/%s?", get_site_url(), $this->responseCallBackUrl);

		$successUrl = $site_url;
		$successUrl .= "redirect=Y&order_id=" . $order->get_id();

		$failUrl = $site_url;
		$failUrl .= "redirect=Y&order_id=" . $order->get_id();

		$backend_callback = $site_url;
		$backend_callback .= "order_id=" . $order->get_id();

		$orderDate = date('Y-m-d H:i:s', time());

		$order->update_meta_data(
			"safepay_Store_ID",
			$this->gateway->storeId
		);

		$order->update_meta_data(
			"Order_Date",
			$orderDate
		);

		$order->update_status('wc-pending');

		$order->save();

		$signature = hash('sha256', $order->get_id());

		$payload = array(
			'MERCHANT_ID' => $this->gateway->merchantId,
			'env' => $this->gateway->app_env,
			'beacon' => $token,
			'mode' => 'payment-raw',
			'amount' => $order->get_total(),
			'CUSTOMER_MOBILE_NO' => $order->get_billing_phone(),
			'CUSTOMER_EMAIL_ADDRESS' => $order->get_billing_email(),
			'redirect_url' => urlencode($successUrl),
			'cancel_url' => urlencode($failUrl),
			'order_id' => $order->get_order_number(),
			'order_date' => $orderDate,
			'CHECKOUT_URL' => urlencode($backend_callback),
			'STORE_ID' => $this->gateway->storeId,
			'currency' => get_woocommerce_currency()
		);

		return $payload;
	}

	private function safepay_payment_token($totalAmount, $basketId)
	{
		$tokenUrlParams = sprintf(
			"MERCHANT_ID=%s&SECURED_KEY=%s&TXNAMT=%s&BASKET_ID=%s",
			$this->gateway->merchantId,
			$this->gateway->securedKey,
			$totalAmount,
			$basketId
		);

		return $this->get_safepay_auth_token($tokenUrlParams);
	}

	private function get_safepay_auth_token($urlParams)
	{
		$baseUrl = $this->gateway->baseUrl;

		$token_url = sprintf("%s/Ecommerce/api/Transaction/GetAccessToken", $baseUrl);
		$response = $this->curl_request($token_url, $urlParams);
		$response_decode = json_decode($response);
		if (isset($response_decode->ACCESS_TOKEN)) {
			return $response_decode->ACCESS_TOKEN;
		}

		return null;
	}

	private function curl_request($url, $urlParams)
	{

		$uniqueId = uniqid(time(), true);
		$xRequestId = sprintf("X-Request-ID: %s", hash('sha256', $uniqueId));
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $urlParams);
		curl_setopt($ch, CURLOPT_POST, 1);

		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-type: application/x-www-form-urlencoded; charset=utf-8',
			$xRequestId
		]);
		curl_setopt($ch, CURLOPT_USERAGENT, 'SafePay Payment Plugin/2.0 (WooCommerce Block Checkout) WordPress');
		$response = curl_exec($ch);
		curl_close($ch);
		return $response;
	}
}
