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
class ShoplemoCallbackModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        if (Tools::getValue('status') != 'success')
        {
            die('Shoplemo.com');
        }

        $_data = json_decode(Tools::getValue('data'), true);
        $customParams = json_decode($_data['custom_params']);

        $hash = base64_encode(hash_hmac('sha256', $_data['progress_id'] . implode('|', $_data['payment']) . Configuration::get('SHOPLEMO_API_KEY'), Configuration::get('SHOPLEMO_API_SECRET'), true));
        if ($hash != $_data['hash'])
        {
            die('Shoplemo: Calculated hash do not match!');
        }

        $orderId = $customParams->order_id;
        $order = new Order($orderId);
        if ($order->getCurrentState() == Configuration::get('SHOPLEMO_AWAITING_PAYMENT'))
        {
            if ($_data['payment']['payment_status'] == 'COMPLETED')
            {
                $order->setCurrentState(2); // 2 :Payment Confirmed - 11: Remote payment accepted
            }
            else
            {
                $order->setCurrentState(6); // 6: Cancelled - 8: Payment error
            }
        }
        else
        {
            die('Shoplemo: Invalid order state: ' . $order->getCurrentState() . ' - Expected: ' . Configuration::get('SHOPLEMO_AWAITING_PAYMENT'));
        }

        die('OK');
    }
}
