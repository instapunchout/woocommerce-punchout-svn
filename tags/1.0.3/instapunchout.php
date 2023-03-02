<?php

/**
 * @package insta_Punchout
 * @version 3.0
 */
/*
Plugin Name: InstaPunchout
Description: This is the punchout plugin which is created by InstaPunchout.
Author: InstaPunchout
Version: 1.0.3

 */
if (!session_id()) {
    session_start();
}
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    exit;
}

function instapunchout_end_session()
{
    global $current_user;
    update_user_meta($current_user->ID, 'instapunchout', '');
}

add_action('wp_logout', 'instapunchout_end_session');
add_action('wp_login', 'instapunchout_end_session');

function instapunchout_user_meta_updated($meta_id, $user_id, $key = null, $value = null)
{
    if ($key == 'group_id' && $value != null && class_exists('Groups_User_Group')) {
        Groups_User_Group::create(array('user_id' => $user_id, 'group_id' => $value));
    } else if ($key == 'role' && $value != null) {
        $user = new WP_User($user_id);
        $roles = explode(",", $value);
        $first = array_shift($roles);
        $user->set_role($first); // remove old roles and sets new one
        foreach ($roles as $k => $role) {
            $user->add_role($role); // add more roles if there is any
        }
    }
}
add_action('added_user_meta', 'instapunchout_user_meta_updated', 20, 4);
add_action('update_user_meta', 'instapunchout_user_meta_updated', 20, 4);

/// uxload

add_action('wp_head', 'instapunchout_ux_load');
function instapunchout_ux_load()
{
    $user_id = get_current_user_id();
    $punchout_id = get_user_meta($user_id, 'punchout_id', true);

    if ($user_id && $punchout_id) {
        $response = wp_remote_get('https://punchout.cloud/punchout.js?id=' . $punchout_id, 'json', 'javascript');
        $body = wp_remote_retrieve_body($response);
        wp_print_inline_script_tag($body);
    }
}

// punchout

add_action('wp_loaded', 'instapunchout_execute');
function instapunchout_execute()
{

    $punchoutModel = new InstaPunchout_Punchout();
    $punchoutModel->process();
}

function instapunchout_post_json($url, $body = null)
{
    $args = array(
        'body' => json_encode($body),
        'timeout' => '5',
        'redirection' => '5',
        'httpversion' => '1.0',
        'blocking' => true,
        'headers' => ["content-type" => "application/json"],
        'cookies' => array(),
    );

    $response = wp_remote_post($url, $args);
    $body = wp_remote_retrieve_body($response);
    return json_decode($body, true);
}

function instapunchout_get_products($vars)
{
    $vars['type'] = array_merge(array_keys(wc_get_product_types()));
    $args = apply_filters('woocommerce_product_object_query_args', $vars);
    $results = WC_Data_Store::load('product')->query($args);
    return apply_filters('woocommerce_product_object_query', $results, $args);
}

class InstaPunchout_Punchout
{

    public function process()
    {
        global $wp;
        $url = $_SERVER['REQUEST_URI'];
        $index = strpos($url, '/punchout/');

        $cart_path = "/punchout/api/cart";
        $order_path_json = "/punchout/api/order.json";
        $cart_path_json = "/punchout/api/cart.json";
        $products_path_json = "/punchout/api/products.json";
        $punchout_path = substr($url, $index);

        if (substr($url, $index, strlen($products_path_json)) === $products_path_json) {
            try {
                header('Content-Type: application/json');
                // $authorization_header = getallheaders()["Authorization"];
                // $res = instapunchout_post_json('https://dev.instapunchout.com/authorize', ["authorization"=> $authorization_header]);
                //if($res["authorized"] == true) {
                $data = [];
                $products = instapunchout_get_products(['limit' => 10000]);
                foreach ($products as $key => $value) {
                    $product = $value->get_data();
                    $attributes = [];
                    foreach ($value->get_attributes() as $name => $attribute) {
                        array_push($attributes, ['name' => $name, 'options' => $attribute->get_slugs()]);
                    }
                    $product['attributes'] = $attributes;
                    array_push($data, $product);
                }
                echo json_encode(["products" => $data]);
                die();
                // instapunchout_create_o(json_decode(file_get_contents('php://input'),true));
                //}else {
                //    echo json_encode(["error" => "You're not authorized", "error_data" => $res]);
                // }
            } catch (Exception $e) {
                echo var_dump($e);
            }
        } else if (substr($url, $index, strlen($order_path_json)) === $order_path_json) {

            try {
                header('Content-Type: application/json');
                $authorization_header = getallheaders()["Authorization"];
                $res = instapunchout_post_json('https://punchout.cloud/authorize', ["authorization" => $authorization_header]);
                if ($res["authorized"] == true) {
                    instapunchout_create_order(json_decode(file_get_contents('php://input'), true));
                } else {
                    echo json_encode(["error" => "You're not authorized", "error_data" => $res]);
                }
            } catch (Exception $e) {
                echo var_dump($e);
            }
            exit;
        } else if (substr($url, $index, strlen($cart_path_json)) === $cart_path_json) {
            header('Content-Type: application/json');
            echo json_encode(instapunchout_get_cart());
            exit;
        } else if (substr($url, $index, strlen($cart_path)) === $cart_path) {

            $current_user_id = get_current_user_id();
            $punchout_id = get_user_meta($current_user_id, 'punchout_id', true);

            if (!isset($punchout_id)) {
                echo json_encode(['message' => "You're not in a punchout session"]);
                exit;
            }

            $cart = ['items' => instapunchout_get_cart(), 'currency' => get_woocommerce_currency()];

            // no need for further sanization as we need to capture all the request data as is
            $custom = json_decode(json_encode($_REQUEST), true);

            $body = ['cart' => ['Woocommerce' => $cart], 'custom' => $custom];
            $res = instapunchout_post_json('https://punchout.cloud/cart/' . $punchout_id, $body);

            if (isset($res['url'])) {
                WC()->cart->empty_cart();
                wp_logout();
            }

            header('Content-Type: application/json');
            echo json_encode($res);
            exit;
        } else if (strpos($url, "/punchout") !== false) {

            // no need for further sanization as we need to capture all the server data as is
            $server = json_decode(json_encode($_SERVER), true);
            // no need for further sanization as we need to capture all the query data as is
            $query = json_decode(json_encode($_GET), true);

            $data = array(
                'headers' => getallheaders(),
                'server' => $server,
                'body' => file_get_contents('php://input'),
                'query' => $query,
            );

            $res = instapunchout_post_json('https://punchout.cloud/proxy', $data);
            if ($res['action'] == 'print') {
                header('content-type: application/xml');
                $xml = new SimpleXMLElement($res['body']);
                echo $xml->asXML();
            } else if ($res['action'] == 'login') {
                $email = $res['email'];

                $user = get_user_by('email', $email);

                if (!$user) {
                    $user_id = wc_create_new_customer($email, $res['username'], $res['password'], $res['properties']);
                    if (is_wp_error($user_id)) {
                        die("Failed to create user " . var_dump($user_id));
                    }
                    update_user_meta($user_id, 'punchout_id', $res['punchout_id']);
                    $user = get_user_by('email', $email);
                }

                // empty cart
                WC()->cart->empty_cart();
                if ($user instanceof WP_User) {
                    wp_clear_auth_cookie();
                    // $data['session'] = $session;
                    update_user_meta($user->ID, 'punchout_id', $res['punchout_id']);
                    wp_set_current_user($user->ID);
                    wp_set_auth_cookie($user->ID);
                    wp_redirect(home_url());
                    exit();
                } else {
                    wp_redirect(home_url() . '?user_doesnt_exist');
                    exit();
                }

                header('Location: /?v28');
            } else {
                echo "v0.0.28 unknwon action " . esc_html($res['action']);
                echo json_encode($data);
                echo json_encode($res);
            }
            exit;
        }
    }
}

function instapunchout_get_cart()
{

    try {
        $cart = WC()->cart->get_cart();
        $version2 = substr(WC()->version, 0, strlen("2.3.")) === "2.3.";

        if ($version2) {
            foreach ($cart as $key => $item) {
                // Adds the product name as a new variable.
                $cart[$key]['name'] = $item['data']->post->post_title;

                $cart[$key]['sku'] = $item['product_id'];
                // support for plugin "advanced-custom-fields"
                if (function_exists('get_fields')) {
                    $cart[$key]['custom'] = get_fields($cart[$key]['product_id']);
                }
                // $cart[$key]['shipping_amount'] = $shipping_amout;
            }
        } else {

            if (method_exists(WC()->cart, 'get_shipping_total')) {
                $shipping_amount = WC()->cart->get_shipping_total();
            } else if (isset(WC()->cart->shipping_total)) {
                $shipping_amount = WC()->cart->shipping_total;
            }

            foreach ($cart as $key => $item) {
                $_product = apply_filters('wc_cart_rest_api_cart_item_product', $item['data'], $item, $key);

                // Adds the product name as a new variable.
                $cart[$key]['name'] = $_product->get_name();

                $cart[$key]['sku'] = $_product->get_sku();
                // support for plugin "advanced-custom-fields"
                if (function_exists('get_fields')) {
                    $cart[$key]['custom'] = get_fields($cart[$key]['product_id']);
                }
                $cart[$key]['shipping_amount'] = $shipping_amount;
            }
        }

        return $cart;
    } catch (Exception $e) {
        echo var_dump(e);
        exit();
    }
}

function instapunchout_create_order($data)
{
    $order = wc_create_order($data);
    foreach ($data['items'] as $key => $item) {
        $order->add_product(get_product($item['product_id']), $item['quantity'], $item);
    }
    $order->set_address($data['billing'], 'billing');
    $order->set_address($data['shipping'], 'shipping');
    $order->calculate_totals();
    if (isset($data['status'])) {
        if (!isset($data['status_note'])) {
            $data['status_node'] = "";
        }
        $order->update_status($data['status'], $data['status_node'], true);
    }
    echo json_encode(['id' => $order->id]);
}

if (isset($_GET['samesitesecure']) && !function_exists('wp_set_auth_cookie')) :
    /**
     * Log in a user by setting authentication cookies.
     *
     * The $remember parameter increases the time that the cookie will be kept. The
     * default the cookie is kept without remembering is two days. When $remember is
     * set, the cookies will be kept for 14 days or two weeks.
     *
     * @since 2.5.0
     * @since 4.3.0 Added the `$token` parameter.
     *
     * @param int    $user_id  User ID
     * @param bool   $remember Whether to remember the user
     * @param mixed  $secure   Whether the admin cookies should only be sent over HTTPS.
     *                         Default is_ssl().
     * @param string $token    Optional. User's session token to use for this cookie.
     */
    function wp_set_auth_cookie($user_id, $remember = false, $secure = '', $token = '')
    {
        $secure = true;
        if ($remember) {
            /**
             * Filters the duration of the authentication cookie expiration period.
             *
             * @since 2.8.0
             *
             * @param int  $length   Duration of the expiration period in seconds.
             * @param int  $user_id  User ID.
             * @param bool $remember Whether to remember the user login. Default false.
             */
            $expiration = time() + apply_filters('auth_cookie_expiration', 14 * DAY_IN_SECONDS, $user_id, $remember);
            /*
             * Ensure the browser will continue to send the cookie after the expiration time is reached.
             * Needed for the login grace period in wp_validate_auth_cookie().
             */
            $expire = $expiration + (12 * HOUR_IN_SECONDS);
        } else {
            /** This filter is documented in wp-includes/pluggable.php */
            $expiration = time() + apply_filters('auth_cookie_expiration', 2 * DAY_IN_SECONDS, $user_id, $remember);
            $expire = 0;
        }
        if ('' === $secure) {
            $secure = is_ssl();
        }
        // Front-end cookie is secure when the auth cookie is secure and the site's home URL is forced HTTPS.
        $secure_logged_in_cookie = $secure && 'https' === parse_url(get_option('home'), PHP_URL_SCHEME);
        /**
         * Filters whether the connection is secure.
         *
         * @since 3.1.0
         *
         * @param bool $secure  Whether the connection is secure.
         * @param int  $user_id User ID.
         */
        $secure = apply_filters('secure_auth_cookie', $secure, $user_id);
        /**
         * Filters whether to use a secure cookie when logged-in.
         *
         * @since 3.1.0
         *
         * @param bool $secure_logged_in_cookie Whether to use a secure cookie when logged-in.
         * @param int  $user_id                 User ID.
         * @param bool $secure                  Whether the connection is secure.
         */
        $secure_logged_in_cookie = apply_filters('secure_logged_in_cookie', $secure_logged_in_cookie, $user_id, $secure);
        if ($secure) {
            $auth_cookie_name = SECURE_AUTH_COOKIE;
            $scheme = 'secure_auth';
        } else {
            $auth_cookie_name = AUTH_COOKIE;
            $scheme = 'auth';
        }
        if ('' === $token) {
            $manager = WP_Session_Tokens::get_instance($user_id);
            $token = $manager->create($expiration);
        }
        $auth_cookie = wp_generate_auth_cookie($user_id, $expiration, $scheme, $token);
        $logged_in_cookie = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);
        /**
         * Fires immediately before the authentication cookie is set.
         *
         * @since 2.5.0
         * @since 4.9.0 The `$token` parameter was added.
         *
         * @param string $auth_cookie Authentication cookie value.
         * @param int    $expire      The time the login grace period expires as a UNIX timestamp.
         *                            Default is 12 hours past the cookie's expiration time.
         * @param int    $expiration  The time when the authentication cookie expires as a UNIX timestamp.
         *                            Default is 14 days from now.
         * @param int    $user_id     User ID.
         * @param string $scheme      Authentication scheme. Values include 'auth' or 'secure_auth'.
         * @param string $token       User's session token to use for this cookie.
         */
        do_action('set_auth_cookie', $auth_cookie, $expire, $expiration, $user_id, $scheme, $token);
        /**
         * Fires immediately before the logged-in authentication cookie is set.
         *
         * @since 2.6.0
         * @since 4.9.0 The `$token` parameter was added.
         *
         * @param string $logged_in_cookie The logged-in cookie value.
         * @param int    $expire           The time the login grace period expires as a UNIX timestamp.
         *                                 Default is 12 hours past the cookie's expiration time.
         * @param int    $expiration       The time when the logged-in authentication cookie expires as a UNIX timestamp.
         *                                 Default is 14 days from now.
         * @param int    $user_id          User ID.
         * @param string $scheme           Authentication scheme. Default 'logged_in'.
         * @param string $token            User's session token to use for this cookie.
         */
        do_action('set_logged_in_cookie', $logged_in_cookie, $expire, $expiration, $user_id, 'logged_in', $token);
        /**
         * Allows preventing auth cookies from actually being sent to the client.
         *
         * @since 4.7.4
         *
         * @param bool $send Whether to send auth cookies to the client.
         */
        if (!apply_filters('send_auth_cookies', true)) {
            return;
        }
        $base_options = [
            'expires' => $expire,
            'domain' => COOKIE_DOMAIN,
            'httponly' => true,
            'samesite' => defined('WP_SAMESITE_COOKIE') ? WP_SAMESITE_COOKIE : 'None',
        ]; // httponly is added at samesite_setcookie();
        instapunchout_setcookie($auth_cookie_name, $auth_cookie, $base_options + ['secure' => $secure, 'path' => PLUGINS_COOKIE_PATH]);
        instapunchout_setcookie($auth_cookie_name, $auth_cookie, $base_options + ['secure' => $secure, 'path' => ADMIN_COOKIE_PATH]);
        instapunchout_setcookie(LOGGED_IN_COOKIE, $logged_in_cookie, $base_options + ['secure' => $secure_logged_in_cookie, 'path' => COOKIEPATH]);
        if (COOKIEPATH != SITECOOKIEPATH) {
            samesite_setcookie(LOGGED_IN_COOKIE, $logged_in_cookie, $base_options + ['secure' => $secure_logged_in_cookie, 'path' => SITECOOKIEPATH]);
        }
    }
endif;
/**
 * @internal Function to mimic setcookie() function behaviour without PHP 7.3 as
 *  as a requirement to set SameSite flag. This function does not handle exceptional
 *  cases well (to keep its functionality minimal); Do not use for any other purpose.
 * @param $name
 * @param $value
 * @param array $options
 */
function instapunchout_setcookie($name, $value, array $options)
{
    $header = 'Set-Cookie:';
    $header .= rawurlencode($name) . '=' . rawurlencode($value) . ';';
    if (!empty($options['expires']) && $options['expires'] > 0) {
        $header .= 'expires=' . \gmdate('D, d-M-Y H:i:s T', (int) $options['expires']) . ';';
        $header .= 'Max-Age=' . max(0, (int) ($options['expires'] - time())) . ';';
    }
    $header .= 'path=' . rawurlencode($options['path']) . ';';
    $header .= 'domain=' . rawurlencode($options['domain']) . ';';
    if (!empty($options['secure'])) {
        $header .= 'secure;';
    }
    $header .= 'httponly;';
    $header .= 'SameSite=' . rawurlencode($options['samesite']);
    header($header, false);
    $_COOKIE[$name] = $value;
}
