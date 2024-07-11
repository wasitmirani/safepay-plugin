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
    const PRODUCTION_BASE_URL = 'https://getsafepay.com';
    const SANDBOX_BASE_URL = 'https://sandbox.api.getsafepay.com';
    const DEVELOPMENT_BASE_URL = 'https://dev.api.getsafepay.com';
    const CHECKOUT_ROUTE = '/checkout/pay';
    const TRANSACTION_ENDPOINT = '/order/payments/v3/';
    const PRODUCTION_API_URL = 'https://api.getsafepay.com/';


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
    // Unique ID for the gateway
    public $id = 'safepay_gateway';

    // Class properties
    private $siteUrl;
    public $merchantId;
    public $appEnv;
    public $securedKey;
    public $storeId;
    public $baseUrl;
    private $processRequestPayment = false;

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {

        $this->icon = apply_filters('woocommerce_safepay_gateway_icon', '');
        $this->has_fields = false;
        $this->supports = array(
            'products'
        );

        $this->method_title = _x('SafePay', 'SafePay Gateway', 'woocommerce-safepay-gateway');
        $this->method_description = __('Pay via Credit/Debit Cards, Bank Accounts/Wallets and RAAST', 'woocommerce-safepay-gateway');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions', $this->description);
        $this->hide_for_non_admin_users = $this->get_option('hide_for_non_admin_users');

        $this->merchantId = $this->get_option('merchant_id');
        $this->securedKey = $this->get_option('security_key');
        $this->storeId = $this->get_option('store_id');
        $this->baseUrl = $this->get_option('base_url');
        $this->appEnv = $this->get_option('app_env');

        $this->siteUrl = get_site_url();

        add_filter('woocommerce_gateway_icon', array($this, 'safepay_display_woocommerce_icons'), 10, 2);


        add_filter('woocommerce_gateway_title', array($this, 'payment_method_title'), 10, 2);

        add_action('woocommerce_order_status_changed', array($this, 'custom_update_order_status'), 10, 3);
        // add_action('woocommerce_thankyou', 'custom_redirect_and_update_order_status');



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
		
        add_action('rest_api_init', function () {
		register_rest_route('safepay/v1', '/safepay-transaction-success/(?P<order_id>\d+)', array(
				'methods' => 'GET',
				'callback' => array($this, 'get_order_success_url'),
			    'permission_callback' => '__return_true', // Adjust permissions as needed

				 // Adjust permissions as needed
			));

		register_rest_route('safepay/v1', '/order-webhook/', array(
				'methods' => 'POST',
				'callback' => array($this, 'handle_webhook'),
			    'permission_callback' => '__return_true', // Adjust permissions as needed

				 // Adjust permissions as needed
			));
		});
    }
	
    function safepay_display_woocommerce_icons($icon, $id)
    {

        if ($id == 'safepay_gateway') {
            $imagePath = sprintf("%s/assets/images/logo.png", plugin_dir_url(dirname(__FILE__)));
            $icon = '<img width="25%" src="' . $imagePath . '" alt="safepay" />';
        }
        return $icon;
    }

    function payment_method_title($title, $id)
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
    private function get_env_url()
    {
        switch ($this->appEnv) {
            case 'development':
                return 'https://dev.api.getsafepay.com'; // Replace with actual development URL
            case 'sandbox':
                return 'https://sandbox.api.getsafepay.com'; // Replace with actual staging URL
            default:
                return 'https://api.getsafepay.com'; // Replace with actual production URL
        }
    }
    private function call_api_to_get_token($method, $endpoint, $params, $baseURL, $securedKey)
    {

        $args = array(
            'method'  => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-SFPY-MERCHANT-SECRET' => $securedKey,
            ),
            'body'    => json_encode($params),
        );
        $tokenEndpoint = '/client/passport/v1/token';
		$metaDataEndpoint = "/order/payments/v3";

        $tokenURL = $baseURL . $tokenEndpoint;
		$metaDataURL = $baseURL . $metaDataEndpoint;
        if (WP_DEBUG) {
            error_log("Request URL tokenURL: $tokenURL");
            error_log("Request Args tokenURL: " . print_r($args, true));
        }
        $responseData = wp_remote_post(esc_url_raw($tokenURL), $args);
        if (is_wp_error($responseData)) {
            if (WP_DEBUG) {
                error_log("cURL error: " . $responseData->get_error_message());
                echo "cURL error: " . $responseData->get_error_message()."<br>";
            }
            return array(false, $responseData->get_error_message());
        } else {
            $userToken = json_decode($responseData['body'], true);
            $code = $responseData['response']['code'];

            if (WP_DEBUG) {
                error_log("Response Code userToken: $code");
                echo "Response Code userToken: $code"."<br>";
                echo "Response BodyuserToken: " . print_r($userToken, true);
                error_log("Response BodyuserToken: " . print_r($userToken, true));
            }
        }

        $url = $baseURL . $endpoint;

        if (WP_DEBUG) {
            error_log("Request URL: $url");
            error_log("Request Args: " . print_r($args, true));
        }

        $response = wp_remote_post(esc_url_raw($url), $args);

        if (is_wp_error($response)) {
            if (WP_DEBUG) {
                error_log("cURL error: " . $response->get_error_message());
            }
            echo "cURL error: " . $response->get_error_message();
            return array(false, $response->get_error_message());
        } else {
            $result = json_decode($response['body'], true);
            $code = $response['response']['code'];

            if (WP_DEBUG) {
                error_log("Response Code: $code");
                error_log("Tracker Response Body: " . print_r($result, true));
            }

            if ($code === 201) {

					$meta_payload = array(
						'method'  => 'POST',
						'headers' => array(
							'Content-Type' => 'application/json',
							'X-SFPY-MERCHANT-SECRET' => $securedKey,
						),
						'body'=> json_encode(['data'=>[
							"source" => (string)$params['source'],
							"order_id"=> (string)$params['order_id'],
							]]),
					);
				     $responseData = wp_remote_post(esc_url_raw($metaDataURL.'/'.$result['data']['tracker']['token'].'/metadata'), 	$meta_payload );
				     $meta_result = json_decode($responseData['body'], true);
				     $meta_code = $responseData['response']['code'];
					error_log("metaData Response Code: $meta_code");
					error_log("metaData Body ".print_r($meta_payload));
					error_log("metaDataURL= ".$metaDataURL.'/'.$result['data']['tracker']['token'].'/metadata');
					error_log("metaData Response Body: " . print_r($meta_result, true));
                return array(true, $userToken, $result);
            } else {
                return array(false, $code);
            }
        }
    }


    public function getBaseURL(){
        $url =  self::PRODUCTION_API_URL;
        switch ($this->appEnv) {
            case 'development':
                $url = self::DEVELOPMENT_BASE_URL; // Replace with actual development URL
                break; 
            case 'sandbox':
                $url =  self::SANDBOX_BASE_URL; // Replace with actual staging URL
                break; 
            case 'production':
                $url =  self::PRODUCTION_BASE_URL; // Replace with actual production URL
                break;
            default:
                $url =  self::PRODUCTION_BASE_URL; // Replace with actual production URL
                break;
        }
        return $url;
    }
   
    function handle_webhook($request){
		
	 // Get the body data from the request
    $parameters = $request->get_json_params();

    // Validate and process the body data
    if (empty($parameters) || !isset($parameters['data'])) {
        return new WP_Error('no_data', 'No data provided', array('status' => 400));
    }

    $data = $parameters['data'];

    // Sanitize and validate each field as necessary
    $tracker = sanitize_text_field($data['tracker']);
    $intent = sanitize_text_field($data['intent']);
    $state = sanitize_text_field($data['state']);
    $net = intval($data['net']);
    $fee = intval($data['fee']);
    $customer_email = sanitize_email($data['customer_email']);
    $amount = intval($data['amount']);
    $currency = sanitize_text_field($data['currency']);
    $metadata = isset($data['metadata']) ? $data['metadata'] : array();
    $charged_at_seconds = intval($data['charged_at']['seconds']);
    $charged_at_nanos = intval($data['charged_at']['nanos']);
		
     $OrderId = absint($data['metadata']['order_id'] ?? 0);
     $order = wc_get_order($OrderId);
	 if($state == 'TRACKER_ENDED'){
		 	  $order->update_status('payment-received');
		 	$order_note_message = 'Payment has been received successfully. Transaction reference ID: ' . $tracker;
		 	$order->update_meta_data('_transaction_ref_id', $tracker);
			 $order->add_order_note($order_note_message);
			// Save the order to persist the changes
    			$order->save();

			   return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order)
                    );
	 }
	else {
			 $order->update_status('failed');
			$order_note_message = 'Payment has been received failed. Transaction reference ID: ' . $tracker;
		 	$order->update_meta_data('_transaction_ref_id', $tracker);
			 $order->add_order_note($order_note_message);
			// Save the order to persist the changes
    			$order->save();
			   return array(
                        'result' => 'failed',
                        'redirect' => $this->get_return_url($order)
            );
	}
    
    // Do something with the data (e.g., save it to the database, etc.)
    
    return rest_ensure_response($response_data);
	}
    function get_order_success_url($request) {
        // Retrieve order_id from URL parameter
        // 
   
    	$order_id = $request->get_param('order_id');
		print_r($order_id);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new WP_Error('no_order', 'Order not found', array('status' => 404));
        }
        
        $site_url = sprintf("%s/checkout/order-received/%s?", get_site_url(), $order->get_id());
        $successUrl = $site_url;
        $successUrl .= "&order_id=" . $order->get_id() . '&ispaid=true';

		
    // Update order status and perform any necessary actions
// 		$order->update_status('payment-received');
// 		$order->delete_meta_data('safepay_gateway_transaction_success');
// 		$order->save();

    // Redirect user to the return URL
   		 $return_url = $this->get_return_url($order);
		wp_redirect($return_url);
    	 exit; // Ensure to exit after redirection
        
     
    }
    public function process_order_place()
    {
 

//         if (isset($_GET['ispaid']) && get_query_var('order-received')) {
//             // Get the value of the 'ispaid' parameter
//             $isPaid = $_GET['ispaid'];
        
//             // Perform actions based on the value of 'ispaid'
//             if ($isPaid == 'true') {
//                 $OrderId = absint(get_query_var('order-received'));
//                 $order = wc_get_order($OrderId);
//                 error_log("order-received: " . $OrderId);
//                 $order->delete_meta_data('safepay_gateway_transaction_success');
//                   // Example: Update order status to 'completed'
              
//                   $order->update_status('payment-received');
//                 if ($order->get_status() === 'payment-received') {
//                     return array(
//                         'result' => 'success',
//                         'redirect' => $this->get_return_url($order)
//                     );
//                 }
//                 // Perform actions for paid users
//             } else {
//                 echo 'The user has not paid.';
//                 $order->update_status('failed');
//                 if ($order->get_status() === 'failed') {
//                     return array(
//                         'result' => 'failed',
//                         'redirect' => get_site_url()
//                     );
//                 }
//                 // Perform actions for non-paid users
//             }
//         } 


     
        if (get_query_var('order-received')) {
            $OrderId = absint(get_query_var('order-received'));
            $order = wc_get_order($OrderId);
            error_log("order-received: " . $OrderId);
            $order->delete_meta_data('safepay_gateway_transaction_success');

            if ($order->get_status() === 'payment-received') {
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
            }

           

            $args["amount"] = (int) ($order->get_total() * 100);
            $args["intent"] = "CYBERSOURCE";
            $args["mode"] = "payment";
            $args["currency"] = get_woocommerce_currency();
            $args["merchant_api_key"] = $this->merchantId;
			$args['order_id'] =  $OrderId;
			$args['source'] = 'woocommerce';

            $baseURL = $this->get_env_url();
          
            list($success, $userToken, $result) = $this->call_api_to_get_token('POST', '/order/payments/v3/', $args, $baseURL, $this->securedKey);
            if ( !$success) {
                return false;
            }
            $tracker = $result['data']['tracker']['token'];

            $payment_method = $order->get_payment_method();
            $this->siteUrl = get_site_url();
            $userToken = $userToken['data'];
            $site_url = sprintf("%s/wp-json/safepay/v1/safepay-transaction-success/%s?", get_site_url(),$order->get_id());
            $successUrl = $site_url;
            $successUrl .= "&order_id=" . $order->get_id().'&ispaid=true';

            $failUrl = $site_url;
            $failUrl .= "&order_id=" . $order->get_id().'&ispaid=false';

            $backend_callback = $site_url;
            $backend_callback .= "order_id=" . $order->get_id();

		    $orderDate = date('Y-m-d H:i:s', time());
            // $successUrl = urlencode($successUrl);
            // $failUrl = urlencode($failUrl);
            $url = $this->getBaseURL();
            if (is_order_received_page() && ('safepay_gateway' == $payment_method)) {
                if (isset($success) && $success) {
                    $token = $result['token'];
    
                    $requestRedirectUrl = $url . "/embedded/?tbt=$userToken&tracker=$tracker&order_id=$OrderId&environment=$this->appEnv&source=woocommerce&redirect_url=$successUrl&cancel_url=$failUrl";
                    echo $requestRedirectUrl;
                    error_log("Checkout URL: " . $requestRedirectUrl);
                    wp_redirect($requestRedirectUrl);
                    die();
                } else {
                    // Log API call failure
                    if (WP_DEBUG) {
                        error_log("API call failed: " . $result);
                        echo "API call failed: " . $result;
                    }
                }
            }
        }
    }
    function custom_redirect_and_update_order_status($order_id) {
        // Get the order object
        $order = wc_get_order($order_id);
        
        // Example: Update order status to 'completed'
        $order->update_status('completed');
    
        // Construct the redirect URL
        $site_url = get_site_url();
        // $redirect_url = trailingslashit($site_url) . '/checkout/order-received/' . $order->get_id() . '/';
        $redirect_url .= '?order_id=' . $order->get_id();
    
        // Perform the redirect
        // wp_redirect($redirect_url);
        exit;
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
            'app_env' => array(
                'title' => __('Environment', 'woocommerce-safepay-gateway'),
                'type' => 'select',
                'label' => __('Environment', 'woocommerce-safepay-gateway'),
                'description' => __('Choose the environment.', 'woocommerce-safepay-gateway'),
                'desc_tip' => false,
                'default' => 'yes',
                'options' => array(
                    'development' => __('Development', 'woocommerce-safepay-gateway'),
                    'sandbox' => __('Sandbox', 'woocommerce-safepay-gateway'),
                    'production' => __('Production', 'woocommerce-safepay-gateway')
                )
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
            'production_webhook_secret' => array(
                'title' => __('Production Webhook Secret', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' =>
                // translators: Instructions for setting up 'webhook shared secrets' on settings page.
                __('Using webhook secret keys allows Safepay to verify each payment. To get your live webhook key:')
                    . '<br /><br />' .

                    // translators: Step 1 of the instructions for 'webhook shared secrets' on settings page.
                    __('1. Navigate to your Live Safepay dashboard by clicking <a target="_blank" href="https://getsafepay.com/dashboard/webhooks">here</a>')

                    . '<br />' .

                    // translators: Step 2 of the instructions for 'webhook shared secrets' on settings page. Includes webhook URL.
                    sprintf(__('2. Click \'Add an endpoint\' and paste the following URL: %s', 'Safepay'), add_query_arg('/wp-json/safepay/v1/order-webhook/', 'WC_Safepay',get_site_url()))

                    . '<br />' .

                    // translators: Step 3 of the instructions for 'webhook shared secrets' on settings page.
                    __('3. Make sure to select "Send me all events", to receive all payment updates.', 'Safepay')

                    . '<br />' .

                    // translators: Step 4 of the instructions for 'webhook shared secrets' on settings page.
                    __('4. Click "Show shared secret" and paste into the box above.', 'Safepay'),

                'desc_tip' => false,
                'default' => sprintf("%s/wp-json/safepay/v1/order-webhook/%s?", get_site_url(),$this->get_option('merchant_id') ?? ''),
            ),
			'sandbox_webhook_secret' => array(
                'title' => __('Sandbox Webhook Secret', 'woocommerce-safepay-gateway'),
                'type' => 'text',
                'description' =>
                // translators: Instructions for setting up 'webhook shared secrets' on settings page.
                __('Using webhook secret keys allows Safepay to verify each payment. To get your live webhook key:')
                    . '<br /><br />' .

                    // translators: Step 1 of the instructions for 'webhook shared secrets' on settings page.
                    __('1. Navigate to your Live Safepay dashboard by clicking <a target="_blank" href="https://getsafepay.com/dashboard/webhooks">here</a>')

                    . '<br />' .

                    // translators: Step 2 of the instructions for 'webhook shared secrets' on settings page. Includes webhook URL.
                    sprintf(__('2. Click \'Add an endpoint\' and paste the following URL: %s', 'Safepay'),add_query_arg('/wp-json/safepay/v1/order-webhook/', 'WC_Safepay',get_site_url()))

                    . '<br />' .

                    // translators: Step 3 of the instructions for 'webhook shared secrets' on settings page.
                    __('3. Make sure to select "Send me all events", to receive all payment updates.', 'Safepay')

                    . '<br />' .

                    // translators: Step 4 of the instructions for 'webhook shared secrets' on settings page.
                    __('4. Click "Show shared secret" and paste into the box above.', 'Safepay'),

                'desc_tip' => false,
                'default' => sprintf("%s/wp-json/safepay/v1/order-webhook/%s?", get_site_url(),$this->get_option('merchant_id') ?? ''),
            ),
            // 'cancel_url' => array(
            //     'title' => __('Cancel URL', 'woocommerce-safepay-gateway'),
            //     'type' => 'text',
            //     'description' => __('Cancel URL', 'woocommerce-safepay-gateway'),
            //     'desc_tip' => true,
            //     'default' => ''
            // )
        );
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        $order->payment_complete();

        // Remove cart
        WC()->cart->empty_cart();

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
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
