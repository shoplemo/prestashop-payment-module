<?php
/*
* 2007-2019 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

/**
 * @since 1.5.0
 */
class ShoplemoIframeModuleFrontController extends ModuleFrontController
{
    /**
     * @var Order
     */
    protected $order;

    /**
     * @var Customer
     */
    protected $customer;

    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
        {
            $this->errors = $this->l('An error occurred during the checkout process. Please try again.');
            $this->redirectWithNotifications($this->context->link->getPageLink('order'));
        }

        $this->module->validateOrder(
            $cart->id,
            Configuration::get('SHOPLEMO_AWAITING_PAYMENT'),
            0, // ödenen?
            $this->module->displayName,
            null,
            [],
            null,
            false,
            $cart->secure_key
        );

        // fixes the FrontController spamming the log
        $this->context->cookie->__unset('id_cart');

        $orderId = $this->module->currentOrder;
        $this->order = new Order($orderId);
        $this->customer = $this->order->getCustomer();
        $address = new Address((int) $cart->id_address_invoice);
        $orderItems = [];

        foreach ($this->order->getProducts() as $orderItem)
        {
            $orderItems[] = [
                'category' => 0,
                'name' => $orderItem['product_name'],
                'quantity' => $orderItem['product_quantity'],
                'type' => 1,
                'price' => number_format($orderItem['total_wt'] * 100, 2, '.', ''),
            ];
        }

        $totalShipping = $cart->getTotalShippingCost();

        if ($totalShipping > 0)
        {
            $orderItems[] = [
                'category' => 0,
                'name' => 'Kargo Ücreti',
                'quantity' => 1,
                'type' => 1,
                'price' => number_format($totalShipping * 100, 2, '.', ''),
            ];
        }

        $requestBody = [
            'user_email' => $this->customer->email,
            'buyer_details' => [
                'ip' => $this->GetIP(),
                'port' => $_SERVER['REMOTE_PORT'],
                'city' => $address->city,
                'country' => $address->country,
                'gsm' => preg_replace('/[^0-9]/', '', ($address->phone_mobile ? $address->phone_mobile : $address->phone)),
                'name' => $this->customer->firstname,
                'surname' => $this->customer->lastname,
            ],
            'basket_details' => [
                'currency' => 'TRY',
                'total_price' => number_format($cart->getOrderTotal() * 100, 2, '.', ''),
                'discount_price' => number_format($cart->getDiscountSubtotalWithoutGifts() * 100, 2, '.', ''),
                'items' => $orderItems,
            ],
            'shipping_details' => [
                'full_name' => $this->customer->firstname . ' ' . $this->customer->lastname,
                'phone' => preg_replace('/[^0-9]/', '', ($address->phone_mobile ? $address->phone_mobile : $address->phone)),
                'address' => $address->address1 . ' ' . $address->address2,
                'city' => $address->city,
                'country' => $address->country,
                'postalcode' => $address->postcode,
            ],
            'billing_details' => [
                'full_name' => $this->customer->firstname . ' ' . $this->customer->lastname,
                'phone' => preg_replace('/[^0-9]/', '', ($address->phone_mobile ? $address->phone_mobile : $address->phone)),
                'address' => $address->address1 . ' ' . $address->address2,
                'city' => $address->city,
                'country' => $address->country,
                'postalcode' => $address->postcode,
            ],
            'custom_params' => json_encode([
                'order_id' => $this->order->id,
                'customer_id' => $this->customer->id,
            ]),
            'user_message' => Message::getMessageByCartId($cart->id),
            'redirect_url' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $this->customer->secure_key,
            'fail_redirect_url' => Tools::getShopDomainSsl(true, true) . __PS_BASE_URI__ . 'index.php?controller=order',
        ];

        $requestBody = json_encode($requestBody);
        print_r($requestBody);
        echo '<hr />';
        if (function_exists('curl_version'))
        {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://payment.shoplemo.com/paywith/credit_card');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $requestBody);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 90);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 90);
            curl_setopt($ch, CURLOPT_SSLVERSION, 6);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . Tools::strlen($requestBody),
                'Authorization: Basic ' . base64_encode(Configuration::get('SHOPLEMO_API_KEY') . ':' . Configuration::get('SHOPLEMO_API_SECRET')),
            ]);
            $result = @curl_exec($ch);

            if (curl_errno($ch))
            {
                die('Shoplemo connection error. Details: ' . curl_error($ch));
            }

            curl_close($ch);
            try
            {
                $result = json_decode($result, 1);
            }
            catch (Exception $ex)
            {
                return 'Failed to handle response';
            }
        }
        else
        {
            echo 'CURL fonksiyonu yüklü değil?';
        }

        if ($result['status'] == 'success')
        {
        }

        $this->context->smarty->assign([
            'shoplemo' => $result,
        ]);

        $this->setTemplate('module:shoplemo/views/templates/front/iframe.tpl');
    }

    private function GetIP()
    {
        if (isset($_SERVER['HTTP_CLIENT_IP']))
        {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        }
        elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        else
        {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return $ip;
    }
}
