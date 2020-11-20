<?php

namespace LicenseManagerForWooCommerce\Integrations\WooCommerce;

use LicenseManagerForWooCommerce\Integrations\WooCommerce\Emails\CustomerDeliverLicenseKeys;
use LicenseManagerForWooCommerce\Integrations\WooCommerce\Emails\CustomerPreorderComplete;
use LicenseManagerForWooCommerce\Integrations\WooCommerce\Emails\Templates;
use LicenseManagerForWooCommerce\Enums\LicenseStatus;
use LicenseManagerForWooCommerce\Repositories\Resources\License as LicenseResourceRepository;
use LicenseManagerForWooCommerce\Settings;
use WC_Email;
use WC_Order;

defined('ABSPATH') || exit;

class Email
{
    /**
     * OrderManager constructor.
     */
    public function __construct() {
        add_action('woocommerce_email_after_order_table', array($this, 'afterOrderTable'), 10, 4);
        add_action('woocommerce_email_classes',           array($this, 'registerClasses'), 90, 1);
        add_action('woocommerce_reduce_order_stock',      array($this, 'lowStockEmail'),   10, 1);
    }

    /**
     * Adds the bought license keys to the "Order complete" email, or displays a notice - depending on the settings.
     *
     * @param WC_Order $order
     * @param bool     $isAdminEmail
     * @param bool     $plainText
     * @param WC_Email $email
     */
    public function afterOrderTable($order, $isAdminEmail, $plainText, $email)
    {
        // Return if the order isn't complete.
        if ($order->get_status() !== 'completed'
            && !get_post_meta($order->get_id(), 'lmfwc_order_complete')
        ) {
            return;
        }

        if (!$data = apply_filters('lmfwc_get_customer_license_keys', $order)) {
            return;
        }

        if (Settings::get('lmfwc_auto_delivery')) {
            // Send the keys out if the setting is active.
            if ($plainText) {
                echo wc_get_template(
                    'emails/plain/lmfwc-email-order-license-keys.php',
                    array(
                        'heading'       => apply_filters('lmfwc_license_keys_table_heading', null),
                        'valid_until'   => apply_filters('lmfwc_license_keys_table_valid_until', null),
                        'data'          => $data,
                        'date_format'   => get_option('date_format'),
                        'order'         => $order,
                        'sent_to_admin' => $isAdminEmail,
                        'plain_text'    => true,
                        'email'         => $email,
                        'args'          => apply_filters('lmfwc_template_args_emails_email_order_license_keys', array())
                    ),
                    '',
                    LMFWC_TEMPLATES_DIR
                );
            }

            else {
                echo wc_get_template_html(
                    'emails/lmfwc-email-order-license-keys.php',
                    array(
                        'heading'       => apply_filters('lmfwc_license_keys_table_heading', null),
                        'valid_until'   => apply_filters('lmfwc_license_keys_table_valid_until', null),
                        'data'          => $data,
                        'date_format'   => get_option('date_format'),
                        'order'         => $order,
                        'sent_to_admin' => $isAdminEmail,
                        'plain_text'    => false,
                        'email'         => $email,
                        'args'          => apply_filters('lmfwc_template_args_emails_email_order_license_keys', array())
                    ),
                    '',
                    LMFWC_TEMPLATES_DIR
                );
            }
        }

        else {
            // Only display a notice.
            if ($plainText) {
                echo wc_get_template(
                    'emails/plain/lmfwc-email-order-license-notice.php',
                    array(
                        'args' => apply_filters('lmfwc_template_args_emails_email_order_license_notice', array())
                    ),
                    '',
                    LMFWC_TEMPLATES_DIR
                );
            }

            else {
                echo wc_get_template_html(
                    'emails/lmfwc-email-order-license-notice.php',
                    array(
                        'args' => apply_filters('lmfwc_template_args_emails_email_order_license_notice', array())
                    ),
                    '',
                    LMFWC_TEMPLATES_DIR
                );
            }

            include LMFWC_TEMPLATES_DIR . '';
        }
    }

    /**
     * Registers the plugin email classes to work with WooCommerce.
     *
     * @param array $emails
     *
     * @return array
     */
    public function registerClasses($emails)
    {
        new Templates();

        $pluginEmails = array(
            //'LMFWC_Customer_Preorder_Complete'    => new CustomerPreorderComplete(),
            'LMFWC_Customer_Deliver_License_Keys' => new CustomerDeliverLicenseKeys()
        );

        return array_merge($emails, $pluginEmails);
    }

    /**
     * Low stock notification email.
     *
     * @param WC_Order $order
     *
     * @return void
     */
    public function lowStockEmail($order) {

        if (! $order instanceof WC_Order) {
            return;
        }

        $products = $order->get_items();

        foreach($products as $item) {
            $product = $item->get_product();

            if ( ! $product ) {
                continue;
            }

            $isActive = $product->get_meta('lmfwc_licensed_product_notify_low_stock', true);

            // Bail early, in case the notification is disabled.
            if (!$isActive) {
                continue;
            }

            $threshold   = (int) $product->get_meta('lmfwc_licensed_product_notify_low_stock_amount', true);
            $stockAmount = (int) LicenseResourceRepository::instance()->countBy(array('product_id' => $product->get_id(), 'status' => LicenseStatus::ACTIVE));

            // Bail early, in case we have enough license key in stock.
            if ( $stockAmount > $threshold ) {
                continue;
            }

            $header  = sprintf( 'From: %s <%s>', wp_specialchars_decode(get_option('woocommerce_email_from_name'), ENT_QUOTES), sanitize_email(get_option('woocommerce_email_from_address')));
            $subject = sprintf( '[%s] %s', wp_specialchars_decode(get_option('blogname'), ENT_QUOTES), __('License low in stock', 'license-manager-for-woocommerce'));
            $message = sprintf(
                /* translators: 1: product name 2: items in stock */
                __('%1$s is low in license stock. There are %2$d left.', 'license-manager-for-woocommerce'),
                html_entity_decode(wp_strip_all_tags($product->get_formatted_name()), ENT_QUOTES, get_bloginfo('charset')),
                html_entity_decode(wp_strip_all_tags($stockAmount))
            );

            wp_mail(
                apply_filters('lmfwc_email_recipient_low_stock', get_option('woocommerce_stock_email_recipient'), $product, null),
                apply_filters('lmfwc_email_subject_low_stock', $subject, $product, null),
                apply_filters('lmfwc_email_content_low_stock', $message, $product),
                apply_filters('lmfwc_email_headers_low_stock', $header, 'low_stock', $product, null),
                apply_filters('lmfwc_email_attachments_low_stock', array(), 'low_stock', $product, null)
            );
        }

    }
}