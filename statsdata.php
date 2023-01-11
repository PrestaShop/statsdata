<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class statsdata extends Module
{
    public function __construct()
    {
        $this->name = 'statsdata';
        $this->tab = 'administration';
        $this->version = '2.1.1';
        $this->author = 'PrestaShop';
        $this->need_instance = 0;

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->trans('Data mining for statistics', [], 'Modules.Statsdata.Admin');
        $this->description = $this->trans('Collect as much information as possible to enrich your stats and run your business further.', [], 'Modules.Statsdata.Admin');
        $this->ps_versions_compliancy = ['min' => '1.7.1.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('displayBeforeBodyClosingTag')
            && $this->registerHook('actionAuthentication')
            && $this->registerHook('createAccount');
    }

    public function getContent()
    {
        $html = '';
        if (Tools::isSubmit('submitStatsData')) {
            Configuration::updateValue('PS_STATSDATA_CUSTOMER_PAGESVIEWS', (int) Tools::getValue('PS_STATSDATA_CUSTOMER_PAGESVIEWS'));
            Configuration::updateValue('PS_STATSDATA_PAGESVIEWS', (int) Tools::getValue('PS_STATSDATA_PAGESVIEWS'));
            Configuration::updateValue('PS_STATSDATA_PLUGINS', (int) Tools::getValue('PS_STATSDATA_PLUGINS'));
            $html .= $this->displayConfirmation($this->trans('Settings updated', [], 'Admin.Global'));
        }

        $html .= $this->renderForm();

        return $html;
    }

    public function hookDisplayBeforeBodyClosingTag($params)
    {
        $script_content_plugins = $this->getScriptPlugins($params);
        $script_content_pages_views = $this->getScriptCustomerPagesViews($params);

        return $script_content_plugins . $script_content_pages_views;
    }

    private function getScriptPlugins($params)
    {
        if (!isset($params['cookie']->id_guest)) {
            Guest::setNewGuest($params['cookie']);

            if (Configuration::get('PS_STATSDATA_PLUGINS')) {
                $this->context->controller->registerJavascript('modules-plugindetect', 'modules/' . $this->name . '/js/plugindetect.js', ['position' => 'bottom', 'priority' => 150]);

                $token = sha1($params['cookie']->id_guest . _COOKIE_KEY_);

                return '<script type="text/javascript">
					$(document).ready(function() {
						plugins = new Object;
						plugins.adobe_director = (PluginDetect.getVersion("Shockwave") != null) ? 1 : 0;
						plugins.adobe_flash = (PluginDetect.getVersion("Flash") != null) ? 1 : 0;
						plugins.apple_quicktime = (PluginDetect.getVersion("QuickTime") != null) ? 1 : 0;
						plugins.windows_media = (PluginDetect.getVersion("WindowsMediaPlayer") != null) ? 1 : 0;
						plugins.sun_java = (PluginDetect.getVersion("java") != null) ? 1 : 0;
						plugins.real_player = (PluginDetect.getVersion("RealPlayer") != null) ? 1 : 0;

						navinfo = { screen_resolution_x: screen.width, screen_resolution_y: screen.height, screen_color:screen.colorDepth};
						for (var i in plugins)
							navinfo[i] = plugins[i];
						navinfo.type = "navinfo";
						navinfo.id_guest = "' . (int) $params['cookie']->id_guest . '";
						navinfo.token = "' . $token . '";
						navinfo.ajax = "1";
						$.post("' . Context::getContext()->link->getPageLink('statistics', (bool) (Tools::getShopProtocol() == 'https://')) . '", navinfo);
					});
				</script>';
            }
        }

        return '';
    }

    private function getScriptCustomerPagesViews($params)
    {
        // Record the guest path then increment the visit counter of the page
        $token_array = Connection::setPageConnection($params['cookie']);
        ConnectionsSource::logHttpReferer();
        if (Configuration::get('PS_STATSDATA_PAGESVIEWS')) {
            Page::setPageViewed($token_array['id_page']);
        }

        if (Configuration::get('PS_STATSDATA_CUSTOMER_PAGESVIEWS')) {
            // Ajax request sending the time spend on the page
            $token = sha1($token_array['id_connections'] . $token_array['id_page'] . $token_array['time_start'] . _COOKIE_KEY_);

            return '<script type="text/javascript">
				var time_start;
				$(window).on("load", function (e) {
					time_start = new Date();
				});
				$(window).on("unload", function (e) {
					var time_end = new Date();
					var pagetime = new FormData();
					pagetime.append("type", "pagetime");
					pagetime.append("id_connections", "' . (int) $token_array['id_connections'] . '");
					pagetime.append("id_page", "' . (int) $token_array['id_page'] . '");
					pagetime.append("time_start", "' . $token_array['time_start'] . '");
					pagetime.append("token", "' . $token . '");
					pagetime.append("time", time_end-time_start);
					pagetime.append("ajax", "1");
					navigator.sendBeacon("' . Context::getContext()->link->getPageLink('statistics', (bool) (Tools::getShopProtocol() == 'https://')) . '", pagetime);
				});
			</script>';
        }

        return '';
    }

    public function hookCreateAccount($params)
    {
        return $this->hookActionAuthentication($params);
    }

    public function hookActionAuthentication($params)
    {
        // Update or merge the guest with the customer id (login and account creation)
        $guest = new Guest($params['cookie']->id_guest);
        $result = Db::getInstance()->getRow('
		SELECT `id_guest`
		FROM `' . _DB_PREFIX_ . 'guest`
		WHERE `id_customer` = ' . (int) $params['cookie']->id_customer);

        if (!empty($result['id_guest'])) {
            // The new guest is merged with the old one when it's connecting to an account
            $guest->mergeWithCustomer($result['id_guest'], $params['cookie']->id_customer);
            $params['cookie']->id_guest = $guest->id;
        } else {
            // The guest is duplicated if it has multiple customer accounts
            $method = 'update';
            if ($guest->id_customer) {
                $method = 'add';
                $guest->id = null;
            }
            $guest->id_customer = $params['cookie']->id_customer;
            $guest->{$method}();
        }
    }

    public function renderForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Settings', [], 'Admin.Global'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Save page views for each customer', [], 'Modules.Statsdata.Admin'),
                        'name' => 'PS_STATSDATA_CUSTOMER_PAGESVIEWS',
                        'desc' => $this->trans('Storing customer page views uses a lot of CPU resources and database space. Only enable if your server can handle it.', [], 'Modules.Statsdata.Admin'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Save global page views', [], 'Modules.Statsdata.Admin'),
                        'name' => 'PS_STATSDATA_PAGESVIEWS',
                        'desc' => $this->trans('Global page views uses fewer resources than customer\'s, but it uses resources nonetheless.', [], 'Modules.Statsdata.Admin'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Plugins detection', [], 'Modules.Statsdata.Admin'),
                        'name' => 'PS_STATSDATA_PLUGINS',
                        'desc' => $this->trans('Plugins detection loads an extra 20 kb JavaScript file once for new visitors.', [], 'Modules.Statsdata.Admin'),
                        'values' => [
                            [
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', [], 'Admin.Global'),
                            ],
                            [
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', [], 'Admin.Global'),
                            ],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Admin.Actions'),
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitStatsData';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$fields_form]);
    }

    public function getConfigFieldsValues()
    {
        return [
            'PS_STATSDATA_CUSTOMER_PAGESVIEWS' => Tools::getValue('PS_STATSDATA_CUSTOMER_PAGESVIEWS', Configuration::get('PS_STATSDATA_CUSTOMER_PAGESVIEWS')),
            'PS_STATSDATA_PAGESVIEWS' => Tools::getValue('PS_STATSDATA_PAGESVIEWS', Configuration::get('PS_STATSDATA_PAGESVIEWS')),
            'PS_STATSDATA_PLUGINS' => Tools::getValue('PS_STATSDATA_PLUGINS', Configuration::get('PS_STATSDATA_PLUGINS')),
        ];
    }
}
