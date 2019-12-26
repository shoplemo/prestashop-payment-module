<?php
/**
 * 2007-2019 PrestaShop.
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
 *  @author    PrestaShop SA <contact@prestashop.com>
 *  @copyright 2007-2019 PrestaShop SA
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */
if (!defined('_PS_VERSION_'))
{
    exit;
}

class Shoplemo extends PaymentModule
{
    protected $config_form = false;

    public function __construct()
    {
        $this->name = 'shoplemo';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'RevoLand';
        $this->need_instance = 0;
        $this->controllers = ['iframe', 'callback'];

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Shoplemo Ödeme Modülü');
        $this->description = $this->l('Shoplemo aracılığı ile ödeme alabilmeniz/ürün satabilmeniz için, Presta ödeme modülü.');

        $this->limited_countries = ['TR'];

        $this->limited_currencies = ['TRY', 'USD', 'EUR'];

        $this->ps_versions_compliancy = ['min' => '1.6', 'max' => _PS_VERSION_];

        if (!Configuration::get('SHOPLEMO_API_KEY'))
        {
            $this->warning = $this->l('Sistemin çalışması için gerekli Api anahtarı girilmedi.');
        }

        if (!Configuration::get('SHOPLEMO_API_SECRET'))
        {
            $this->warning = $this->l('Sistemin çalışması için gerekli Api Secret anahtarı girilmedi.');
        }
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update.
     */
    public function install()
    {
        if (extension_loaded('curl') == false)
        {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');

            return false;
        }

        $iso_code = Country::getIsoById(Configuration::get('PS_COUNTRY_DEFAULT'));

        if (in_array($iso_code, $this->limited_countries) == false)
        {
            $this->_errors[] = $this->l('This module is not available in your country');

            return false;
        }

        if (Shop::isFeatureActive())
        {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        $this->createOrderState('SHOPLEMO_AWAITING_PAYMENT', 'Shoplemo ile ödeme yapılması bekleniyor.');

        return parent::install() &&
            $this->registerHook('paymentOptions');
    }

    public function uninstall()
    {
        Configuration::deleteByName('SHOPLEMO_API_KEY');
        Configuration::deleteByName('SHOPLEMO_API_SECRET');

        return parent::uninstall();
    }

    /**
     * Load the configuration form.
     */
    public function getContent()
    {
        /*
         * If values have been submitted in the form, process.
         */
        if (((bool) Tools::isSubmit('submitShoplemoModule')) == true)
        {
            $this->postProcess();
        }

        $this->context->smarty->assign('module_dir', $this->_path);

        $output = $this->context->smarty->fetch($this->local_path . 'views/templates/admin/configure.tpl');

        return $output . $this->renderForm();
    }

    /**
     * Return payment options available for PS 1.7+.
     *
     * @param array Hook parameters
     * @param mixed $params
     *
     * @return array|null
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active)
        {
            return;
        }

        if (!$this->checkCurrency($params['cart']))
        {
            return;
        }

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText($this->l('Shoplemo ile öde'))
            ->setAction($this->context->link->getModuleLink($this->name, 'iframe', [], true))
            ->setModuleName($this->name)
            ->setAdditionalInformation($this->context->smarty->fetch('module:shoplemo/views/templates/front/payment_infos.tpl'))
            ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'));

        return [
            $option,
        ];
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);
        if (is_array($currencies_module))
        {
            foreach ($currencies_module as $currency_module)
            {
                if ($currency_order->id == $currency_module['id_currency'])
                {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;

        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitShoplemoModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFormValues(), /* Add values for your inputs */
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$this->getConfigForm()]);
    }

    /**
     * Create the structure of your form.
     */
    protected function getConfigForm()
    {
        return [
            'form' => [
                'legend' => [
                    'title' => $this->l('Shoplemo Modül Ayarları'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'name' => 'SHOPLEMO_API_KEY',
                        'desc' => $this->l('Shoplemo sistemi üzerinden size verilmiş olan Api anahtarını buraya girebilirsiniz.'),
                        'label' => $this->l('Api Key'),
                        'required' => true,
                    ],
                    [
                        'type' => 'text',
                        'name' => 'SHOPLEMO_API_SECRET',
                        'desc' => $this->l('Shoplemo sistemi üzerinden size verilmiş olan Api Secret anahtarını buraya girebilirsiniz.'),
                        'label' => $this->l('Api Secret'),
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Kaydet'),
                ],
            ],
        ];
    }

    /**
     * Set values for the inputs.
     */
    protected function getConfigFormValues()
    {
        return [
            'SHOPLEMO_API_KEY' => Configuration::get('SHOPLEMO_API_KEY', null),
            'SHOPLEMO_API_SECRET' => Configuration::get('SHOPLEMO_API_SECRET', null),
        ];
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key)
        {
            Configuration::updateValue($key, Tools::getValue($key));
        }
    }

    private function createOrderState($stateName, $stateDesc)
    {
        if (Configuration::get($stateName))
        {
            return;
        }

        $orderState = new OrderState();
        $orderState->name = array_fill(0, 10, $stateDesc);
        $orderState->invoice = false;
        $orderState->module_name = $this->name;
        $orderState->send_email = false;
        $orderState->color = '#076dc4';
        $orderState->hidden = false;
        $orderState->logable = false;
        $orderState->delivery = false;
        $orderState->add();

        Configuration::updateValue($stateName, (int) $orderState->id);
    }
}
