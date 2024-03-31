<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Safepay_Gateway_Blocks_Support extends AbstractPaymentMethodType
{

	/**
	 * The gateway instance.
	 *
	 * @var WC_Safepay_Gateway
	 */
	private $gateway;
	private $icon;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'safepay_gateway';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize()
	{

		$gateways = WC()->payment_gateways->payment_gateways();
		$this->gateway = $gateways[$this->name];

		$plugin_image_url = plugins_url("/assets/image/logo.png", 'safepay-plugin');

		$this->settings = [
			'title' => $this->gateway->get_option('title'),
			'icon' => $plugin_image_url,
			'description' => $this->gateway->get_option('description'),
			'supports' => array_filter($this->gateway->supports, [$this->gateway, 'supports'])
		];
	}
	// 'icon' => 'https://uploads-ssl.webflow.com/5f2d8cefa1f1ec3622a82394/5f2d8cefa1f1ec33a5a829e3_5f1001c874b19d21f44da1e2_safepay_logo.svg',
	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active()
	{
		return $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles()
	{
		$script_path = '/assets/js/frontend/blocks.js';
		$script_asset_path = WC_SafePay_Payments::plugin_abspath() . 'assets/js/frontend/blocks.asset.php';
		$script_asset = file_exists($script_asset_path)
			? require ($script_asset_path)
			: array(
				'dependencies' => [
					'wc-settings'
				],
				'version' => '1.2.0'
			);
		$script_url = WC_SafePay_Payments::plugin_url() . $script_path;

		wp_register_script(
			'wc-safepay-payments-blocks',
			$script_url,
			[
				'wc-settings'
			],
			$script_asset['version'],
			true
		);

		if (function_exists('wp_set_script_translations')) {
			wp_set_script_translations('wc-safepay-payments-blocks', ' woocommerce-safepay-gateway', WC_SafePay_Payments::plugin_abspath() . 'languages/');
		}

		return ['wc-safepay-payments-blocks'];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data()
	{
		// 'icon' => 'https://uploads-ssl.webflow.com/5f2d8cefa1f1ec3622a82394/5f2d8cefa1f1ec33a5a829e3_5f1001c874b19d21f44da1e2_safepay_logo.svg',
		$plugin_image_url = plugins_url("/safepay-plugin/assets/images/card.svg", 'safepay-plugin');
		return [
			'title' => $this->get_setting('title'),
			'description' => $this->get_setting('description'),
			'icon' => $plugin_image_url,
			'supports' => array_filter($this->gateway->supports, [$this->gateway, 'supports'])
		];
	}
}
