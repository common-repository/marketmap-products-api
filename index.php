<?php

/*
Plugin Name: Marketmap Products API
Description: افزونه لیست محصولات ووکامرس برای مارکت مپ
Author: Marketmap
Version: 1.1.2
Author URI: https://marketmap.org/
License: MIT
License URI: https://opensource.org/licenses/MIT
Text Domain: marketmap-products-api
*/

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check WooCommerce plugin is activated
 */
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {


    class WC_BL_Products_Extractor_Controller extends WP_REST_Controller
    {
        private $bl_plugin_version = "1.1.2";
        private $bl_plugin_slug = "Marketmap-products-api/index.php";

        /**
         * The namespace.
         *
         * @var string
         */
        protected $namespace;

        /**
         * Rest base for the current object.
         *
         * @var string
         */
        protected $rest_base;

        /**
         * Category_List_Rest constructor.
         */
        public function __construct()
        {
            $this->namespace = 'wm/v1';
            $this->rest_base = 'products';
        }

        /**
         * Check for new updates
         * Auto upgrade plugin if have new version
         */
        private function auto_update()
        {
            $result = FALSE;
            try {
                ob_start(function () {
                    return '';
                });
                include_once ABSPATH . '/wp-admin/includes/file.php';
                include_once ABSPATH . '/wp-admin/includes/misc.php';
                include_once ABSPATH . '/wp-includes/pluggable.php';
                include_once ABSPATH . '/wp-admin/includes/plugin.php';
                include_once ABSPATH . '/wp-admin/includes/class-wp-upgrader.php';
                if (is_plugin_active($this->bl_plugin_slug)) {
                    $upgrader = new Plugin_Upgrader();
                    $result = $upgrader->upgrade($this->bl_plugin_slug);
                    activate_plugin($this->bl_plugin_slug);
                }
                @ob_end_clean();
            } catch (Exception $e) {
                activate_plugin($this->bl_plugin_slug);
            }
            return $result;
        }

        /**
         * Check update and validate the request
         * @param request
         * @return wp_safe_remote_post
         */
        public function check_request($request)
        {
            // Check and update plugin for first request
            if (!empty($request->get_param('auto_update'))) {
                $update_switch = rest_sanitize_boolean($request->get_param('auto_update'));
            } else {
                $update_switch = TRUE;
            }
            if ($update_switch) {
                if ($this->auto_update()) {
                    exit();
                }
            }


            // Marketmap token validation
            $endpoint_url = 'https://api.marketmap.org/token-validation/';

            // Get parameters
            $token = sanitize_text_field($request->get_param('token'));
            $link = sanitize_text_field($request->get_param('link'));
            $params = array('token' => $token, 'link' => $link);

            $result = wp_remote_get(  $endpoint_url . '?' . http_build_query($params) );

            $response = array();
            $response['body'] = $result['body'];

            return $response;
        }

        /**
         * Register rout: https://example.com/wp-json/wm/v1/products
         */
        public function register_routes()
        {
            register_rest_route($this->namespace, '/' . $this->rest_base, array(
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array(
                        $this,
                        'get_products'
                    ),
                    'permission_callback' => '__return_true',
                    'args' => array()
                ),
            ));

            register_rest_route($this->namespace, '/' . $this->rest_base . '/categories', array(
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array(
                        $this,
                        'get_categories'
                    ),
                    'permission_callback' => '__return_true',
                    'args' => array()
                ),
            ));

            register_rest_route($this->namespace, '/' . $this->rest_base . '/link', array(
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array(
                        $this,
                        'get_product_by_link'
                    ),
                    'permission_callback' => '__return_true',
                    'args' => array()
                ),
            ));

            register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<product_id>\d+)/variations', array(
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array(
                        $this,
                        'get_product_variation'
                    ),
                    'permission_callback' => '__return_true',
                    'args' => array()
                ),
            ));

            register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<product_id>\d+)', array(
                array(
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => array(
                        $this,
                        'get_product_by_id'
                    ),
                    'permission_callback' => '__return_true',
                    'args' => array()
                ),
            ));
        }

        /**
         * Get All of products
         * @param $request
         * @return WP_REST_Response
         *
         * rout: https://example.com/wp-json/wm/v1/products
         */
        function get_products($request)
        {
            // Check request is valid and update
            $response = $this->check_request($request);

            if (!$response) {
                $data['Response'] = '';
                $data['Error'] = 'Error';
                $response_code = 500;
            } else {
                $response = json_decode($response['body'], true);
                if ($response['data']['message'] === 'successfull') {

                    $result = array();
                    $index = 0;


                    //////////////////////////////////// products filter params
                    $order = $request->get_param('order');
                    $orderby = $request->get_param('orderby');
                    $category = $request->get_param('category');
                    $title = $request->get_param('title');
                    $min_price = $request->get_param('min_price');
                    $max_price = $request->get_param('max_price');
                    $stock_status = $request->get_param('stock_status');
                    $attribute = $request->get_param('attribute');
                    $attribute_value = $request->get_param('attribute_value');
                    $slug = $request->get_param('slug');
                    $per_page = $request->get_param('per_page');
                    $page = $request->get_param('page');
                    $offset = $request->get_param('offset');
                    $category_id = $request->get_param('category_id');
                    //////////////////////////////////////////

                    if($orderby == 'price') $orderby = "meta_value_num";

                    $arg1 = array(
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'order' => $order, //DESC, ASC
                        'orderby' => $orderby, //date, id, include, title, slug, price, popularity, rating
                        'meta_key' => '_price',
                        'product_cat' => $category,
                        'title' => $title,
                        'meta_query' => array(
                            isset($min_price) && isset($max_price) ?
                                array(
                                    'key' => '_regular_price',
                                    'value' => array($min_price, $max_price),
                                    'compare' => 'between',
                                    'type' => 'numeric'
                                )
                                : null,
                            isset($min_price) ?
                                array(
                                    'key' => '_regular_price',
                                    'value' => $min_price,
                                    'compare' => '>=',
                                    'type' => 'numeric'
                                )
                                : null,
                            isset($max_price) ?
                                array(
                                    'key' => '_regular_price',
                                    'value' => $max_price,
                                    'compare' => '<=',
                                    'type' => 'numeric'
                                )
                                : null,
                            isset($stock_status) ?
                                array(
                                    'key' => '_stock_status',
                                    'value' => $stock_status,//instock, outofstock, onbackorder
                                )
                                : null,
                        ),
                        'tax_query' => array(
                            isset($attribute) && isset($attribute_value) ?
                                array(
                                    'taxonomy' => 'pa_' . $attribute,
                                    'terms' => explode(",", $attribute_value),
                                    'field' => 'slug',
                                    'operator' => 'IN'
                                ) : null,
                            isset($category_id) ?
                                array(
                                    'taxonomy' => 'product_cat', //double check your taxonomy name in you dd
                                    'field'    => 'id',
                                    'terms'    => $category_id,
                                ) : null,
                        ),
                        'name' => $slug
                    );

                    $productss = new WP_Query($arg1);
                    $product_count = $productss->found_posts;


                    $arg = array(
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'order' => $order, //DESC, ASC
                        'orderby' => $orderby, //date, id, include, title, slug, price, popularity, rating
                        'meta_key' => '_price',
                        'posts_per_page' => $per_page,
                        'product_cat' => $category,
                        'paged' => $page,
                        'offset' => $offset,
                        'title' => $title,
                        'meta_query' => array(
                            isset($min_price) && isset($max_price) ?
                                array(
                                    'key' => '_regular_price',
                                    'value' => array($min_price, $max_price),
                                    'compare' => 'between',
                                    'type' => 'numeric'
                                )
                                : null,
                            isset($min_price) ?
                                array(
                                    'key' => '_regular_price',
                                    'value' => $min_price,
                                    'compare' => '>=',
                                    'type' => 'numeric'
                                )
                                : null,
                            isset($max_price) ?
                                array(
                                    'key' => '_regular_price',
                                    'value' => $max_price,
                                    'compare' => '<=',
                                    'type' => 'numeric'
                                )
                                : null,
                            isset($stock_status) ?
                                array(
                                    'key' => '_stock_status',
                                    'value' => $stock_status,//instock, outofstock, onbackorder
                                )
                                : null,
                        ),
                        'tax_query' => array(
                            isset($attribute) && isset($attribute_value) ?
                                array(
                                    'taxonomy' => 'pa_' . $attribute,
                                    'terms' => explode(",", $attribute_value),
                                    'field' => 'slug',
                                    'operator' => 'IN'
                                ) : null,
                            isset($category_id) ?
                                array(
                                    'taxonomy' => 'product_cat', //double check your taxonomy name in you dd
                                    'field'    => 'id',
                                    'terms'    => $category_id,
                                ) : null,
                        ),
                        'name' => $slug
                    );

                    $products = new WP_Query($arg);
                    $products = (array)$products->posts;

                    foreach ($products as $product) {
                        $id = $product->ID;
                        $result[$index]["id"] = $product->ID;
                        $result[$index]["title"] = $product->post_title;
                        $result[$index]["thumbnail"] = get_the_post_thumbnail_url($id);
                        $result[$index]["price"] = get_post_meta($id, '_sale_price', true) == "" ? get_post_meta($id, '_regular_price', true) : get_post_meta($id, '_sale_price', true);

                        $product_variable = wc_get_product($id);
                        if ($product_variable)
                            if ($product_variable->is_type('variable')) {
                                $result[$index]["regular_price"] = $product_variable->get_variation_price();
                                $result[$index]["price"] = $product_variable->get_variation_price();
                            } else
                                $result[$index]["regular_price"] = get_post_meta($id, '_regular_price', true);

                        $result[$index]["sale_price"] = get_post_meta($id, '_sale_price', true);
                        $result[$index]["content"] = $product->post_content;
                        $result[$index]["name"] = get_post_meta($id, 'wc_ps_subtitle', true);
                        $result[$index]["guid"] = $product->guid;
                        $result[$index]["excerpt"] = $product->post_excerpt;
                        $result[$index]["stock_status"] = get_post_meta($id, '_stock_status', true);
                        $result[$index]["link"] = get_post_permalink($id);
                        $result[$index]["average_rating"] = get_post_meta($id, '_wc_average_rating', 'true');
                        $result[$index]["review_count"] = get_post_meta($id, '_wc_review_count', 'true');
                        $result[$index]["rating_count"] = get_post_meta($id, '_wc_rating_count', 'true');
                        $terms = get_the_terms($id, 'product_cat');
                        $indexCategory = 0;
                        if ($terms)
                            foreach ($terms as $term) {
                                $result[$index]["product_category"][$indexCategory]["id"] = $term->term_id;
                                $result[$index]["product_category"][$indexCategory]["name"] = $term->name;
                                $result[$index]["product_category"][$indexCategory]["slug"] = $term->slug;
                                $indexCategory++;
                            }

                        $argPrice = array(
                            'post_type' => 'product_variation',
                            'post_parent' => intval($id),
                            'post_status' => 'publish',
                            'orderby' => 'ID',
                            'order' => 'asc'
                        );
                        $products = new WP_Query($argPrice);
                        $products = (array)$products->posts;
                        $indexPrice = 0;
                        $resultPrice = array();

                        foreach ($products as $price) {

                            $attribute = get_post_meta($id, '_product_attributes', true);
                            foreach ($attribute as $attr) {
                                if ($attr["name"] == explode(':', $price->post_excerpt)[0]) {
                                    if ($attr["is_visible"] == 0) {
                                        $values = wc_get_product_terms($id, $attr["name"], array('fields' => 'names'))[$indexPrice];

                                        $resultPrice[$indexPrice]["id"] = $price->ID;
                                        $resultPrice[$indexPrice]["name"] = explode('_', $attr["name"])[1];
                                        $resultPrice[$indexPrice]["title"] = $values;
                                        $resultPrice[$indexPrice]["regular_price"] = get_post_meta($price->ID, '_regular_price', true);
                                        $resultPrice[$indexPrice]["sale_price"] = get_post_meta($price->ID, '_sale_price', true);
                                        $resultPrice[$indexPrice]["thumbnail"] = get_the_post_thumbnail_url($price->ID);

                                        break;

                                    }
                                }
                            }
                            $indexPrice++;
                        }

                        $result[$index]["variations"] = $resultPrice;


                        $prod_terms = get_the_terms($id, 'product_cat');
                        $brand_history = "";
                        if ($prod_terms)
                            foreach ($prod_terms as $prod_term) {
                                $product_cat_id = $prod_term->term_id;
                                $product_parent_categories_all_hierachy = get_ancestors($product_cat_id, 'product_cat');
                                $last_parent_cat = array_slice($product_parent_categories_all_hierachy, -1, 1, true);
                                foreach ($last_parent_cat as $last_parent_cat_value) {
                                    $brand_history .= category_description($last_parent_cat_value);
                                }
                            }

                        $result[$index]["brand_history"] = $brand_history;

                        $tags = array();
                        $indexTag = 0;
                        foreach (wp_get_post_terms($id, 'product_tag', array("fields" => "all")) as $tag) {
                            $tags[$indexTag]["id"] = $tag->term_id;
                            $tags[$indexTag]["name"] = $tag->name;
                            $tags[$indexTag]["slug"] = $tag->slug;

                            $indexTag++;
                        }
                        $result[$index]["tags"] = $tags;

                        $images = array();
                        $indexImages = 1;
                        $attachments = get_post_meta($id, '_product_image_gallery', true);
                        if ($attachments != null) {
                            $images[0]["id"] = intval($id);
                            $images[0]["title"] = $product->post_title;
                            $images[0]["content"] = $product->post_name;
                            $images[0]["guid"] = get_the_post_thumbnail_url($id);;

                            foreach (explode(',', $attachments) as $image) {
                                $imageinfp = get_post($image);
                                $images[$indexImages]["id"] = $imageinfp->ID;
                                $images[$indexImages]["title"] = $imageinfp->post_title;
                                $images[$indexImages]["content"] = $imageinfp->post_content;
                                $image = $imageinfp->guid;
                                $images[$indexImages]["guid"] = $image;

                                $indexImages++;
                            }
                        } else {
                            $images[0]["id"] = intval($id);
                            $images[0]["title"] = $product->post_title;
                            $images[0]["content"] = $product->post_name;
                            $images[0]["guid"] = get_the_post_thumbnail_url($id);;
                        }

                        $result[$index]["gallery"] = $images;


                        $attributes = array();
                        $indexAttribute = 0;
                        $attribute = get_post_meta($id, '_product_attributes', true);
                        if (isset($attributes)) {
                            foreach ($attribute as $attr) {
                                if ($attr["is_visible"] == 1) {
                                    $attributes[$indexAttribute]["name"] = wc_attribute_label($attr["name"]);

                                    $attributesValue = array();
                                    $indexAttributeValue = 0;
                                    $values = wc_get_product_terms($id, $attr["name"], array('fields' => 'names'));
                                    if ($values != null)
                                        foreach ($values as $value) {
                                            $attributesValue[$indexAttributeValue]["value"] = $value;
                                            $indexAttributeValue++;
                                        }
                                    else
                                        $attributesValue[$indexAttributeValue]["value"] = $attributes[$indexAttribute]["name"];

                                    $attributes[$indexAttribute]["values"] = $attributesValue;

                                    $indexAttribute++;
                                }

                            }
                            $result[$index]["attribute"] = $attributes;
                        }


                        $result[$index]['date_created'] = $product->post_date;
                        $result[$index]['date_modified'] = $product->post_modified;

                        $comment = get_comments(array('post_id' => $id));
                        $comments = array();
                        $commentIndex = 0;
                        foreach ($comment as $com) {
                            if ($com->comment_parent == "0") {
                                $comments[$commentIndex]["id"] = $com->comment_ID;
                                $comments[$commentIndex]["author"] = $com->comment_author;
                                $comments[$commentIndex]["email"] = $com->comment_author_email;
                                $comments[$commentIndex]["date"] = $com->comment_date;
                                $comments[$commentIndex]["content"] = $com->comment_content;
                                $comments[$commentIndex]["rating"] = get_comment_meta($com->comment_ID, 'rating', true);

                                $commentsAnswer = array();
                                $commentIndexAnswer = 0;
                                foreach ($comment as $comAnswer) {
                                    if ($comAnswer->comment_parent == $com->comment_ID) {
                                        $commentsAnswer[$commentIndexAnswer]["id"] = $comAnswer->comment_ID;
                                        $commentsAnswer[$commentIndexAnswer]["author"] = $comAnswer->comment_author;
                                        $commentsAnswer[$commentIndexAnswer]["email"] = $comAnswer->comment_author_email;
                                        $commentsAnswer[$commentIndexAnswer]["date"] = $comAnswer->comment_date;
                                        $commentsAnswer[$commentIndexAnswer]["content"] = $comAnswer->comment_content;
                                        $commentsAnswer[$commentIndexAnswer]["rating"] = get_comment_meta($comAnswer->comment_ID, 'rating', true);

                                        $commentIndexAnswer++;
                                    }
                                }

                                $comments[$commentIndex]["answer"] = $commentsAnswer;
                                $commentIndex++;
                            }
                        }


                        $result[$index]["comments"] = $comments;

                        $index++;
                    }

                    $data['data'] = $result;
                    $data['count'] = $product_count;

                    $response_code = 200;
                } else {
                    $data['Response'] = '';
                    $data['Error'] = $response;
                    $response_code = 401;
                }
            }
            $data['Version'] = $this->bl_plugin_version;
            return new WP_REST_Response($data, $response_code);
        }

        /**
         * Get product by id
         * @param $request
         * @return WP_REST_Response
         *
         * rout: https://example.com/wp-json/wm/v1/products/<product_id>
         */
        function get_product_by_id($request)
        {
            // Check request is valid and update
            $response = $this->check_request($request);

            if (!$response) {
                $data['Response'] = '';
                $data['Error'] = 'Error';
                $response_code = 500;
            }else{
                $response = json_decode($response['body'], true);
                if ($response['data']['message'] === 'successfull') {

                    $result = array();

                    $arg = array(
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'p' => $request->get_param('product_id')
                    );
                    $product = new WP_Query($arg);
                    $product = (array)$product->post;

                    $id = $request->get_param('product_id');
                    $result["id"] = $request->get_param('product_id');
                    $result["title"] = $product['post_title'];
                    $result["thumbnail"] = get_the_post_thumbnail_url($id);

                    $result["price"] = get_post_meta($id, '_sale_price', true) == "" ? get_post_meta($id, '_regular_price', true) : get_post_meta($id, '_sale_price', true);

                    $product_variable = wc_get_product($id);
                    if ($product_variable)
                        if ($product_variable->is_type('variable')) {
                            $result["regular_price"] = $product_variable->get_variation_price();
                            $result["price"] = $product_variable->get_variation_price();
                        } else
                            $result["regular_price"] = get_post_meta($id, '_regular_price', true);

                    $result["sale_price"] = get_post_meta($id, '_sale_price', true);
                    $result["content"] = $product['post_content'];
                    $result["name"] = get_post_meta($id, 'wc_ps_subtitle', true);
                    $result["guid"] = $product['guid'];
                    $result["excerpt"] = $product['post_excerpt'];
                    $result["stock_status"] = get_post_meta($id, '_stock_status', true);
                    $result["link"] = get_post_permalink($id);
                    $result["average_rating"] = get_post_meta($id, '_wc_average_rating', 'true');
                    $result["review_count"] = get_post_meta($id, '_wc_review_count', 'true');
                    $result["rating_count"] = get_post_meta($id, '_wc_rating_count', 'true');
                    $terms = get_the_terms($id, 'product_cat');
                    $indexCategory = 0;
                    if ($terms)
                        foreach ($terms as $term) {
                            $result["product_category"][$indexCategory]["id"] = $term->term_id;
                            $result["product_category"][$indexCategory]["name"] = $term->name;
                            $result["product_category"][$indexCategory]["slug"] = $term->slug;
                            $indexCategory++;
                        }

                    $argPrice = array(
                        'post_type' => 'product_variation',
                        'post_parent' => intval($id),
                        'post_status' => 'publish',
                        'orderby' => 'ID',
                        'order' => 'asc'
                    );
                    $products = new WP_Query($argPrice);
                    $products = (array)$products->posts;
                    $indexPrice = 0;
                    $resultPrice = array();

                    foreach ($products as $price) {

                        $attribute = get_post_meta($id, '_product_attributes', true);
                        foreach ($attribute as $attr) {
                            if ($attr["name"] == explode(':', $price->post_excerpt)[0]) {
                                if ($attr["is_visible"] == 0) {
                                    $values = wc_get_product_terms($id, $attr["name"], array('fields' => 'names'))[$indexPrice];

                                    $resultPrice[$indexPrice]["id"] = $price->ID;
                                    $resultPrice[$indexPrice]["name"] = explode('_', $attr["name"])[1];
                                    $resultPrice[$indexPrice]["title"] = $values;
                                    $resultPrice[$indexPrice]["regular_price"] = get_post_meta($price->ID, '_regular_price', true);
                                    $resultPrice[$indexPrice]["sale_price"] = get_post_meta($price->ID, '_sale_price', true);
                                    $resultPrice[$indexPrice]["thumbnail"] = get_the_post_thumbnail_url($price->ID);

                                    break;

                                }
                            }
                        }
                        $indexPrice++;
                    }

                    $result["variations"] = $resultPrice;


                    $prod_terms = get_the_terms($id, 'product_cat');
                    $brand_history = "";
                    if ($prod_terms)
                        foreach ($prod_terms as $prod_term) {
                            $product_cat_id = $prod_term->term_id;
                            $product_parent_categories_all_hierachy = get_ancestors($product_cat_id, 'product_cat');
                            $last_parent_cat = array_slice($product_parent_categories_all_hierachy, -1, 1, true);
                            foreach ($last_parent_cat as $last_parent_cat_value) {
                                $brand_history .= category_description($last_parent_cat_value);
                            }
                        }

                    $result["brand_history"] = $brand_history;

                    $tags = array();
                    $indexTag = 0;
                    foreach (wp_get_post_terms($id, 'product_tag', array("fields" => "all")) as $tag) {
                        $tags[$indexTag]["id"] = $tag->term_id;
                        $tags[$indexTag]["name"] = $tag->name;
                        $tags[$indexTag]["slug"] = $tag->slug;

                        $indexTag++;
                    }
                    $result["tags"] = $tags;

                    $images = array();
                    $indexImages = 1;
                    $attachments = get_post_meta($id, '_product_image_gallery', true);
                    if ($attachments != null) {
                        $images[0]["id"] = intval($id);
                        $images[0]["title"] = $product['post_title'];
                        $images[0]["content"] = $product['post_name'];
                        $images[0]["guid"] = get_the_post_thumbnail_url($id);;

                        foreach (explode(',', $attachments) as $image) {
                            $imageinfp = get_post($image);
                            $images[$indexImages]["id"] = $imageinfp->ID;
                            $images[$indexImages]["title"] = $imageinfp->post_title;
                            $images[$indexImages]["content"] = $imageinfp->post_content;
                            $image = $imageinfp->guid;
                            $images[$indexImages]["guid"] = $image;

                            $indexImages++;
                        }
                    } else {
                        $images[0]["id"] = intval($id);
                        $images[0]["title"] = $product['post_title'];
                        $images[0]["content"] = $product['post_name'];
                        $images[0]["guid"] = get_the_post_thumbnail_url($id);;
                    }

                    $result["gallery"] = $images;


                    $attributes = array();
                    $indexAttribute = 0;
                    $attribute = get_post_meta($id, '_product_attributes', true);
                    if (isset($attributes)) {
                        foreach ($attribute as $attr) {
                            if ($attr["is_visible"] == 1) {
                                $attributes[$indexAttribute]["name"] = wc_attribute_label($attr["name"]);

                                $attributesValue = array();
                                $indexAttributeValue = 0;
                                $values = wc_get_product_terms($id, $attr["name"], array('fields' => 'names'));
                                if ($values != null)
                                    foreach ($values as $value) {
                                        $attributesValue[$indexAttributeValue]["value"] = $value;
                                        $indexAttributeValue++;
                                    }
                                else
                                    $attributesValue[$indexAttributeValue]["value"] = $attributes[$indexAttribute]["name"];

                                $attributes[$indexAttribute]["values"] = $attributesValue;

                                $indexAttribute++;
                            }

                        }
                        $result["attribute"] = $attributes;
                    }


                    $result['date_created'] = $product['post_date'];
                    $result['date_modified'] = $product['post_modified'];

                    $comment = get_comments(array('post_id' => $id));
                    $comments = array();
                    $commentIndex = 0;
                    foreach ($comment as $com) {
                        if ($com->comment_parent == "0") {
                            $comments[$commentIndex]["id"] = $com->comment_ID;
                            $comments[$commentIndex]["author"] = $com->comment_author;
                            $comments[$commentIndex]["email"] = $com->comment_author_email;
                            $comments[$commentIndex]["date"] = $com->comment_date;
                            $comments[$commentIndex]["content"] = $com->comment_content;
                            $comments[$commentIndex]["rating"] = get_comment_meta($com->comment_ID, 'rating', true);

                            $commentsAnswer = array();
                            $commentIndexAnswer = 0;
                            foreach ($comment as $comAnswer) {
                                if ($comAnswer->comment_parent == $com->comment_ID) {
                                    $commentsAnswer[$commentIndexAnswer]["id"] = $comAnswer->comment_ID;
                                    $commentsAnswer[$commentIndexAnswer]["author"] = $comAnswer->comment_author;
                                    $commentsAnswer[$commentIndexAnswer]["email"] = $comAnswer->comment_author_email;
                                    $commentsAnswer[$commentIndexAnswer]["date"] = $comAnswer->comment_date;
                                    $commentsAnswer[$commentIndexAnswer]["content"] = $comAnswer->comment_content;
                                    $commentsAnswer[$commentIndexAnswer]["rating"] = get_comment_meta($comAnswer->comment_ID, 'rating', true);

                                    $commentIndexAnswer++;
                                }
                            }

                            $comments[$commentIndex]["answer"] = $commentsAnswer;
                            $commentIndex++;
                        }
                    }

                    $result["comments"] = $comments;

                    $data['data'] = $result;

                    $response_code = 200;
                } else {
                    $data['Response'] = '';
                    $data['Error'] = $response;
                    $response_code = 401;
                }
            }
            $data['Version'] = $this->bl_plugin_version;
            return new WP_REST_Response($data, $response_code);
        }

        /**
         * Get product categories
         * @param $request
         * @return WP_REST_Response
         *
         * rout: https://example.com/wp-json/wm/v1/products/categories
         */
        function get_categories($request)
        {
            // Check request is valid and update
            $response = $this->check_request($request);

            if (!$response) {
                $data['Response'] = '';
                $data['Error'] = 'Error';
                $response_code = 500;
            }else{
                $response = json_decode($response['body'], true);
                if ($response['data']['message'] === 'successfull') {

                    $response = array();
                    $index = 0;

                    $taxonomy = 'product_cat';
                    $orderby = 'name';
                    $show_count = 0;      // 1 for yes, 0 for no
                    $pad_counts = 0;      // 1 for yes, 0 for no
                    $hierarchical = 1;      // 1 for yes, 0 for no
                    $title = '';
                    $empty = 0;

                    $args = array(
                        'taxonomy' => $taxonomy,
                        'orderby' => $orderby,
                        'show_count' => $show_count,
                        'pad_counts' => $pad_counts,
                        'hierarchical' => $hierarchical,
                        'title_li' => $title,
                        'hide_empty' => $empty
                    );
                    $all_categories = get_categories($args);

                    foreach ($all_categories as $cat) {
                        if ($cat->category_parent == 0) {
                            $category_id = $cat->term_id;

                            $response[$index]['id'] = $category_id;
                            $response[$index]['slug'] = get_term_link($cat->slug, 'product_cat');
                            $response[$index]['name'] = $cat->name;

                            $args2 = array(
                                'taxonomy' => $taxonomy,
                                'child_of' => 0,
                                'parent' => $category_id,
                                'orderby' => $orderby,
                                'show_count' => $show_count,
                                'pad_counts' => $pad_counts,
                                'hierarchical' => $hierarchical,
                                'title_li' => $title,
                                'hide_empty' => $empty
                            );
                            $sub_cats = get_categories($args2);
                            if ($sub_cats) {
                                $response2 = array();
                                $index2 = 0;

                                foreach ($sub_cats as $sub_category) {

                                    $response2[$index2]['id'] = $sub_category->term_id;
                                    $response2[$index2]['slug'] = get_term_link($sub_category->slug, 'product_cat');
                                    $response2[$index2]['name'] = $sub_category->name;

                                    $index2++;
                                }

                                $response[$index]['sub_category'] = $response2;
                            }

                            $index++;
                        }
                    }

                    $data['data'] = $response;

                    $response_code = 200;
                } else {
                    $data['Response'] = '';
                    $data['Error'] = $response;
                    $response_code = 401;
                }
            }
            $data['Version'] = $this->bl_plugin_version;
            return new WP_REST_Response($data, $response_code);
        }

        /**
         * Get Product by url
         * @param $request
         * @return WP_REST_Response
         *
         * rout: https://example.com/wp-json/wm/v1/products/link
         */
        function get_product_by_link($request)
        {
            // Check request is valid and update
            $response = $this->check_request($request);

            if (!$response) {
                $data['Response'] = '';
                $data['Error'] = 'Error';
                $response_code = 500;
            }else{
                $response = json_decode($response['body'], true);
                if ($response['data']['message'] === 'successfull') {

                    $result = array();
                    $product = get_page_by_path(explode('/', $request->get_param('url'))[4], OBJECT, 'product');


                    $id = $product->ID;
                    $result["id"] = $product->ID;
                    $result["title"] = $product->post_title;
                    $result["thumbnail"] = get_the_post_thumbnail_url($id);

                    $result["price"] = get_post_meta($id, '_sale_price', true) == "" ? get_post_meta($id, '_regular_price', true) : get_post_meta($id, '_sale_price', true);

                    $product_variable = wc_get_product($id);
                    if ($product_variable)
                        if ($product_variable->is_type('variable')) {
                            $result["regular_price"] = $product_variable->get_variation_price();
                            $result["price"] = $product_variable->get_variation_price();
                        } else
                            $result["regular_price"] = get_post_meta($id, '_regular_price', true);

                    $result["sale_price"] = get_post_meta($id, '_sale_price', true);
                    $result["content"] = $product->post_content;
                    $result["name"] = get_post_meta($id, 'wc_ps_subtitle', true);
                    $result["guid"] = $product->guid;
                    $result["excerpt"] = $product->post_excerpt;
                    $result["stock_status"] = get_post_meta($id, '_stock_status', true);
                    $result["link"] = get_post_permalink($id);
                    $result["average_rating"] = get_post_meta($id, '_wc_average_rating', 'true');
                    $result["review_count"] = get_post_meta($id, '_wc_review_count', 'true');
                    $result["rating_count"] = get_post_meta($id, '_wc_rating_count', 'true');
                    $terms = get_the_terms($id, 'product_cat');
                    $indexCategory = 0;
                    if ($terms)
                        foreach ($terms as $term) {
                            $result["product_category"][$indexCategory]["id"] = $term->term_id;
                            $result["product_category"][$indexCategory]["name"] = $term->name;
                            $result["product_category"][$indexCategory]["slug"] = $term->slug;
                            $indexCategory++;
                        }

                    $argPrice = array(
                        'post_type' => 'product_variation',
                        'post_parent' => intval($id),
                        'post_status' => 'publish',
                        'orderby' => 'ID',
                        'order' => 'asc'
                    );
                    $products = new WP_Query($argPrice);
                    $products = (array)$products->posts;
                    $indexPrice = 0;
                    $resultPrice = array();
                    foreach ($products as $price) {
                        $attribute = get_post_meta($id, '_product_attributes', true);
                        foreach ($attribute as $attr) {
                            if ($attr["name"] == explode(':', $price->post_excerpt)[0]) {
                                if ($attr["is_visible"] == 0) {
                                    $values = wc_get_product_terms($id, $attr["name"], array('fields' => 'names'))[$indexPrice];

                                    $resultPrice[$indexPrice]["id"] = $price->ID;
                                    $resultPrice[$indexPrice]["name"] = explode('_', $attr["name"])[1];
                                    $resultPrice[$indexPrice]["title"] = $values;
                                    $resultPrice[$indexPrice]["regular_price"] = get_post_meta($price->ID, '_regular_price', true);
                                    $resultPrice[$indexPrice]["sale_price"] = get_post_meta($price->ID, '_sale_price', true);
                                    $resultPrice[$indexPrice]["thumbnail"] = get_the_post_thumbnail_url($price->ID);

                                    break;

                                }
                            }
                        }
                        $indexPrice++;
                    }

                    $result["variations"] = $resultPrice;


                    $prod_terms = get_the_terms($id, 'product_cat');
                    $brand_history = "";
                    if ($prod_terms)
                        foreach ($prod_terms as $prod_term) {
                            $product_cat_id = $prod_term->term_id;
                            $product_parent_categories_all_hierachy = get_ancestors($product_cat_id, 'product_cat');
                            $last_parent_cat = array_slice($product_parent_categories_all_hierachy, -1, 1, true);
                            foreach ($last_parent_cat as $last_parent_cat_value) {
                                $brand_history .= category_description($last_parent_cat_value);
                            }
                        }

                    $result["brand_history"] = $brand_history;

                    $tags = array();
                    $indexTag = 0;
                    foreach (wp_get_post_terms($id, 'product_tag', array("fields" => "all")) as $tag) {
                        $tags[$indexTag]["id"] = $tag->term_id;
                        $tags[$indexTag]["name"] = $tag->name;
                        $tags[$indexTag]["slug"] = $tag->slug;

                        $indexTag++;
                    }
                    $result["tags"] = $tags;

                    $images = array();
                    $indexImages = 1;
                    $attachments = get_post_meta($id, '_product_image_gallery', true);
                    if ($attachments != null) {
                        $images[0]["id"] = intval($id);
                        $images[0]["title"] = $product->post_title;
                        $images[0]["content"] = $product->post_name;
                        $images[0]["guid"] = get_the_post_thumbnail_url($id);;

                        foreach (explode(',', $attachments) as $image) {
                            $imageinfp = get_post($image);
                            $images[$indexImages]["id"] = $imageinfp->ID;
                            $images[$indexImages]["title"] = $imageinfp->post_title;
                            $images[$indexImages]["content"] = $imageinfp->post_content;
                            $image = $imageinfp->guid;
                            $images[$indexImages]["guid"] = $image;

                            $indexImages++;
                        }
                    } else {
                        $images[0]["id"] = intval($id);
                        $images[0]["title"] = $product->post_title;
                        $images[0]["content"] = $product->post_name;
                        $images[0]["guid"] = get_the_post_thumbnail_url($id);;
                    }

                    $result["gallery"] = $images;


                    $attributes = array();
                    $indexAttribute = 0;
                    $attribute = get_post_meta($id, '_product_attributes', true);
                    if ($attributes) {
                        foreach ($attribute as $attr) {
                            if ($attr["is_visible"] == 1) {
                                $attributes[$indexAttribute]["name"] = wc_attribute_label($attr["name"]);

                                $attributesValue = array();
                                $indexAttributeValue = 0;
                                $values = wc_get_product_terms($id, $attr["name"], array('fields' => 'names'));
                                if ($values != null)
                                    foreach ($values as $value) {
                                        $attributesValue[$indexAttributeValue]["value"] = $value;
                                        $indexAttributeValue++;
                                    }
                                else
                                    $attributesValue[$indexAttributeValue]["value"] = $attributes[$indexAttribute]["name"];

                                $attributes[$indexAttribute]["values"] = $attributesValue;

                                $indexAttribute++;
                            }

                        }
                        $result["attribute"] = $attributes;
                    }

                    $comment = get_comments(array('post_id' => $id));
                    $comments = array();
                    $commentIndex = 0;
                    foreach ($comment as $com) {
                        if ($com->comment_parent == "0") {
                            $comments[$commentIndex]["id"] = $com->comment_ID;
                            $comments[$commentIndex]["author"] = $com->comment_author;
                            $comments[$commentIndex]["email"] = $com->comment_author_email;
                            $comments[$commentIndex]["date"] = $com->comment_date;
                            $comments[$commentIndex]["content"] = $com->comment_content;
                            $comments[$commentIndex]["rating"] = get_comment_meta($com->comment_ID, 'rating', true);

                            $commentsAnswer = array();
                            $commentIndexAnswer = 0;
                            foreach ($comment as $comAnswer) {
                                if ($comAnswer->comment_parent == $com->comment_ID) {
                                    $commentsAnswer[$commentIndexAnswer]["id"] = $comAnswer->comment_ID;
                                    $commentsAnswer[$commentIndexAnswer]["author"] = $comAnswer->comment_author;
                                    $commentsAnswer[$commentIndexAnswer]["email"] = $comAnswer->comment_author_email;
                                    $commentsAnswer[$commentIndexAnswer]["date"] = $comAnswer->comment_date;
                                    $commentsAnswer[$commentIndexAnswer]["content"] = $comAnswer->comment_content;
                                    $commentsAnswer[$commentIndexAnswer]["rating"] = get_comment_meta($comAnswer->comment_ID, 'rating', true);

                                    $commentIndexAnswer++;
                                }
                            }

                            $comments[$commentIndex]["answer"] = $commentsAnswer;
                            $commentIndex++;
                        }
                    }


                    $result["comments"] = $comments;

                    $result['date_created'] = $product->post_date;
                    $result['date_modified'] = $product->post_modified;

                    $data['data'] = $result;

                    $response_code = 200;
                } else {
                    $data['Response'] = '';
                    $data['Error'] = $response;
                    $response_code = 401;
                }
            }
            $data['Version'] = $this->bl_plugin_version;
            return new WP_REST_Response($data, $response_code);
        }

        /**
         * Get product variations
         * <product_id?
         * @param $request
         * @return WP_REST_Response
         *
         * rout: https://example.com/wp-json/wm/v1/products/<product_id>/variations
         */
        function get_product_variation($request)
        {
            // Check request is valid and update
            $response = $this->check_request($request);

            if (!$response) {
                $data['Response'] = '';
                $data['Error'] = 'Error';
                $response_code = 500;
            }else{
                $response = json_decode($response['body'], true);
                if ($response['data']['message'] === 'successfull') {

                    $argPrice = array(
                        'post_type' => 'product_variation',
                        'post_parent' => intval($request->get_param('product_id')),
                        'post_status' => 'publish',
                        'orderby' => 'ID',
                        'order' => 'asc'
                    );
                    $products = new WP_Query($argPrice);
                    $products = (array)$products->posts;
                    $indexPrice = 0;
                    $resultPrice = array();
                    foreach ($products as $price) {
                        $attribute = get_post_meta($request->get_param('product_id'), '_product_attributes', true);
                        foreach ($attribute as $attr) {
                            if ($attr["name"] == explode(':', $price->post_excerpt)[0]) {
                                if ($attr["is_visible"] == 0) {
                                    $values = wc_get_product_terms($request->get_param('product_id'), $attr["name"], array('fields' => 'names'))[$indexPrice];

                                    $resultPrice[$indexPrice]["id"] = $price->ID;
                                    $resultPrice[$indexPrice]["name"] = explode('_', $attr["name"])[1];
                                    $resultPrice[$indexPrice]["title"] = $values;
                                    $resultPrice[$indexPrice]["regular_price"] = get_post_meta($price->ID, '_regular_price', true);
                                    $resultPrice[$indexPrice]["sale_price"] = get_post_meta($price->ID, '_sale_price', true);
                                    $resultPrice[$indexPrice]["thumbnail"] = get_the_post_thumbnail_url($price->ID);

                                    break;

                                }
                            }
                        }
                        $indexPrice++;
                    }

                    $data['data'] = $resultPrice;

                    $response_code = 200;
                } else {
                    $data['Response'] = '';
                    $data['Error'] = $response;
                    $response_code = 401;
                }
            }
            $data['Version'] = $this->bl_plugin_version;
            return new WP_REST_Response($data, $response_code);
        }
    }

    /*
     * Call controller and register routes
     */
    function register_BL_Products_Extractor_Controller()
    {
        $controller = new WC_BL_Products_Extractor_Controller();
        $controller->register_routes();
    }

    add_action('rest_api_init', 'register_BL_Products_Extractor_Controller');

} else {
    echo 'WooCommerce Plugin is not installed ot activated.';
}


?>