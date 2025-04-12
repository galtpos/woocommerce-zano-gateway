<?php
/*
Plugin Name: WooCommerce Zano Gateway (CoinGecko)
Description: Zano-only WooCommerce payment gateway with real-time USD-to-ZANO conversion via CoinGecko and working QR code.
Version: 1.0.0
Author: Daylight Freedom
*/

defined('ABSPATH') || exit;

add_filter('woocommerce_payment_gateways', 'zano_gateway_add_class');
function zano_gateway_add_class($methods) {
    $methods[] = 'WC_Gateway_Zano_CoinGecko';
    return $methods;
}

add_action('plugins_loaded', 'zano_gateway_init');
function zano_gateway_init() {
    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_Gateway_Zano_CoinGecko extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = 'zano_gateway_coingecko';
            $this->has_fields = false;
            $this->method_title = 'Zano';
            $this->method_description = 'Accept ZANO cryptocurrency payments with real-time conversion from USD.';

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->wallet_address = $this->get_option('wallet_address');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array('title' => 'Enable/Disable', 'label' => 'Enable Zano Payment', 'type' => 'checkbox', 'default' => 'yes'),
                'title' => array('title' => 'Title', 'type' => 'text', 'default' => 'Pay with Zano'),
                'description' => array('title' => 'Customer Message', 'type' => 'textarea', 'default' => 'Send the exact amount of Zano (ZANO) after placing your order.'),
                'wallet_address' => array('title' => 'Zano Wallet Address', 'type' => 'text', 'description' => 'Enter your Zano receiving address.', 'default' => '')
            );
        }

        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }
        }

        public function is_available() {
            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $order->update_status('on-hold', 'Awaiting Zano payment');
            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();
            return array('result' => 'success', 'redirect' => $this->get_return_url($order));
        }

        public function get_zano_amount($usd) {
            $response = wp_remote_get('https://api.coingecko.com/api/v3/simple/price?ids=zano&vs_currencies=usd');
            if (is_wp_error($response)) return false;
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            if (!isset($data['zano']['usd'])) return false;
            $rate = $data['zano']['usd'];
            return round($usd / $rate, 6);
        }

        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            $usd_amount = floatval($order->get_total());
            $wallet = $this->wallet_address;

            if (!$wallet) {
                echo "<p style='color:red;'>Zano wallet address not configured. Please contact the merchant.</p>";
                return;
            }

            $zano_amount = $this->get_zano_amount($usd_amount);
            if (!$zano_amount) {
                echo "<p style='color:red;'>Unable to retrieve Zano price. Please contact support or try again later.</p>";
                return;
            }

            $uri = "zano:{$wallet}?amount={$zano_amount}";
            $qr = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($uri);

            echo "<div style='text-align:center;'>";
            echo "<h2>Complete Your Zano Payment</h2>";
            echo "<p><strong>Send:</strong> {$zano_amount} ZANO (~\${$usd_amount} USD)</p>";
            echo "<p><strong>To Address:</strong><br><code>{$wallet}</code></p>";
            echo "<img src='{$qr}' alt='Zano QR Code' style='margin:20px auto;width:200px;height:200px;'>";
            echo "<p>Scan this QR code with your Zano wallet or copy the address above.</p>";
            echo "</div>";
        }
    }
}
?>
