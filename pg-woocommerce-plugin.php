<?php

/*
Plugin Name: Paymentez WooCommerce Plugin
Plugin URI: http://www.paymentez.com
Description: This module is a solution that allows WooCommerce users to easily process credit card payments.
Version: 1.0
Author: Paymentez
Author URI: http://www.paymentez.com
License: A "Slug" license name e.g. GPL2
*/

add_action( 'plugins_loaded', 'pg_woocommerce_plugin' );

// Creación de la base de datos
if (!function_exists('db_paymentez_plugin')) {
  function db_paymentez_plugin() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'paymentez_plugin';

    if ($wpdb->get_var('SHOW TABLES LIKES ' . $table_name) != $table_name) {
      $sql = 'CREATE TABLE ' . $table_name . ' (
             id integer(9) unsigned NOT NULL AUTO_INCREMENT,
             Status varchar(50) NOT NULL,
             Comments varchar(50) NOT NULL,
             description text(500) NOT NULL,
             OrdenId int(9) NOT NULL,
             Transaction_Code varchar(50) NOT NULL,
             PRIMARY KEY  (id)
             );';
      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      dbDelta($sql);
    }
  }
}

register_activation_hook(__FILE__, 'db_paymentez_plugin');

function pg_woocommerce_plugin() {
  class WC_Gateway_Paymentez extends WC_Payment_Gateway {
    public function __construct() {
      # $this->has_fields = true;
      $this->id = 'pg_woocommerce';
      $this->icon = apply_filters('woocomerce_paymentez_icon', plugins_url('/imgs/paymentezcheck.png', __FILE__));
      $this->method_title = 'Paymentez Plugin';
      $this->method_description = 'This module is a solution that allows WooCommerce users to easily process credit card payments.';

      $this->init_settings();
      $this->init_form_fields();

      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');

      $this->app_code_client = $this->get_option('app_code_client');
      $this->app_key_client = $this->get_option('app_key_client');
      $this->app_code_server = $this->get_option('app_code_server');
      $this->app_key_server = $this->get_option('app_key_server');

      // Para guardar sus opciones, simplemente tiene que conectar la función process_admin_options en su constructor.
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));

      add_action('woocommerce_receipt_pg_woocommerce', array(&$this, 'receipt_page'));
    }

    public function init_form_fields() {
      $this->form_fields = array (
        'enabled' => array(
            'title' => __( 'Enable/Disable', 'pg_woocommerce' ),
            'type' => 'checkbox',
            'label' => __( 'Enable Paymentez Gateway', 'pg_woocommerce' ),
            'default' => 'yes'
        ),
        'title' => array(
            'title' => __( 'Title', 'pg_woocommerce' ),
            'type' => 'text',
            'description' => __( 'This controls the title which the user sees during checkout.', 'pg_woocommerce' ),
            'default' => __( 'Paymentez Gateway', 'pg_woocommerce' ),
            'desc_tip' => true,
        ),
        'description' => array(
            'title' => __( 'Customer Message', 'pg_woocommerce' ),
            'type' => 'textarea',
            'default' => 'Paymentez is a complete solution for online payments. Safe, easy and fast.'
        ),
        'app_code_client' => array(
        'title' => __('App Code Client', 'pg_woocommerce'),
        'type' => 'text',
        'description' => __('Unique identifier in Paymentez.', 'pg_woocommerce')
        ),
        'app_key_client' => array(
            'title' => __('App Key Client', 'pg_woocommerce'),
            'type' => 'text',
            'description' => __('Key used to encrypt communication with Paymentez.', 'pg_woocommerce')
        ),
        'app_code_server' => array(
            'title' => __('App Code Server', 'pg_woocommerce'),
            'type' => 'text',
            'description' => __('Unique identifier in Paymentez Server.', 'pg_woocommerce')
        ),
        'app_key_server' => array(
            'title' => __('App Key Server', 'pg_woocommerce'),
            'type' => 'text',
            'description' => __('Key used for reverse communication with Paymentez Server.', 'pg_woocommerce')
        )
      );
    }

    function admin_options() {
      ?>
      <h2><?php _e('Paymentez Gateway','pg_woocommerce'); ?></h2>
      <table class="form-table">
      <?php $this->generate_settings_html(); ?>
      </table>
      <?php
    }

    function receipt_page($order) {
      echo $this->generate_paymentez_form($order);
    }

    public function get_params_post($orderId) {
      $order = new WC_Order($orderId);
      $order_data = $order->get_data();
      $currency = get_woocommerce_currency();
      $amount = $order_data['total'];
      $credito = get_post_meta($orderId, '_billing_customer_dni', true);
      $products = $order->get_items();
      $description = '';
      $taxable_amount = 0.00;
      foreach ($products as $product) {
        $description .= $product['name'] . ',';
        if ($product['subtotal_tax'] != 0 && $product['subtotal_tax'] != '') {
            $taxable_amount = number_format(($product['subtotal']), 2, '.', '');
        }
      }
      foreach ($order->get_items() as $item_key => $item) {
        $prod = $order->get_product_from_item($item);
        $sku = $prod->get_id();
      }
      $fecha_actual = date('Y-m-d');
      $subtotal = number_format(($order->get_subtotal()), 2, '.', '');
      $vat = number_format(($order->get_total_tax()), 2, '.', '');
      $taxReturnBase = number_format(($amount - $vat), 2, '.', '');
      if ($vat == 0) $taxReturnBase = 0;
      if ($vat == 0) $tax_percentage = 0;
      if (is_null($order_data['customer_id']) or empty($order_data['customer_id'])) {
          $uid = $orderId;
      } else {
          $uid = $order_data['customer_id'];
      }
      $parametersArgs = array(
        'purchase_order_id' => $orderId,
        'purchase_description' => $description,
        'purchase_amount' => $amount,
        'subtotal' => $subtotal,
        'purchase_currency' => $currency,
        'customer_firstname' => $order_data['billing']['first_name'],
        'customer_lastname' => $order_data['billing']['last_name'],
        'customer_phone' => $order_data['billing']['phone'],
        'customer_email' => $order_data['billing']['email'],
        'address_street' => $order_data['billing']['address_1'],
        'address_city' => $order_data['billing']['city'],
        'address_country' => $order_data['billing']['country'],
        'address_state' => $order_data['billing']['state'],
        'user_id' => $uid,
        'cod_prod' => $sku,
        'productos' => $prod,
        'taxable_amount' => $taxable_amount,
      );

      return $parametersArgs;

    }

    public function generate_paymentez_form($orderId) {
      $callback = plugins_url('/callback.php', __FILE__);
      $css = plugins_url('/css/styles.css', __FILE__);
      $orderData = $this->get_params_post($orderId);
      ?>
      <link rel="stylesheet" type="text/css" href="<?php echo $css; ?>">

      <div id="messagetwo" class="hide"> <p class="alert alert-success" > Su pago se ha realizado con éxito. Muchas gracias por su compra </p> </div>

      <div id="messagetres" class="hide"> <p class="alert alert-warning"> Ocurrió un error al comprar y su pago no se pudo realizar. Intente con otra Tarjeta de Crédito </p> </div>

      <div id="buttonreturn" class="hide">
        <p>
          <a class="btn-tienda" href="<?php echo get_permalink( wc_get_page_id( 'shop' ) ); ?>"><?php _e( 'Return to Store', 'woocommerce' ) ?></a>
        </p>
      </div>

      <script src="https://cdn.paymentez.com/checkout/1.0.1/paymentez-checkout.min.js"></script>

      <button class="js-paymentez-checkout">Purchase</button>

      <script>
      jQuery(document).ready(function($) {
        var paymentezCheckout = new PaymentezCheckout.modal({
            client_app_code: '<?php echo $this->app_code_client;?>', // Client Credentials Provied by Paymentez
            client_app_key: '<?php echo $this->app_key_client;?>', // Client Credentials Provied by Paymentez
            locale: 'en', // User's preferred language (es, en, pt). English will be used by default.
            env_mode: 'stg', // `prod`, `stg` to change environment. Default is `stg`
            onOpen: function() {
                console.log('modal open');
            },
            onClose: function() {
                console.log('modal closed');
            },
            onResponse: function(response) {
                announceTransaction(response);
                if (response.transaction["status_detail"] === 3) {
                   console.log('modal response');
                   console.log(response);
                   showMessageSuccess();
                } else {
                   showMessageError();
                }
            }
        });

        var btnOpenCheckout = document.querySelector('.js-paymentez-checkout');
        btnOpenCheckout.addEventListener('click', function(){
          // Open Checkout with further options:
          paymentezCheckout.open({
            user_id: '<?php echo $orderData['user_id'];?>',
            user_email: '<?php echo $orderData['customer_email'];?>', //optional
            user_phone: '<?php echo $orderData['customer_phone'];?>', //optional
            order_description: '<?php echo $orderData['purchase_description'];?>',
            order_amount: parseFloat('<?php echo $orderData['purchase_amount'];?>'),
            order_vat: 0,
            order_reference: '<?php echo $orderData['purchase_order_id'];?>',
            //order_installments_type: 2, // optional: For Colombia an Brazil to show installments should be 0, For Ecuador the valid values are: https://paymentez.github.io/api-doc/#payment-methods-cards-debit-with-token-installments-type
            //order_taxable_amount: 0, // optional: Only available for Ecuador. The taxable amount, if it is zero, it is calculated on the total. Format: Decimal with two fraction digits.
            //order_tax_percentage: 10 // optional: Only available for Ecuador. The tax percentage to be applied to this order.
          });
        });

        // Close Checkout on page navigation:
        window.addEventListener('popstate', function() {
          paymentezCheckout.close();
        });

        function showMessageSuccess() {
          $("#buttonspay").addClass("hide");
          $("#messagetwo").removeClass("hide");
          $("#buttonreturn").removeClass("hide");
        }

        function showMessageError() {
          $("#buttonspay").addClass("hide");
          $("#messagetres").removeClass("hide");
          $("#buttonreturn").removeClass("hide");
        }

        function announceTransaction(data) {
            fetch("<?php echo $callback; ?>", {
            method: "POST",
            body: JSON.stringify(data)
            }).then(function(response) {
            console.log(response);
            }).catch(function(myJson) {
            console.log(myJson);
            });
        }
      });
      </script>
      <?php
    }

    public function process_payment($orderId)
    {
        $order = new WC_Order($orderId);
        return array(
            'result' => 'success',
            'redirect' => $order->get_checkout_payment_url(true)
        );
    }
  }
}

function add_pg_woocommerce_plugin( $methods ) {
    $methods[] = 'WC_Gateway_Paymentez';
    return $methods;
}

add_filter( 'woocommerce_payment_gateways', 'add_pg_woocommerce_plugin' );
