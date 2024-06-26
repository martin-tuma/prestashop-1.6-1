<?php
/**
 * NOTICE OF LICENSE
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 * @author    Zásilkovna, s.r.o.
 * @copyright 2012-2016 Zásilkovna, s.r.o.
 * @license   LICENSE.txt
 */

if (!defined('_PS_VERSION_')) {
    exit();
}

class Packetery extends Module
{
    const ID_PREF_ID = 'id';
    const ID_PREF_REF = 'reference';
    const WIDGET_URL = 'https://widget.packeta.com/v6/www/js/library.js';
    const APP_IDENTITY_PREFIX = 'prestashop-1.6-packeta-';
    // only for mixing with branch ids
    const ZPOINT = 'zpoint';
    // don't forget to update translation
    const MINIMUM_PHP_VERSION = '5.6';

    public static $is_before_carrier = false;

    public function __construct()
    {
        $this->name = 'packetery';
        $this->tab = 'shipping_logistics';
        $this->version = '2.0.9';
        $this->limited_countries = [];
        parent::__construct();

        $this->author = $this->l('Packeta s.r.o.');
        $this->displayName = $this->l('Packeta');
        $this->description = $this->l(
            'Offers your customers the option to choose pickup point in Packeta network,
            and export orders to Packeta system.'
        );
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the module? All module data will be deleted.');

        $this->module_key = 'aa9b6f2b47192e6caae86b500177a861';

        // This is only used in admin of modules, and we're accessing Packetery API here, so don't do that elsewhere.
        if (self::_isInstalled($this->name) && strpos($_SERVER['REQUEST_URI'], 'tab=AdminModules') !== false) {
            $errors = array();
            $this->configuration_errors($errors);
            foreach ($errors as $error) {
                $this->warning .= $error;
            }
        }
    }

    /**
     * Checks if module is installed
     * @param $module_name
     * @return bool
     */
    public static function _isInstalled($module_name)
    {
        if (method_exists("Packetery", "isInstalled")) {
            return self::isInstalled($module_name);
        } else {
            return true;
        }
    }

    /**
     * Returns available data transport methods (curl / fopen), prioritizes curl
     * @return bool|string
     */
    private static function transportMethod()
    {
        if (extension_loaded('curl')) {
            $have_curl = true;
        }
        if (ini_get('allow_url_fopen')) {
            $have_url_fopen = true;
        }

        if ($have_curl) {
            return 'curl';
        }
        if ($have_url_fopen) {
            return 'fopen';
        }
        return false;
    }

    /**
     * Checks for errors in configuration
     * @param null $error
     * @return bool
     */
    public function configuration_errors(&$error = null)
    {
        $error = array();
        $have_error = false;

        if (!self::transportMethod()) {
            $error[] = $this->l(
                'No way to access Packeta API is available on the web server:
                please allow CURL module or allow_url_fopen setting.'
            );
            $have_error = true;
        }

        $apiKey = Configuration::get('PACKETERY_API_KEY');
        if (!$apiKey) {
            $error[] = $this->l('Packeta API key is not set.');
            $have_error = true;
        } elseif (!$error && (bool)preg_match('/^[a-z\d]{16}$/', $apiKey) === false) {
            $error[] = $this->l('The API key must be 16 characters long (digits and letters).');
            $have_error = true;
        }

        return $have_error;
    }

    /**
     * Checks if the requirement for the minimum PHP version is met.
     * @return bool
     */
    public function checkRequirements() {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            $errorMessage = $this->l('You are using too old PHP version, please upgrade to version 5.6 or higher.');
            $this->_errors[] = $errorMessage;
            PrestaShopLogger::addLog(sprintf("%s: %s", $this->l('Packeta module'), $errorMessage), 5, null, null, null, true);

            return false;
        }

        return true;
    }

    /**
     * Module installation script
     * @return bool
     */
    public function install()
    {
        if (!$this->checkRequirements()) {
            return false;
        }

        $sql = array();
        $db = Db::getInstance();

        // create tables
        if (!defined('_MYSQL_ENGINE_')) {
            define('_MYSQL_ENGINE_', 'MyISAM');
        }
        include(dirname(__FILE__) . '/sql-install.php');
        foreach ($sql as $s) {
            if (!$db->execute($s)) {
                return false;
            }
        }

        // module itself and hooks
        if (!parent::install()
            || !$this->registerHook('extraCarrier')
            || !$this->registerHook('updateCarrier')
            || !$this->registerHook('newOrder')
            || !$this->registerHook('header')
            || !$this->registerHook('displayFooter')
            || !$this->registerHook('displayAdminOrderLeft')
        ) {
            return false;
        }

        // optional hooks (allow fail for older versions of PrestaShop)
        $this->registerHook('displayOrderConfirmation');
        $this->registerHook('displayOrderDetail');
        $this->registerHook('actionGetExtraMailTemplateVars');
        $this->registerHook('backOfficeTop');
        $this->registerHook('beforeCarrier');
        $this->registerHook('displayMobileHeader');

        // create admin tab under Orders
        $db->execute(
            'insert into `' . _DB_PREFIX_ . 'tab` (id_parent, class_name, module, position)
            select id_parent, "AdminOrderPacketery", "packetery", coalesce(max(position) + 1, 0)
            from `' . _DB_PREFIX_ . 'tab` pt where id_parent=(select if (id_parent>0, id_parent, id_tab) from `' .
            _DB_PREFIX_ . 'tab` as tp where tp.class_name="AdminOrders") group by id_parent'
        );
        $tab_id = $db->insert_id();

        $tab_name = array('en' => 'Packetery', 'cs' => 'Zásilkovna', 'sk' => 'Zásielkovňa');
        foreach (Language::getLanguages(false) as $language) {
            $db->execute(
                'insert into `' . _DB_PREFIX_ . 'tab_lang` (id_tab, id_lang, name)
                values(' . (int)$tab_id . ', ' . (int)$language['id_lang'] . ', "' .
                pSQL($tab_name[$language['iso_code']] ? $tab_name[$language['iso_code']] : $tab_name['en']) . '")'
            );
        }

        if (!Tab::initAccess($tab_id)) {
            return false;
        }

        $packetery = new Packetery();
        $result = $packetery->removeOverrideV204();

        $this->saveQuickLinks();

        return $result;
    }

	public function saveQuickLinks() {
		$settingsQA = $this->createQuickAccess($this->createQuickAccessSettingsUrl(), 'Packeta - Settings');
		$settingsQA->id = $this->getQuickAccessId($settingsQA->link);
		$settingsQA->save();

		$ordersQA = $this->createQuickAccess($this->createQuickAccessOrdersUrl(), 'Packeta - Orders');
		$ordersQA->id = $this->getQuickAccessId($ordersQA->link);
		$ordersQA->save();
    }

    /**
     * Use controller name to create a link
     *
     * @param string $controller
     * @param array $params
     * @param bool $withToken include or not the token in the url
     *
     * @return string url
     */
    public function getAdminLink($controller, array $params = [], $withToken = true) {
        $idLang = Context::getContext()->language->id;
        if ($withToken) {
            $params['token'] = Tools::getAdminTokenLite($controller);
        }

        return Dispatcher::getInstance()->createUrl($controller, $idLang, $params, false);
    }

    /**
     * @param string $link
     * @param string $label
     * @return \QuickAccessCore
     */
    public function createQuickAccess($link, $label) {
        $quickAccess = new QuickAccessCore();
        $quickAccess->link = $link;

        foreach (Language::getLanguages(false) as $lang) {
            $langId = $lang['id_lang'];
            $quickAccess->name[$langId] = $this->l($label);
        }

        $quickAccess->new_window = 0;
        return $quickAccess;
    }

    private function createQuickAccessSettingsUrl() {
        return $this->getAdminLink('AdminModules', [
            'configure' => $this->name,
            'module_name' => $this->name,
        ], false);
    }

    private function createQuickAccessOrdersUrl() {
        return $this->getAdminLink('AdminOrderPacketery', [], false);
    }

    /**
     * @param string $link
     * @return int|null
     */
    public function getQuickAccessId($link) {
        $id = Db::getInstance()->getValue('SELECT id_quick_access FROM '._DB_PREFIX_.'quick_access WHERE link = "'.pSQL($link).'"');
	    return (is_numeric($id) ? (int)$id : null);
    }

    /**
     * Module uninstallation script
     * @return bool
     */
    public function uninstall()
    {
        $configKeys = [
            'PACKETERY_API_KEY',
            'PACKETERY_ESHOP_DOMAIN',
            'PACKETERY_ID_PREFERENCE',
        ];
        foreach ($configKeys as $key) {
            Configuration::deleteByName($key);
        }

        // remove admin tab
        $db = Db::getInstance();
        $tabId = $db->getValue(
            'SELECT `id_tab` FROM `' . _DB_PREFIX_ . 'tab` WHERE `class_name` = "AdminOrderPacketery"'
        );
        if ($tabId) {
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'tab` WHERE `id_tab` = ' . $tabId);
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'tab_lang` WHERE `id_tab` = ' . $tabId);
            $db->execute('DELETE FROM `' . _DB_PREFIX_ . 'access` WHERE `id_tab` = ' . $tabId);
        }

        // remove all module tables
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'packetery_payment`');
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'packetery_address_delivery`');
        $db->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'packetery_order`');

        // module itself and hooks
        if (!parent::uninstall()
            || !$this->unregisterHook('beforeCarrier')
            || !$this->unregisterHook('extraCarrier')
            || !$this->unregisterHook('updateCarrier')
            || !$this->unregisterHook('newOrder')
            || !$this->unregisterHook('header')
            || !$this->unregisterHook('displayFooter')
            || !$this->unregisterHook('displayOrderConfirmation')
            || !$this->unregisterHook('displayOrderDetail')
            || !$this->unregisterHook('actionGetExtraMailTemplateVars')
            || !$this->unregisterHook('displayAdminOrderLeft')
            || !$this->unregisterHook('backOfficeTop')
        ) {
            return false;
        }

        $settingsQA = new QuickAccessCore($this->getQuickAccessId($this->createQuickAccessSettingsUrl()));
        $settingsQA->delete();

        $ordersQA = new QuickAccessCore($this->getQuickAccessId($this->createQuickAccessOrdersUrl()));
        $ordersQA->delete();

        return true;
    }

    /**
     * Processes data on form save
     */
    private function cConfigurationPost()
    {
        // leave the function if nothing is set
        if (
            !Tools::getIsset('packetery_api_key') &&
            !Tools::getIsset('packetery_eshop_domain') &&
            !Tools::getIsset('packetery_id_preference')
        ) {
            return;
        }

        // save API KEY if changed
        // TODO: validate
        if (trim(Tools::getValue('packetery_api_key')) != Configuration::get('PACKETERY_API_KEY')) {
            Configuration::updateValue('PACKETERY_API_KEY', trim(Tools::getValue('packetery_api_key')));
            @clearstatcache();
        }

        // save e-shop domain
        Configuration::updateValue('PACKETERY_ESHOP_DOMAIN', trim(Tools::getValue('packetery_eshop_domain')));

        Configuration::updateValue('PACKETERY_ID_PREFERENCE', trim(Tools::getValue('packetery_id_preference')));
    }

    /**
     * Outputs html for configuration form
     * @return string
     */
    private function cConfiguration()
    {
        $html = "";
        $html .= "<fieldset><legend>" . $this->l('Module Configuration') . "</legend>";
        $html .= "<form method='post'>";

        $html .= "<label>" . $this->l('API key') . ": </label>";
        $html .= "<div class='margin-form'><input type='text' name='packetery_api_key' value='" .
            htmlspecialchars(Configuration::get('PACKETERY_API_KEY'), ENT_QUOTES) . "' /></div>";
        $html .= "<div class='clear'></div>";

        $html .= "<label>" . $this->l('Sender label') . ": </label>";
        $html .= "<div class='margin-form'><input type='text' name='packetery_eshop_domain' value='" .
            htmlspecialchars(Configuration::get('PACKETERY_ESHOP_DOMAIN'), ENT_QUOTES) . "' /><p>" .
            $this->l('If you\'re using one Packeta account for multiple e-shops, enter the domain of current one here, so that your customers are properly informed about what package they are receiving.')
            . "</p></div>";
        $html .= "<div class='clear'></div>";

        $html .= "<label>" . $this->l('As the order ID, use') . ": </label>";
        $html .= "<div class='margin-form'><select name='packetery_id_preference'>";
        $idPreferenceOptions = [
            self::ID_PREF_ID => $this->l('Order ID'),
            self::ID_PREF_REF => $this->l('Order Reference'),
        ];
        foreach ($idPreferenceOptions as $optionValue => $optionTitle) {
            $selected = (Configuration::get('PACKETERY_ID_PREFERENCE') === $optionValue ? 'selected' : '');
            $html .= "<option value='$optionValue' $selected>" . $optionTitle . "</option>";
        }
        $html .= "</select></div><div class='clear'></div>";

        $html .= "<div class='margin-form'><input class='button' type='submit' value='" .
            htmlspecialchars($this->l('Save'), ENT_QUOTES) . "'  /></div>";

        $html .= "</form>";
        $html .= "</fieldset>";

        return $html;
    }

    /**
     * Processes change of COD in payments list
     */
    private function cListPaymentsPost()
    {
        if (Tools::getIsset('packetery_payment_module') && Tools::getValue('packetery_payment_module') && Tools::getValue('packetery_payment_submit')) {
            $db = Db::getInstance();
            if ($db->getValue(
                    'select 1 from `' . _DB_PREFIX_ . 'packetery_payment` where module_name="' .
                    pSQL(Tools::getValue('packetery_payment_module')) . '"'
                ) == 1
            ) {
                $db->execute(
                    'update `' . _DB_PREFIX_ . 'packetery_payment` set is_cod=' .
                    ((int)Tools::getValue('packetery_payment_is_cod')) . ' where module_name="' .
                    pSQL(Tools::getValue('packetery_payment_module')) . '"'
                );
            } else {
                $db->execute(
                    'insert into `' . _DB_PREFIX_ . 'packetery_payment` set is_cod=' .
                    ((int)Tools::getValue('packetery_payment_is_cod')) . ', module_name="' .
                    pSQL(Tools::getValue('packetery_payment_module')) . '"'
                );
            }
        }
    }

    /**
     * Outputs HTML for payments list
     * @return string
     */
    private function cListPayments()
    {
        $db = Db::getInstance();
        $html = "";
        $html .= "<fieldset><legend>" . $this->l('Payment List') . "</legend>";
        $html .= "<table class='table' cellspacing='0'>";
        $html .= "<tr><th>" . $this->l('Module') . "</th><th>" . $this->l('Is COD') .
            "</th><th>" . $this->l('Action') . "</th></tr>";
        $modules = $db->executeS(
            'select distinct m.name
            from `' . _DB_PREFIX_ . 'module` m
            left join `' . _DB_PREFIX_ . 'hook_module` hm on(hm.id_module=m.id_module)
            left join `' . _DB_PREFIX_ . 'hook` h on(hm.id_hook=h.id_hook)
            WHERE h.name in ("payment", "displayPayment", "displayPaymentReturn")
            AND m.active=1
        '
        );
        foreach ($modules as $module) {
            $instance = Module::getInstanceByName($module['name']);
            $is_cod = ($db->getValue(
                    'select is_cod from `' . _DB_PREFIX_ . 'packetery_payment`
                where module_name="' . pSQL($module['name']) . '"'
                ) == 1);
            $html .= "<tr><td>$instance->displayName</td><td>" . ($is_cod == 1 ? $this->l('Yes') : $this->l('No')) .
                "</td><td><form method='post'><input type='hidden' name='packetery_payment_module' value='" .
                htmlspecialchars($module['name'], ENT_QUOTES) . "' />
                <input type='hidden' name='packetery_payment_is_cod' value='" . (1 - $is_cod) . "' />
                <input type='submit' name='packetery_payment_submit' class='button' value='" .
                htmlspecialchars(
                    $is_cod ? $this->l('Clear COD setting') : $this->l('Set COD setting'),
                    ENT_QUOTES
                ) . "'></form></td></tr>";
        }
        $html .= "</table>";
        $html .= "<p>" . $this->l('When exporting order paid using module which has COD setting, the order total will be put as COD.') . "</p>";
        $html .= "<p>" . $this->l('Changes will not affect existing orders, only those created after your changes.') . "</p>";
        $html .= "</fieldset>";
        return $html;
    }

    /**
     * Processes adress delivery form
     */
    private function cListAllCarriersPost()
    {
        if (
            !Tools::getIsset('address_delivery_carriers') ||
            !Tools::getValue('address_delivery_carriers') ||
            !Tools::getIsset('data') ||
            !is_array(Tools::getValue('data'))
        ) {
            return;
        }

        $carriers = Tools::getValue('data');
        $addressDeliveries = self::addressDeliveries();
        foreach ($carriers as $carrierId => $carrier) {
            if (!$carrier['id_branch']) {
                Db::getInstance()->delete('packetery_address_delivery', '`id_carrier` = ' . ((int)$carrierId));
                continue;
            }

            if ($carrier['id_branch'] === self::ZPOINT) {
                $carrierName = null;
                $carrierCurrency = null;
                $branchId = null;
            } else if ($carrier['id_branch']) {
                $addressDelivery = $addressDeliveries[$carrier['id_branch']];
                $carrierName = pSQL($addressDelivery->name);
                $carrierCurrency = pSQL($addressDelivery->currency);
                $branchId = (int)$carrier['id_branch'];
            }

            $carrierData = [
                'id_carrier' => (int)$carrierId,
                'is_cod' => (int)$carrier['is_cod'],
                'id_branch' => $branchId,
                'name_branch' => $carrierName,
                'currency_branch' => $carrierCurrency,
                'is_pickup_point' => ($branchId === null ? 1 : 0),
            ];
            $this->insertOnDuplicateKeyUpdate('packetery_address_delivery', $carrierData, true);
        }
    }

    /**
     * Outputs html for delivery form
     * @return string
     */
    private function cListAllCarriers()
    {
        $db = Db::getInstance();
        $html = "";
        $html .= "<fieldset><legend>" . $this->l('Carriers List') . "</legend>";
        $html .= "<form method='post'>";
        $html .= "<input type='hidden' name='address_delivery_carriers' value='1'>";
        $html .= "<table class='table' cellspacing='0'>";
        $html .= "<tr><th>" . $this->l('Carrier') . "</th><th>" . $this->l('Is delivery via Packetery') .
            "</th><th>" . $this->l('Is COD') . "</th></tr>";

        $carriers = $db->executeS(
            'SELECT `pad`.`id_branch`, `pad`.`is_cod`,`pad`.`is_pickup_point`, `c`.`name`, `c`.`id_carrier`
            FROM `' . _DB_PREFIX_ . 'carrier` `c`
            LEFT JOIN `' . _DB_PREFIX_ . 'packetery_address_delivery` `pad` USING(`id_carrier`)
            WHERE `c`.`deleted` = 0
            AND `c`.`active` = 1
        '
        );

        $addressDeliveries = self::addressDeliveries();
        $codOptions = [
            $this->l('No'),
            $this->l('Yes'),
        ];
        foreach ($carriers as $carrier) {
            $html .= "<tr><td>" . ($carrier['name'] != "0" ? $carrier['name'] : Configuration::get('PS_SHOP_NAME')) .
                "</td><td><select name='data[" . $carrier['id_carrier'] . "][id_branch]'>";
            $html .= "<option value=''>–– " . Tools::strtolower($this->l('No')) . " ––</option>";
            $html .= "<option value='" . self::ZPOINT . "'" .
                ($carrier['is_pickup_point'] ? ' selected' : '') . ">" . $this->l('Packeta pickup point') . "</option>";
            foreach ($addressDeliveries as $branchId => $branch) {
                $html .= "<option value='$branchId'" .
                    ($carrier['id_branch'] == $branchId ? " selected" : "") . ">$branch->name</option>\n";
            }
            $html .= "</select></td><td><select name='data[" . $carrier['id_carrier'] . "][is_cod]'>";
            foreach ($codOptions as $codOptionId => $codOptionName) {
                $html .= "<option value='$codOptionId'" . ($carrier['is_cod'] == $codOptionId ? " selected" : "") . ">$codOptionName</option>\n";
            }
            $html .= "</select></td></tr>";
        }
        $html .= "</table>";
        $html .= "<input type='submit' class='button' value='" .
            htmlspecialchars($this->l('Save settings'), ENT_QUOTES) . "'>";
        $html .= "<p>" . $this->l(
                'Changes will not affect existing orders, only those created after your changes.'
            ) . "</p>";
        $html .= "</fieldset>";
        return $html;
    }

    /**
     * Primary method for Packetery settings page in administration
     * @return string
     */
    public function getContent()
    {
        /* Update list of carriers for address delivery if not up to date */
        $this->ensureUpdatedAPI();

        /* Process all forms */
        $this->cConfigurationPost();
        $this->cListPaymentsPost();
        $this->cListAllCarriersPost();

        $html = '';
        $html .= '<h2>' . $this->l('Packeta Shipping Module Settings') . '</h2>';
        $errors = array();

        /* Display configuration errors */
        $this->configuration_errors($errors);
        if ($errors) {
            $html .= "<fieldset><legend>" . $this->l('Configuration Errors') . "</legend>";
            foreach ($errors as $error) {
                $html .= "<p style='font-weight: bold; color: red'>" . $error . "</p>";
            }
            $html .= "</fieldset>";
        }

        /* Output all sections */
        $html .= "<br>";
        $html .= $this->cConfiguration();
        $html .= "<br>";
        $html .= $this->cListAllCarriers();
        $html .= "<br>";
        $html .= $this->cListPayments();

        return $html;
    }

    /**
     * Hook call
     * @param $params
     * @return string
     */
    public function hookBeforeCarrier($params)
    {
        self::$is_before_carrier = true;
        $res = $this->hookExtraCarrier($params);
        self::$is_before_carrier = false;
        return $res;
    }

    /**
     * Called from hook to display Packetery widget button and some extra data to each Packetery carrier
     * @param $params
     * @return string
     */
    public function hookExtraCarrier($params)
    {
        $db = Db::getInstance();

        /* Check if the hooks are active */
        if ($db->getValue(
                'select 1 from `' . _DB_PREFIX_ . 'hook` where name in ("beforeCarrier", "displayBeforeCarrier")'
            ) == 1 && !self::$is_before_carrier
        ) {
            return "";
        }

        $address = new AddressCore($params['cart']->id_address_delivery);
        $country_iso = CountryCore::getIsoById($address->id_country);
        $country = strtolower($country_iso);

        $zPointCarriers = $db->executeS(
            'SELECT `pad`.`id_carrier` FROM `' . _DB_PREFIX_ . 'packetery_address_delivery` `pad`
            JOIN `' . _DB_PREFIX_ . 'carrier` `c` USING(`id_carrier`) WHERE `c`.`deleted` = 0 AND `pad`.`is_pickup_point` = 1'
        );
        $zPointCarriersIdsJSON = Tools::jsonEncode(array_column($zPointCarriers, 'id_carrier'));

        $api_key = Configuration::get('PACKETERY_API_KEY');

        /* Get language from cart, global $language updates weirdly */
        $language = new LanguageCore($this->context->cart->id_lang);

        /* Use user's language if supported, english otherwise */
        $lang = ($language->iso_code ?: 'en');

        /* Prepare langs to be used by JS */
        $mod_dir = _MODULE_DIR_;
        $must_select_point_text = $this->l('You must select a pickup point before continuing');
        $select_point_text = $this->l('Please select a pickup point');
        $selected_point_text = $this->l('Selected pickup point');
        $appIdentity = self::APP_IDENTITY_PREFIX . $this->version;

        $lang = strtolower($lang);

        /* Define some JS variables and inicialize widget */
        return <<< END
        <script type="text/javascript">
            var zpoint_carriers = $zPointCarriersIdsJSON;
            var api_key = "$api_key";           
            var country = "$country";
            var lang = "$lang";            
            var module_dir = "$mod_dir";
            var selected_text = "$selected_point_text"; 
            var select_text = "$select_point_text";
            var must_select_text = "$must_select_point_text";
            var app_identity = "$appIdentity";
            
            $(function(){
                $("input.delivery_option_radio").on('change', function(){
                    initializePacketaWidget();
                });
                initializePacketaWidget();
            });
        </script>
END;
    }

    /**
     * Hook call - saves additional data after an order has been created.
     * @param $params
     */
    public function hookNewOrder($params)
    {
        $carrier = $this->getPacketeryCarrier((int)$params['order']->id_carrier);
        if (!$carrier) {
            return;
        }

        $orderData = [
            'id_cart' => (int)$params['cart']->id,
            'id_order' => (int)$params['order']->id,
        ];
        if (!$carrier['is_pickup_point']) {
            // address delivery
            $orderData['id_branch'] = (int)$carrier['id_branch'];
            $orderData['name_branch'] = pSQL($carrier['name_branch']);
            $orderData['currency_branch'] = pSQL($carrier['currency_branch']);
        }

        $carrierIsCod = ($carrier['is_cod'] == 1);
        $db = Db::getInstance();
        $paymentIsCod = ($db->getValue(
                'SELECT `is_cod` FROM `' . _DB_PREFIX_ . 'packetery_payment`
                WHERE `module_name` = "' . pSQL($params['order']->module) . '"'
            ) == 1);

        // If payment or carrier is set as cod - set order as cod
        if ($carrierIsCod || $paymentIsCod) {
            $orderData['is_cod'] = 1;
        }

        $this->insertOnDuplicateKeyUpdate('packetery_order', $orderData);
    }

    public function hookDisplayAdminOrderLeft($params)
    {
        $apiKey = Configuration::get('PACKETERY_API_KEY');
        $packeteryOrder = Db::getInstance()->getRow(
            'SELECT `pa`.`is_pickup_point`, `po`.`name_branch`, `c`.`iso_code` AS `country`
            FROM `' . _DB_PREFIX_ . 'packetery_order` `po`
            JOIN `' . _DB_PREFIX_ . 'orders` `o` ON `o`.`id_order` = `po`.`id_order`
            JOIN `' . _DB_PREFIX_ . 'packetery_address_delivery` `pa` ON `o`.`id_carrier` = `pa`.`id_carrier`
            JOIN `' . _DB_PREFIX_ . 'address` `a` ON `a`.`id_address` = `o`.`id_address_delivery` 
            JOIN `' . _DB_PREFIX_ . 'country` `c` ON `c`.`id_country` = `a`.`id_country`
            WHERE `po`.`id_order` = ' . ((int)$params['id_order'])
        );
        if (!$apiKey || !$packeteryOrder) {
            return;
        }

        $this->context->controller->addCSS($this->_path . 'views/css/admin_order.css?v=' . $this->version, 'all', null, false);
        $this->context->controller->addJS(self::WIDGET_URL);
        $this->context->controller->addJS($this->_path . 'views/js/admin_order.js?v=' . $this->version);

        $isPickupPointDelivery = (bool)$packeteryOrder['is_pickup_point'];
        $this->context->smarty->assign('isPickupPointDelivery', $isPickupPointDelivery);
        $this->context->smarty->assign('branchName', $packeteryOrder['name_branch']);
        if ($isPickupPointDelivery) {
            $employee = Context::getContext()->employee;
            $widgetOptions = [
                'api_key' => $apiKey,
                'app_identity' => self::APP_IDENTITY_PREFIX . $this->version,
                'country' => strtolower($packeteryOrder['country']),
                'module_dir' => _MODULE_DIR_,
                'order_id' => $params['id_order'],
                'lang' => Language::getIsoById($employee ? $employee->id_lang : Configuration::get('PS_LANG_DEFAULT')),
            ];
            $this->context->smarty->assign('widgetOptions', $widgetOptions);
        }
        return $this->display(__FILE__, 'display_order_left.tpl');
    }

    /**
     * Shows information about selected pickup point, in the last step of the cart when confirming the order
     * @param array $params
     * @return string|void
     */
    public function hookDisplayOrderConfirmation($params)
    {
        if (!isset($params['objOrder'])) {
            return;
        }
        return $this->displayPickupPointInfo($params['objOrder'], 'display_order_confirmation.tpl');
    }

    /**
     * Show information about selected pickup point in frontend order detail, between address and products
     * @param array $params
     * @return string|void
     */
    public function hookDisplayOrderDetail($params)
    {
        if (!isset($params['order'])) {
            return;
        }
        return $this->displayPickupPointInfo($params['order'], 'display_order_detail.tpl');
    }

    /**
     * @param Order $order
     * @param string $templateName
     * @return string|void
     */
    private function displayPickupPointInfo($order, $templateName)
    {
        $orderData = self::getPickupPointInfoForOrder('id_order', (int)$order->id);
        if ($orderData && (bool)$orderData['is_pickup_point'] === true) {
            $this->context->smarty->assign('title', $this->l('Selected Packeta pickup point'));
            $this->context->smarty->assign('pickupPointOrAddressDeliveryName', $orderData['name_branch']);

            return $this->display(__FILE__, $templateName);
        }
    }

    /**
     * Called when sending email, will add extra variables to email templates.
     * Order confirmation template is located in mails/language-code/order_conf - both html and txt.
     * Add {packetery_pickup_point_label} and {packetery_pickup_point} where you need, usually after {carrier}.
     * @param array $params
     * @return void
     */
    public function hookActionGetExtraMailTemplateVars(array &$params)
    {
        // to not show when not needed
        $params['extra_template_vars'] = [
            '{packetery_pickup_point_label}' => '',
            '{packetery_pickup_point}' => '',
        ];

        if (!isset($params['cart'])) {
            return;
        }

        $orderData = self::getPickupPointInfoForOrder('id_cart', (int)$params['cart']->id);
        if (!$orderData || (int)$orderData['is_pickup_point'] === 0) {
            return;
        }

        $pickupPoint = $orderData['name_branch'];
        if ((bool)$orderData['is_carrier'] === false) {
            $pickupPoint .= sprintf(' (%s)', $orderData['id_branch']);
        }
        $params['extra_template_vars'] = [
            '{packetery_pickup_point_label}' => sprintf("%s:", $this->l('Selected Packeta pickup point')),
            '{packetery_pickup_point}' => $pickupPoint,
        ];
    }

    /**
     * @param string $key db column to match - id_cart or id_order
     * @param int $id
     * @return array|bool|object|null
     */
    private static function getPickupPointInfoForOrder($key, $id)
    {
        return Db::getInstance()->getRow(
            'SELECT `po`.`name_branch`, `po`.`id_branch`, `po`.`is_carrier`, `pad`.`is_pickup_point`
            FROM `' . _DB_PREFIX_ . 'packetery_order` `po`
            JOIN `' . _DB_PREFIX_ . 'orders` `o` ON `o`.`id_order` = `po`.`id_order`  
            JOIN `' . _DB_PREFIX_ . 'packetery_address_delivery` `pad` ON `pad`.`id_carrier` = `o`.`id_carrier`  
            WHERE `po`.`' . $key . '` = ' . $id);
    }

    /**
     * Sets new carrier ID after update
     * @param $params
     */
    public function hookUpdateCarrier($params)
    {
        if ($params['id_carrier'] != $params['carrier']->id) {
            Db::getInstance()->update('packetery_address_delivery',
                ['id_carrier' => ((int)$params['carrier']->id)],
                '`id_carrier` = ' . ((int)$params['id_carrier'])
            );
        }
    }

    /**
     * Hook call, display header on mobile
     * @param $params
     * @return string
     */
    public function hookDisplayMobileHeader($params)
    {
        return $this->hookHeader($params);
    }

    /**
     * Hook call, display header - adds js files
     * @param $params
     * @return string
     */
    public function hookHeader($params)
    {
        // need to able to overwrite
        $this->context->controller->addJs(($this->_path).'views/js/front.js');
        $this->context->controller->addCSS(($this->_path).'views/css/packetery.css');

        return '
        <script type="text/javascript" src="' . self::WIDGET_URL . '"></script>';
    }

    /*
      Try to update Branch XML file once a day. If it's older than five days and still
      can't update, then remove it - the e-shop owner must solve it.
    */
    private function ensureUpdatedAPI()
    {
        $key = Configuration::get('PACKETERY_API_KEY');
        $localFilePath = _PS_MODULE_DIR_ . "packetery/address-delivery.xml";
        $remoteUrl = "https://www.zasilkovna.cz/api/v4/$key/branch.xml?address-delivery";
        if (file_exists($localFilePath) && filesize($localFilePath) === 0) {
            unlink($localFilePath);
        }
        if (!file_exists($localFilePath) || date('Y-m-d', filemtime($localFilePath)) !== date('Y-m-d')) {
            if ($this->configuration_errors()) {
                if (file_exists($localFilePath) && time() - filemtime($localFilePath) > 5 * 24 * 60 * 60) {
                    unlink($localFilePath);
                }

                return;
            }

            ignore_user_abort(true);
            $response = Tools::file_get_contents($remoteUrl);
            if (!$response) {
                return;
            }

            $xml = simplexml_load_string($response);
            if ($xml === false) {
                return;
            }

            $data = $xml->asXML();
            if ($data) {
                file_put_contents($localFilePath, $data);
            }
        }
    }

    /**
     * @param int $carrierId
     * @return array|bool|null|object
     */
    private function getPacketeryCarrier($carrierId)
    {
        return Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'packetery_address_delivery`
            WHERE `id_carrier` = ' . $carrierId);
    }

    /**
     * @return array
     */
    public static function addressDeliveries()
    {
        $res = array();
        $fn = _PS_MODULE_DIR_ . "packetery/address-delivery.xml";
        if (function_exists('simplexml_load_string') && file_exists($fn) && filesize($fn) !== 0) {
            $xml = simplexml_load_string(file_get_contents($fn));
            foreach ($xml->carriers->carrier as $carrier) {
                if ((string)$carrier->pickupPoints === 'false') {
                       $res[(string)$carrier->id] = (object)array(
                        'name' => (string)$carrier->name,
                        'currency' => (string)$carrier->currency,
                    );
                }
            }
            if (function_exists('mb_convert_encoding')) {
                $fn = create_function(
                    '$a,$b',
                    'return strcmp(mb_convert_encoding($a->name, "ascii", "utf-8"),
                    mb_convert_encoding($b->name, "ascii", "utf-8"));'
                );
            } else {
                $fn = create_function(
                    '$a,$b',
                    'return strcmp($a->name, $b->name);'
                );
            }
            uasort($res, $fn);
        }
        return $res;
    }

    /**
     * display existing errors, TODO: delete?
     * @param $params
     * @return string
     */
    public function hookBackOfficeTop($params)
    {
        $cookie = Context::getContext()->cookie;
        if ($cookie->packetery_seen_warning < 3) {
            $cookie->packetery_seen_warning++;
            $errors = array();
            if (!$this->configuration_errors($errors) && count($errors) > 0) {
                return "<div style='float: right; width: 400px; font-weight: bold; color: red'>" . $errors[0] .
                    "</div>";
            }
        }
    }

    /**
     * removing Carrier override in module 2.0.4 does not work, this is a workaround
     * @return bool
     */
    public function removeOverrideV204()
    {
        $backupOverridePath = $this->getLocalPath() . 'override-old/Carrier-2.0.4.php';
        $originalOverridePath = $this->getLocalPath() . 'override/classes/Carrier.php';
        $uninstallResult = true;
        $copyResult = Tools::copy($backupOverridePath, $originalOverridePath);
        if ($copyResult) {
            $uninstallResult = (bool)$this->uninstallOverrides();
            Tools::deleteFile($originalOverridePath);
        }

        // from Module->addOverride
        $path = PrestaShopAutoload::getInstance()->getClassPath('CarrierCore');
        if (!$path) {
            $path = 'modules' . DIRECTORY_SEPARATOR . 'Carrier' . DIRECTORY_SEPARATOR . 'Carrier.php';
        }
        $override_path = _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'override' . DIRECTORY_SEPARATOR . $path;

        if (is_file($override_path) && is_readable($override_path)) {
            $overrideContents = file_get_contents($override_path);
            if (strpos($overrideContents, '$is_packetery_carrier') !== false) {
                $errorMessage = $this->l('Packeta module failed to uninstall version 2.0.4 override. You can find more information in module documentation.');

                // this does not show up during upgrade
                $this->_errors[] = Tools::displayError($errorMessage);

                PrestaShopLogger::addLog($errorMessage, 4, null, null, null, true);
                return false;
            }
        }

        return $uninstallResult;
    }

    public static function adminOrderChangeBranch()
    {
        if (!Tools::getIsset('order_id') || !Tools::getIsset('pickup_point')) {
            return false;
        }

        $orderId = (int)Tools::getValue('order_id');
        $pickupPoint = Tools::getValue('pickup_point');

        $packeteryOrderFields = [
            'id_branch' => (int)$pickupPoint['id'],
            'name_branch' => pSQL($pickupPoint['name']),
            'currency_branch' => pSQL($pickupPoint['currency']),
        ];
        if ($pickupPoint['pickupPointType'] == 'external') {
            $packeteryOrderFields['is_carrier'] = 1;
            $packeteryOrderFields['id_branch'] = (int)$pickupPoint['carrierId'];
            $packeteryOrderFields['carrier_pickup_point'] = pSQL($pickupPoint['carrierPickupPointId']);
        }
        Db::getInstance()->update('packetery_order', $packeteryOrderFields, '`id_order` = ' . $orderId);

        echo json_encode(['result' => 'ok']);
    }

    /**
     * @param array $data associative array of data, it supports integer, float, boolean, null and string values
     * @param bool $nullValues convert empty string to null?
     * @return string array stringified to SQL
     */
    private function generateSQLFromData($data, $nullValues)
    {
        $sqlParts = [];
        $db = Db::getInstance();
        foreach ($data as $key => $value) {
            switch (true) {
                case (is_int($value) || is_float($value)):
                    $valueEscaped = $value;
                    break;
                case is_bool($value):
                    $valueEscaped = var_export($value, true);
                    break;
                case is_null($value):
                    $valueEscaped = 'NULL';
                    break;
                default:
                    // same behavior as newer PrestaShop
                    if ($nullValues && $value === '') {
                        $valueEscaped = 'NULL';
                        break;
                    }
                    $valueEscaped = '\'' . $db->escape((string)$value) . '\'';
            }
            $sqlParts[] = sprintf(' `%s` = %s', $db->escape($key), $valueEscaped);
        }
        return implode(',', $sqlParts);
    }

    /**
     * older PS does not support ON_DUPLICATE_KEY so this is simplified version of Db::insert
     * @param string $table
     * @param array $data
     * @param bool $nullValues
     */
    private function insertOnDuplicateKeyUpdate($table, $data, $nullValues = false)
    {
        $dataSql = $this->generateSQLFromData($data, $nullValues);
        Db::getInstance()->execute('INSERT INTO `' . _DB_PREFIX_ . $table . '` SET ' . $dataSql . ' ON DUPLICATE KEY UPDATE ' . $dataSql);
    }
}
