<?php

/**
 *
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
	return;
}

class WC_SafePay_Response
{

	protected $gateway;


	public function __construct($gateway)
	{
		$this->gateway = $gateway;
	}

	public function order_placement_routine($sandbox = false)
	{

		$redirect = isset($_REQUEST['redirect']) ? $_REQUEST['redirect'] : '';
		$basketid = isset($_REQUEST['basket_id']) ? $_REQUEST['basket_id'] : '';
		$order_id = isset($_REQUEST['order_id']) ? $_REQUEST['order_id'] : '';
		$safepay_message = isset($_REQUEST['err_msg']) ? $_REQUEST['err_msg'] : '';
		$safepay_transactionid = $transaction_id = isset($_REQUEST['transaction_id']) ? $_REQUEST['transaction_id'] : '';
		$safepay_statuscode = isset($_REQUEST['err_code']) ? $_REQUEST['err_code'] : '';
		$validation_hash = isset($_REQUEST['validation_hash']) ? trim($_REQUEST['validation_hash']) : '';


		$hashValidated = $this->validateHash($validation_hash, $basketid, $safepay_statuscode);
		if (!$hashValidated) {
			if ($redirect !== 'Y') {
				header($_SERVER['SERVER_PROTOCOL'] . ' 401 - Unauthorized Order', true, 404);
				header('HTTP/1.0 401 Unauthorized Order');
				echo "Unauthorized Order (Invalid Response Key)";
				return;
			}

			wc_add_notice('Order could not be authorized.', 'error');
			wp_redirect(wc_get_checkout_url());
			return;
		}

		$order = wc_get_order((int) $order_id);
		$order_id = $order->get_id();

		if (!$order) {
			if ($redirect !== 'Y') {
				header($_SERVER['SERVER_PROTOCOL'] . ' 404 - Order Not Found/Invalid Order ID', true, 404);
				header('HTTP/1.1 200 OK');
				echo "Order Not Found/Invalid Order ID";
				return;
			}

			wc_add_notice('Order not found. Invalid Order ID received.', 'error');
			wp_redirect(get_site_url());
			return;
		}

		$notificationMethod = '';

		if ($redirect !== 'Y') {
			$notificationMethod = 'IPN';
		} else {
			$notificationMethod = 'Redirecttion';
		}

		$order->update_meta_data('Payment_notification', $notificationMethod);

		$current_status = $order->get_status();

		if ($current_status == 'payment-received' || $current_status == 'wc-processing' || $current_status == 'wc-complete' || $current_status == 'wc-on-hold') {
			if ($redirect == "Y") {
				wc_add_notice('Could not update this order. Its already in processing state.', 'error');
				wp_redirect(get_site_url());
				return;
			}
			header('HTTP/1.1 200 OK');
			echo "Forbidden  (Order Already Updated)";
			return;
		}

		do_action('woocommerce_payment_complete', $order_id);

		if ($safepay_statuscode === "000") {

			$order->update_status('wc-payment-received', __('Payment received, your order is currently being processed.'));
			$message = __('Payment received.<br />Your order is currently being processed.', 'woocommerce-safepay-gateway');
			$message_type = 'success';

			$order->add_order_note(__('Payment Received.<br />Your order is currently being processed.<br />SafePay Transaction ID: ', 'woocommerce-safepay-gateway') . $safepay_transactionid, 1);
			$order->add_order_note(__('Payment Via SafePay Payment Gateway<br />Transaction ID: ', 'woocommerce-safepay-gateway') . $transaction_id);

			wc_reduce_stock_levels($order_id);


			WC()->cart->empty_cart();

			$order->update_meta_data('safepay_transaction_id', $safepay_transactionid);
			$order->update_meta_data('safepay_status_message', $safepay_message);
			$order->update_meta_data('safepay_status_code', $safepay_statuscode);
			$order->update_meta_data('safepay_gateway_transaction_success', 'SUCCESS');

			$order->save();

			$safepay_message = array(
				'message' => $message,
				'message_type' => $message_type
			);

			update_post_meta($order_id, 'safepay_txn_completed', $safepay_message);


			if ($redirect == "Y") {
				wp_redirect($this->gateway->get_return_url($order));
				return;
			}

			header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK', true, 200);
			return;
		}

		$order->update_status('failed', __('Payment transaction failed. Your order was not successfull.'));

		$message = __('Payment transaction failed. Your order was not successfull.', 'woocommerce-safepay-gateway');
		$message_type = 'failed';

		$order->add_order_note(__('Payment transaction failed. Your order was not successfull..<br />SafePay Transaction ID: ', 'woocommerce-safepay-gateway') . $safepay_transactionid, 1);

		$order->add_order_note(__('Payment transaction failed. <br>' . $safepay_message . ' <br />Transaction ID: ', 'woocommerce-safepay-gateway') . $transaction_id);

		update_post_meta($order_id, '_safepay_gateway_message', $safepay_message);


		$order->update_meta_data('safepay_transaction_id', $safepay_transactionid);
		$order->update_meta_data('safepay_status_message', $safepay_message);
		$order->update_meta_data('safepay_status_code', $safepay_statuscode);
		$order->update_meta_data('safepay_gateway_transaction_success', 'FAILED');
		$order->save();

		if ($redirect == "Y") {
			wc_add_notice('Payment was unsuccessfull. Please contact merchant for more information.', 'error');
			wp_redirect($order->get_cancel_order_url());
			return;
		}

		header($_SERVER['SERVER_PROTOCOL'] . ' 200 OK', true, 200);

		return;
	}

	private function validateHash($validation_hash, $order_id, $err_code)
	{
		$merchantId = $this->gateway->merchantId;
		$secretKey = $this->gateway->securedKey;

		$protocol = sprintf(
			"%s|%s|%s|%s",
			$order_id,
			$secretKey,
			$merchantId,
			$err_code
		);

		$hash = hash('sha256', $protocol);
		return $hash == $validation_hash;
	}
}
