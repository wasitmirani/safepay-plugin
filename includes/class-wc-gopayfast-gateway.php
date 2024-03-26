<?php

if (!defined('ABSPATH')) {
    exit;
}

class WC_Safepay_Gateway extends WC_Payment_Gateway
{

    /**
     * Payment gateway instructions.
     * @var string
     *
     */
    protected $instructions;

    /**
     * Whether the gateway is visible for non-admin users.
     * @var boolean
     *
     */
    protected $hide_for_non_admin_users;

    /**
     * Unique id for the gateway.
     * @var string
     *
     */
    public $id = 'safepay_gateway';

    private $siteUrl;

    public $merchantId;

    public $securedKey;

    public $storeId;

    public $baseUrl;

    private $processRequestPayment = false;

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {

        $this->icon               = apply_filters('woocommerce_safepay_gateway_icon', '');
        $this->has_fields         = false;
        $this->supports           = array(
            'products'
        );

        $this->method_title       = _x('SafePay', 'SafePay Gateway', 'woocommerce-safepay-gateway');
        $this->method_description = __('Pay via Credit/Debit Cards, Bank Accounts/Wallets and RAAST', 'woocommerce-safepay-gateway');

        $this->init_form_fields();
        $this->init_settings();

        $this->title                    = $this->get_option('title');
        $this->description              = $this->get_option('description');
        $this->instructions             = $this->get_option('instructions', $this->description);
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users');

        $this->merchantId = $this->get_option('merchant_id');
        $this->securedKey = $this->get_option('security_key');
        $this->storeId = $this->get_option('store_id');
        $this->baseUrl = $this->get_option('base_url');

        $this->siteUrl = get_site_url();

        add_filter('woocommerce_gateway_icon', array($this, 'safepay_display_woocommerce_icons'), 10, 2);


        add_filter('woocommerce_gateway_title', array($this, 'payment_method_title'), 10, 2);

        add_action('woocommerce_order_status_changed', array($this, 'custom_update_order_status'), 10, 3);


        add_action('woocommerce_receipt_' . $this->id, array($this, 'process_order_request'));

        /**
         * action to process admin configs for the plugins
         */
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

        /**
         * action process gateway redirection
         */
        add_action('woocommerce_api_safepay_request_redirect', array($this, 'gateway_request'));

        /**
         * action process gateway response url/ipn callback
         */
        add_action('woocommerce_api_safepay_gatewaycallback', array($this, 'payment_notification'));

        /**
         * processing order
         */
        add_action('wp', array($this, 'process_order_place'));
    }

    function safepay_display_woocommerce_icons($icon, $id)
    {
        
        if ($id == 'safepay_gateway') {
            $imagePath = sprintf("%s/assets/images/logo.png", plugin_dir_url(dirname(__FILE__)));
            $icon  = '<img width="25%" src="' . $imagePath . '" alt="safepay" />';
        }
        return $icon;
    }

    function  payment_method_title($title, $id)
    {

        if ($this->id === $id) {
            $title = $this->title;
        }

        return $title;
    }

    function process_order_request()
    {
        exit;
    }

    function my_custom_temporary_page_endpoint()
    {
        add_rewrite_endpoint('my-temp-page', EP_ROOT | EP_PAGES);
    }

    public function process_order_place()
    {
        if (get_query_var('order-received')) {
            $OrderId = absint(get_query_var('order-received'));
            $order = wc_get_order($OrderId);
            $order->delete_meta_data('safepay_gateway_transaction_success');

            if ($order->get_status() === 'payment-received') {
                return array(
                    'result'     => 'success',
                    'redirect'    => $this->get_return_url($order)
                );
            }

            if ($order->get_status() === 'failed') {
                return array(
                    'result'     => 'failed',
                    'redirect'    => get_site_url()
                );
            }

            $payment_method = $order->get_payment_method();
            $this->siteUrl = get_site_url();

            if (is_order_received_page() && ('safepay_gateway' == $payment_method)) {
                $requestRedirectUrl = sprintf("%s/wc-api/%s?processing_order_id=%s", $this->siteUrl, 'safepay_request_redirect', $OrderId);
                wp_redirect($requestRedirectUrl);
                die();
            }
        }
    }

    private function enqueue_scripts()
    {
        wp_enqueue_script($this->id . time(), plugin_dir_url(__FILE__) . '../assets/js/safepay-payment-form-woocommerce.js', array('jquery'), time(), true);
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields()
    {

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce-safepay-gateway'),
                'type' => 'checkbox',
                'label' => __('Enable SafePay Payment Gateway', 'woocommerce-safepay-gateway'),
                'description' => __('Enable or disable the gateway.', 'woocommerce-safepay-gateway'),
                'desc_tip' => false,
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title at checkout', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'label' => __('Title at checkout', 'woocommerce-safepay-gateway'),
                'description' => __('Title at checkout', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => 'Visa,Master,UPI Cards via SafePay'
            ),
            'description' => array(
                'title' => __('Description', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'label' => __('Description', 'woocommerce-safepay-gateway'),
                'description' => __('Description', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => 'Visa,Master Credit/Debit Cards, PayPak, UPI, Bank Accounts/Wallets and RAAST'
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'label' => __('Registered Merchant ID at SafePay', 'woocommerce-safepay-gateway'),
                'description' => __('Registered Merchant ID at SafePay.', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => ''
            ),
            'security_key' => array(
                'title' => __('Merchant Secured Key', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' => __('Merchant\'s security key.', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => ''
            ),
            'base_url' => array(
                'title' => __('Gateway Base URL', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' => __('Gateway Base URL', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => 'https://www.getsafepay.pk'
            ),
            'store_id' => array(
                'title' => __('Store ID', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' => __('Merchant\'s Store/Terminal/Outlet ID.', 'woocommerce-safepay-gateway'),
                'desc_tip' => true,
                'default' => ''
            )
        );
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        $order->payment_complete();

        // Remove cart
        WC()->cart->empty_cart();

        return array(
            'result'     => 'success',
            'redirect'    => $this->get_return_url($order)
        );
    }

    public function gateway_request()
    {
        $processingOrderId = isset($_GET['processing_order_id']) ? $_GET['processing_order_id'] : null;

        if (!$processingOrderId) {
            wp_redirect($this->siteUrl);
            die();
        }

        $order = wc_get_order($processingOrderId);


        header('HTTP/1.1 200 OK');
        header('Content-type: text/html');
        $gatewayRequest = new WC_safepay_Request($this, $processingOrderId);
        $gatewayRequest->responseCallBackUrl = 'safepay_gatewaycallback';
        $this->enqueue_scripts();

        $htmlParametersForm = $gatewayRequest->generate_SafePay_form();


        /**
         * Render HTML containing SafePay Payment Form
         */
        echo $htmlParametersForm;
        die();
    }

    public function payment_notification()
    {
        header('Content-type: text/html');
        $gatewayRequest = new WC_safepay_Response($this);
        $gatewayRequest->order_placement_routine();
        die();
    }

    function custom_update_order_status($order_id, $old_status, $new_status)
    {

        error_log(sprintf("Status changed from %s to %s", $old_status, $new_status));
    }
}
