<?php

/**
 * @package insta_Punchout
 * @version 3.0
 */
/*
Plugin Name: InstaPunchout
Description: This is the punchout plugin which is created by InstaPunchout.
Author: InstaPunchout
Version: 1.0.14

 */
if (!session_id()) {
    session_start();
}
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
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

$instapunchout_order_status_sent = false;
function instapunchout_order_status_changed($order_id, $old_status, $new_status, $order)
{

    try {
        if (isset($instapunchout_order_status_sent) && $instapunchout_order_status_sent) {
            return;
        }
        $instapunchout_order_status_sent = true;
        $data = [
            "site_url" => get_site_url(),
            "order_id" => $order_id,
            "old_status" => $old_status,
            "new_status" => $new_status,
            "order" => $order,
        ];
        $request = new WP_REST_Request('GET', '/wc/v3/orders/' . $order_id);
        $response = rest_do_request($request);
        $server = rest_get_server();
        $order = $server->response_to_data($response, false);
        $data["order"] = $order;

        return instapunchout_post_json('https://punchout.cloud/api/v1/plugins/woocommerce-order-status-changed', $data);

        //code...
    } catch (\Throwable $th) {
        return instapunchout_post_json('https://punchout.cloud/log', [
            "error" => "failed to callback woocommerce-order-status-changed",
            "message" => $th->getMessage(),
            "data" => [$order_id, $old_status, $new_status, $order, $data]
        ]);
    }
}

add_action('added_user_meta', 'instapunchout_user_meta_updated', 20, 4);
add_action('update_user_meta', 'instapunchout_user_meta_updated', 20, 4);
add_action('woocommerce_order_status_changed', 'instapunchout_order_status_changed', 10, 3);
add_action('woocommerce_order_edit_status', 'instapunchout_order_status_changed', 10, 3);
add_action('woocommerce_order_status_pending', 'instapunchout_order_status_changed');
add_action('woocommerce_order_status_failed', 'instapunchout_order_status_changed');
add_action('woocommerce_order_status_on-hold', 'instapunchout_order_status_changed');
add_action('woocommerce_order_status_processing', 'instapunchout_order_status_changed');
add_action('woocommerce_order_status_completed', 'instapunchout_order_status_changed');
add_action('woocommerce_order_status_refunded', 'instapunchout_order_status_changed');
add_action('woocommerce_order_status_cancelled', 'instapunchout_order_status_changed');
add_action('woocommerce_update_order', 'instapunchout_order_status_changed', 10, 2);


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
    $args    = apply_filters('woocommerce_product_object_query_args', $vars);
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
                if (isset($_GET["authorization_header"])) {
                    $authorization_header = $_GET["authorization_header"];
                }
                $res = instapunchout_post_json('https://punchout.cloud/authorize', ["authorization" => $authorization_header]);
                if ($res["authorized"] == true) {
                    instapunchout_create_order(json_decode(file_get_contents('php://input'), true));
                } else {
                    echo json_encode(["error" => "You're not authorized", "error_data" => $res, "header" => $authorization_header]);
                }
            } catch (Exception $e) {
                echo var_dump(e);
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
        echo var_dump($e);
        exit();
    }
}

function instapunchout_create_order($data)
{
    $email = $data['customer_email'];
    $user = get_user_by('email', $email);
    $admin = get_users(array(
        'role__in' => 'administrator',
        'fields'   => 'ID',
    ))[0];

    if ($user instanceof WP_User) {
        wp_clear_auth_cookie();
        wp_set_current_user($admin);
        wp_set_auth_cookie($admin);
        $data['customer_id'] = $user->ID;
    } else {
        echo json_decode(["error" => "user doesn't exist " . $data['customer_email']]);
        exit();
    }

    $request   = new WP_REST_Request('POST', '/wc/v3/orders');
    $request->set_header('content-type', 'application/json');
    $request->set_body(json_encode($data));
    $response = rest_do_request($request);
    $server = rest_get_server();
    $order = $server->response_to_data($response, false);
    echo json_encode($order);
}
