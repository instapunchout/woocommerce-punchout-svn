<?php

/**
 * @package insta_Punchout
 * @version 3.0
 */
/*
Plugin Name: InstaPunchout
Description: This is the punchout plugin which is created by InstaPunchout.
Author: InstaPunchout
Version: 1.0.34

 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}


function instapunchout_order_status_changed($order_id, $old_status, $new_status)
{
    try {
        $data = [
            "site_url" => get_site_url(),
            "order_id" => $order_id,
            "old_status" => $old_status,
            "new_status" => $new_status,
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
            "order_id" => $order_id
        ]);
    }
}

add_action('woocommerce_order_status_changed', 'instapunchout_order_status_changed', 10, 3);

/// uxload

add_action('wp_head', 'instapunchout_ux_load');
function instapunchout_ux_load()
{
    $user_id = get_current_user_id();
    $punchout_id = get_user_meta($user_id, 'punchout_id', true);

    if ($user_id && $punchout_id) {
        $response = wp_remote_get('https://punchout.cloud/punchout.js?id=' . $punchout_id, 'json', 'javascript');
        $body = wp_remote_retrieve_body($response);
        if (function_exists('wp_print_inline_script_tag')) {
            wp_print_inline_script_tag($body);
        } else {
            echo sprintf("<script type=\"text/javascript\">%s</script>\n", $body);
        }
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

        if (strpos($url, '%2Fpunchout')) {
            $url = str_replace("%2F", "/", $url);
        }

        $index = strpos($url, '/punchout/');

        $cart_path = "/punchout/api/cart";
        $order_path_json = "/punchout/api/order.json";
        $cart_path_json = "/punchout/api/cart.json";
        $products_path_json = "/punchout/api/products.json";
        $options_path_json = "/punchout/api/options.json";
        $punchout_path = substr($url, $index);

        if (substr($url, $index, strlen($options_path_json)) === $options_path_json) {
            $data = [];

            global $wp_roles;
            $all_roles = $wp_roles->roles;
            $roles = [];
            foreach ($all_roles as $key => $value) {
                array_push($roles, ['label' => $value['name'], 'value' => $key]);
            }
            $data['roles'] = $roles;

            if (class_exists(Groups_Group)) {
                $groups = [];
                foreach (Groups_Group::get_groups() as $group) {
                    array_push($groups, ['label' => $group->name, 'value' => $group->group_id]);
                }
                $data['groups'] = $groups;
            }

            // Support WP-Memberships Plugin
            if (function_exists('wpmem_get_memberships')) {
                $memberships_data = wpmem_get_memberships();
                if ($memberships_data) {
                    $memberships = [];
                    foreach ($memberships_data as $membership) {
                        array_push($memberships, ['label' => $membership['title'], 'value' => $membership['name']]);
                    }
                    $data['memberships'] = $memberships;
                }
            }

            echo json_encode($data);
            exit;
        } else if (substr($url, $index, strlen($products_path_json)) === $products_path_json) {
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
                // wp_logout();
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

                $user = instapunchout_prepare_user($res);

                // check if user is admin or super admin
                if (in_array('administrator', $user->roles) || in_array('super_admin', $user->roles)) {
                    die('You are not allowed to login as an admin');
                }

                if (isset($res['properties']['role'])) {
                    $role = $res['properties']['role'];
                    if (!in_array($role, (array) $user->roles)) {
                        $user->add_role($role);
                    }
                }

                if (isset($res['properties']['groups'])) {
                    $user_id = $user->ID;
                    if (class_exists(Groups_Group) && class_exists(Groups_User_Group)) {
                        $groups = Groups_Group::get_groups();
                        $user_group_ids = $res['properties']['groups'];
                        foreach ($groups as $group) {
                            if (in_array($group->group_id, $user_group_ids)) {
                                if (!Groups_User_Group::read($user_id, $group->group_id)) {
                                    Groups_User_Group::create(array('user_id' => $user_id, 'group_id' => $group->group_id));
                                }
                            } else {
                                if (Groups_User_Group::read($user_id, $group->group_id)) {
                                    Groups_User_Group::delete($user_id, $group->group_id);
                                }
                            }
                        }
                    }
                }

                // Support WP-Memberships Plugin
                if (function_exists('wpmem_set_user_membership') && isset($res['properties']['memberships'])) {
                    foreach ($res['properties']['memberships'] as $membership) {
                        wpmem_set_user_membership($membership, $user->ID);
                    }
                }

                // empty cart
                WC()->cart->empty_cart();
                if ($user instanceof WP_User) {
                    wp_clear_auth_cookie();
                    update_user_meta($user->ID, 'punchout_id', $res['punchout_id']);
                    wp_set_current_user($user->ID);
                    if (isset($res['properties']['custom_set_auth_cookie']) && $res['properties']['custom_set_auth_cookie'] == true) {
                        instapunchout_wp_set_auth_cookie($user->ID);
                    } else {
                        wp_set_auth_cookie($user->ID, false);
                    }
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

                $cart[$key]['product'] = $_product->get_data();

                // get product and its parent attributes
                $cart[$key]['product']['attributes'] = instapunchout_get_product_attributes($_product);

                $categories = get_the_terms($_product->get_id(), 'product_cat');
                if ($categories) {
                    $cart[$key]['product']['categories'] = $categories;
                }

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

function instapunchout_get_product_attributes($_product)
{
    $attributes = [];
    foreach ($_product->get_attributes() as $name => $attribute) {
        $value = null;
        if (method_exists($attribute, 'get_slugs')) {
            $value = $attribute->get_slugs();
        } else if (!is_object($attribute)) {
            $value = $attribute;
        } else {
            $value = json_encode($attribute);
        }
        array_push($attributes, ['name' => $name, 'value' => $value]);
    }

    $parent_id = $_product->get_parent_id();
    if ($parent_id) {
        $_pf = new WC_Product_Factory();
        $_product = $_pf->get_product($parent_id);

        $parent_attributes = instapunchout_get_product_attributes($_product);
        $attributes = array_merge($attributes, $parent_attributes);
    }
    return $attributes;
}

function instapunchout_prepare_user($res)
{
    $email = $res['email'];
    $user = get_user_by('email', $email);
    if (!$user) {
        // fix for nonce_verification_failed caused by Dokan Plugin (dokan-lite)
        add_filter('dokan_register_nonce_check', '__return_false');

        $user_id = wc_create_new_customer($email, $res['username'], $res['password'], $res['properties']);
        if (is_wp_error($user_id)) {
            die("Failed to create user " . var_dump($user_id));
        }
        $user = get_user_by('email', $email);
    }
    return $user;
}

function instapunchout_create_order($data)
{
    $email = $data['customer_email'];
    $user = instapunchout_prepare_user($data['customer']);
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
        echo json_encode(["error" => "user doesn't exist " . $email]);
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


add_filter('woocommerce_set_cookie_options', 'instapunchout_woocommerce_set_cookie_options_filter', 10, 3);
function instapunchout_woocommerce_set_cookie_options_filter($cookie_options, $name, $value)
{
    $cookie_options['secure'] = 1;
    $cookie_options['samesite'] = 'None';
    return $cookie_options;
}


function instapunchout_wp_set_auth_cookie($user_id)
{
    $expiration = time() + apply_filters('auth_cookie_expiration', 2 * DAY_IN_SECONDS, $user_id, false);
    $expire = 0;
    $auth_cookie_name = SECURE_AUTH_COOKIE;
    $scheme = 'secure_auth';

    $manager = WP_Session_Tokens::get_instance($user_id);
    $token = $manager->create($expiration);

    $auth_cookie = wp_generate_auth_cookie($user_id, $expiration, $scheme, $token);
    $logged_in_cookie = wp_generate_auth_cookie($user_id, $expiration, 'logged_in', $token);

    do_action('set_auth_cookie', $auth_cookie, $expire, $expiration, $user_id, $scheme, $token);
    do_action('set_logged_in_cookie', $logged_in_cookie, $expire, $expiration, $user_id, 'logged_in', $token);


    instapunchout_setcookie($auth_cookie_name, $auth_cookie);
    instapunchout_setcookie(LOGGED_IN_COOKIE, $logged_in_cookie);
}

function instapunchout_setcookie($name, $value)
{
    $date = date("D, d M Y H:i:s", time() + 3600 * 48) . 'GMT';
    header("Set-Cookie: {$name}={$value}; EXPIRES{$date};SameSite=None;Secure;HttpOnly");
}
