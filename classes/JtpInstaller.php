<?php
/**
 * josratpvpresta - TPV para PrestaShop
 *
 * Instalación / desinstalación: tablas, estados de pedido, transportista,
 * cliente por defecto, hooks propios para addons y configuración inicial.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpInstaller
{
    /** @var Josratpvpresta */
    protected $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * Hooks propios que la base crea para que los addons se enganchen.
     * Documentados en ADDONS.md
     */
    public static function customHooks()
    {
        return [
            'actionJtpAddonRegister' => 'TPV: registro de addons (info y versión)',
            'actionJtpPosAssets' => 'TPV: JS/CSS de addons en la pantalla de venta',
            'actionJtpPaymentMethods' => 'TPV: métodos de pago aportados por addons',
            'actionJtpCartLineBefore' => 'TPV: antes de añadir/modificar una línea',
            'actionJtpCartTotals' => 'TPV: totales del carrito calculados',
            'actionJtpCheckoutValidate' => 'TPV: validar antes de cobrar',
            'actionJtpTicketNumber' => 'TPV: sobrescribir número de ticket',
            'actionJtpDocumentType' => 'TPV: tipo de documento (simplificada/completa)',
            'actionJtpSaleCompleted' => 'TPV: venta completada',
            'actionJtpReturnCompleted' => 'TPV: devolución completada',
            'actionJtpTicketBlocks' => 'TPV: bloques extra en el ticket',
            'actionJtpSessionOpened' => 'TPV: caja abierta',
            'actionJtpSessionClosed' => 'TPV: caja cerrada',
            'actionJtpCashMovement' => 'TPV: movimiento de caja',
            'actionJtpQueueProcess' => 'TPV: procesar trabajo de la cola',
            'actionJtpApi' => 'TPV: acciones de API propias de un addon',
            'actionJtpCustomerCreated' => 'TPV: cliente creado desde el TPV',
            'actionJtpTicketLines' => 'TPV: líneas del ticket (notas bajo cada línea)',
            'actionJtpCustomerData' => 'TPV: datos extra del cliente (flags)',
            'actionJtpEcotaxLabel' => 'TPV: nombre de la ecotasa de un producto',
            'actionJtpPurgeList' => 'TPV: datos de un addon que se pueden limpiar (Mantenimiento)',
            'actionJtpPurgeBefore' => 'TPV: antes de limpiar los datos de prueba',
            'displayJtpAdminConfig' => 'TPV: paneles de addons en la configuración',
            'displayJtpAdminSale' => 'TPV: info de addons en el detalle de una venta',
        ];
    }

    public static function moduleHooks()
    {
        $hooks = [
            'moduleRoutes',
            'actionValidateOrder',
            'actionEmailSendBefore',
            'displayAdminOrderSide',
        ];

        return $hooks;
    }

    public function install()
    {
        return $this->installTables()
            && $this->installCustomHooks()
            && $this->installOrderStates()
            && $this->installCarrier()
            && $this->installDefaultCustomer()
            && $this->installPaymentMethods()
            && $this->installDefaultRegister()
            && $this->installConfig()
            && $this->widenReferenceColumns();
    }

    public function uninstall($dropTables = false)
    {
        $carrier = new Carrier((int) Configuration::get('JTP_ID_CARRIER'));
        if (Validate::isLoadedObject($carrier)) {
            $carrier->deleted = 1;
            $carrier->update();
        }
        // Restaurar facturas de PrestaShop si las desactivamos nosotros
        if (Configuration::get('JTP_DISABLE_PS_INVOICE') && Configuration::get('JTP_PS_INVOICE_BACKUP') !== false) {
            Configuration::updateValue('PS_INVOICE', (int) Configuration::get('JTP_PS_INVOICE_BACKUP'));
        }
        if ($dropTables) {
            foreach (self::tables() as $name => $sql) {
                Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $name . '`');
            }
            foreach (self::configKeys() as $key) {
                Configuration::deleteByName($key);
            }
        }

        return true;
    }

    public static function configKeys()
    {
        return [
            'JTP_SECRET', 'JTP_OS_SALE', 'JTP_OS_RETURNED', 'JTP_USE_OS_SALE', 'JTP_USE_OS_RETURNED', 'JTP_ID_CARRIER', 'JTP_DEFAULT_CUSTOMER', 'JTP_DEFAULT_ADDRESS',
            'JTP_ALLOW_NO_STOCK', 'JTP_REQUIRE_SESSION', 'JTP_TICKET_AS_REFERENCE', 'JTP_WEB_NUMERIC_REF', 'JTP_REF_MODE', 'JTP_WEB_REF_MODE',
            'JTP_WEB_SERIES', 'JTP_DISABLE_PS_INVOICE', 'JTP_PS_INVOICE_BACKUP', 'JTP_TICKET_HEADER',
            'JTP_TICKET_FOOTER', 'JTP_TICKET_SHOW_LOGO', 'JTP_TICKET_SHOW_EMPLOYEE', 'JTP_TICKET_SHOW_CUSTOMER',
            'JTP_TICKET_SHOW_REF', 'JTP_TICKET_SHOW_TAXES', 'JTP_TICKET_BARCODE', 'JTP_TICKET_WIDTH',
            'JTP_TICKET_EMAIL', 'JTP_PRINT_MODE', 'JTP_QZ_FORMAT', 'JTP_QZ_QR', 'JTP_TICKET_TOP_MM', 'JTP_BEEP', 'JTP_SCHEMA', 'JTP_QZ_CERT', 'JTP_QZ_KEY',
            'JTP_AUTO_PRINT', 'JTP_PRINT_COPIES', 'JTP_VOUCHER_DAYS', 'JTP_QUEUE_MAX_ATTEMPTS',
            'JTP_SEARCH_LIMIT', 'JTP_SHOW_OOS', 'JTP_SESSION_HOURS', 'JTP_PRICE_TAX_INCL',
            'JTP_CRON_TOKEN', 'JTP_ROUTE',
        ];
    }

    /**
     * Columnas añadidas en versiones posteriores: se crean si faltan (por si la actualización
     * del módulo no llegó a ejecutarse). Se llama una vez por versión desde el constructor.
     */
    public static function ensureSchema()
    {
        $db = Db::getInstance();
        $cols = [
            'jtp_register' => ['printer_a4' => "VARCHAR(128) NOT NULL DEFAULT '' AFTER `printer`"],
        ];
        $ok = true;
        foreach ($cols as $table => $list) {
            $t = _DB_PREFIX_ . $table;
            $have = [];
            foreach ($db->executeS('SHOW COLUMNS FROM `' . $t . '`') ?: [] as $c) {
                $have[] = $c['Field'];
            }
            if (!$have) {
                $ok = false;
                continue;
            }
            foreach ($list as $col => $def) {
                if (!in_array($col, $have)) {
                    $ok = $db->execute('ALTER TABLE `' . $t . '` ADD COLUMN `' . $col . '` ' . $def) && $ok;
                }
            }
        }

        return $ok;
    }

    public static function tables()
    {
        $e = ' ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
        $p = _DB_PREFIX_;

        return [
            'jtp_register' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_register` (
                `id_register` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `name` VARCHAR(64) NOT NULL,
                `series` VARCHAR(12) NOT NULL,
                `return_series` VARCHAR(12) NOT NULL,
                `printer` VARCHAR(128) NOT NULL DEFAULT '',
                `printer_a4` VARCHAR(128) NOT NULL DEFAULT '',
                `open_drawer` TINYINT(1) NOT NULL DEFAULT 1,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `id_cart_active` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_register`),
                KEY `id_shop` (`id_shop`)
            )" . $e,
            'jtp_payment_method' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_payment_method` (
                `id_payment_method` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
                `code` VARCHAR(32) NOT NULL,
                `name` VARCHAR(64) NOT NULL,
                `is_cash` TINYINT(1) NOT NULL DEFAULT 0,
                `allow_change` TINYINT(1) NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `position` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_payment_method`),
                UNIQUE KEY `shop_code` (`id_shop`, `code`)
            )" . $e,
            'jtp_employee' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_employee` (
                `id_employee` INT UNSIGNED NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `pin` VARCHAR(255) NOT NULL DEFAULT '',
                `perm_discount` TINYINT(1) NOT NULL DEFAULT 1,
                `perm_price` TINYINT(1) NOT NULL DEFAULT 0,
                `perm_return` TINYINT(1) NOT NULL DEFAULT 1,
                `perm_cash_move` TINYINT(1) NOT NULL DEFAULT 1,
                `perm_close` TINYINT(1) NOT NULL DEFAULT 1,
                `perm_no_stock` TINYINT(1) NOT NULL DEFAULT 0,
                `perm_all_sales` TINYINT(1) NOT NULL DEFAULT 0,
                `perm_favorites` TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id_employee`)
            )" . $e,
            'jtp_device' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_device` (
                `id_device` VARCHAR(40) NOT NULL,
                `name` VARCHAR(128) NOT NULL DEFAULT '',
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_employee_auth` INT UNSIGNED NOT NULL DEFAULT 0,
                `fails` INT UNSIGNED NOT NULL DEFAULT 0,
                `locked_until` DATETIME NULL,
                `revoked` TINYINT(1) NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                `last_seen` DATETIME NULL,
                PRIMARY KEY (`id_device`)
            )" . $e,
            'jtp_session' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_session` (
                `id_session` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_register` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_employee_open` INT UNSIGNED NOT NULL,
                `id_employee_close` INT UNSIGNED NOT NULL DEFAULT 0,
                `opening_amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `expected_cash` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `counted_cash` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `difference` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `status` VARCHAR(10) NOT NULL DEFAULT 'open',
                `note` TEXT NULL,
                `date_open` DATETIME NOT NULL,
                `date_close` DATETIME NULL,
                PRIMARY KEY (`id_session`),
                KEY `register_status` (`id_register`, `status`)
            )" . $e,
            'jtp_cash_movement' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_cash_movement` (
                `id_movement` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_session` INT UNSIGNED NOT NULL,
                `id_employee` INT UNSIGNED NOT NULL,
                `type` VARCHAR(10) NOT NULL,
                `amount` DECIMAL(20,6) NOT NULL,
                `reason` VARCHAR(255) NOT NULL DEFAULT '',
                `id_sale` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_return` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_movement`),
                KEY `id_session` (`id_session`)
            )" . $e,
            'jtp_counter' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_counter` (
                `series` VARCHAR(12) NOT NULL,
                `year` SMALLINT UNSIGNED NOT NULL,
                `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`series`, `year`)
            )" . $e,
            'jtp_cart' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_cart` (
                `id_cart` INT UNSIGNED NOT NULL,
                `id_register` INT UNSIGNED NOT NULL,
                `id_employee` INT UNSIGNED NOT NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'active',
                `label` VARCHAR(128) NOT NULL DEFAULT '',
                `id_cart_rule_discount` INT UNSIGNED NOT NULL DEFAULT 0,
                `discount_type` VARCHAR(10) NOT NULL DEFAULT '',
                `discount_value` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `note` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_cart`),
                KEY `register_status` (`id_register`, `status`)
            )" . $e,
            'jtp_cart_line' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_cart_line` (
                `id_cart` INT UNSIGNED NOT NULL,
                `id_product` INT UNSIGNED NOT NULL,
                `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
                `original_price` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `mode` VARCHAR(10) NOT NULL DEFAULT '',
                `value` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `position` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_cart`, `id_product`, `id_product_attribute`)
            )" . $e,
            'jtp_sale' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_sale` (
                `id_sale` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_order` INT UNSIGNED NOT NULL,
                `id_cart` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_register` INT UNSIGNED NOT NULL,
                `id_session` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_employee` INT UNSIGNED NOT NULL,
                `id_customer` INT UNSIGNED NOT NULL,
                `ticket_number` VARCHAR(40) NOT NULL,
                `series` VARCHAR(12) NOT NULL,
                `year` SMALLINT UNSIGNED NOT NULL,
                `number` INT UNSIGNED NOT NULL,
                `doc_type` VARCHAR(20) NOT NULL DEFAULT 'simplified',
                `total` DECIMAL(20,6) NOT NULL,
                `received` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `change_given` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `returned_total` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `note` TEXT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_sale`),
                UNIQUE KEY `ticket_number` (`ticket_number`),
                KEY `id_order` (`id_order`),
                KEY `id_session` (`id_session`)
            )" . $e,
            'jtp_sale_payment' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_sale_payment` (
                `id_sale_payment` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_sale` INT UNSIGNED NOT NULL,
                `code` VARCHAR(32) NOT NULL,
                `name` VARCHAR(64) NOT NULL,
                `amount` DECIMAL(20,6) NOT NULL,
                `is_cash` TINYINT(1) NOT NULL DEFAULT 0,
                `reference` VARCHAR(128) NOT NULL DEFAULT '',
                PRIMARY KEY (`id_sale_payment`),
                KEY `id_sale` (`id_sale`)
            )" . $e,
            'jtp_return' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_return` (
                `id_return` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_sale` INT UNSIGNED NOT NULL,
                `id_order` INT UNSIGNED NOT NULL,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `id_register` INT UNSIGNED NOT NULL,
                `id_session` INT UNSIGNED NOT NULL DEFAULT 0,
                `id_employee` INT UNSIGNED NOT NULL,
                `ticket_number` VARCHAR(40) NOT NULL,
                `series` VARCHAR(12) NOT NULL,
                `year` SMALLINT UNSIGNED NOT NULL,
                `number` INT UNSIGNED NOT NULL,
                `total` DECIMAL(20,6) NOT NULL,
                `refund_code` VARCHAR(32) NOT NULL,
                `refund_name` VARCHAR(64) NOT NULL,
                `refund_is_cash` TINYINT(1) NOT NULL DEFAULT 0,
                `id_cart_rule` INT UNSIGNED NOT NULL DEFAULT 0,
                `voucher_code` VARCHAR(64) NOT NULL DEFAULT '',
                `restock` TINYINT(1) NOT NULL DEFAULT 1,
                `reason` VARCHAR(255) NOT NULL DEFAULT '',
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_return`),
                UNIQUE KEY `ticket_number` (`ticket_number`),
                KEY `id_sale` (`id_sale`),
                KEY `id_session` (`id_session`)
            )" . $e,
            'jtp_return_line' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_return_line` (
                `id_return_line` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_return` INT UNSIGNED NOT NULL,
                `id_order_detail` INT UNSIGNED NOT NULL,
                `id_product` INT UNSIGNED NOT NULL,
                `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(255) NOT NULL,
                `quantity` INT UNSIGNED NOT NULL,
                `unit_price` DECIMAL(20,6) NOT NULL,
                `total` DECIMAL(20,6) NOT NULL,
                PRIMARY KEY (`id_return_line`),
                KEY `id_return` (`id_return`),
                KEY `id_order_detail` (`id_order_detail`)
            )" . $e,
            'jtp_favorite' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_favorite` (
                `id_shop` INT UNSIGNED NOT NULL,
                `id_product` INT UNSIGNED NOT NULL,
                `position` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id_shop`, `id_product`)
            )" . $e,
            'jtp_queue' => "CREATE TABLE IF NOT EXISTS `{$p}jtp_queue` (
                `id_job` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_shop` INT UNSIGNED NOT NULL DEFAULT 1,
                `addon` VARCHAR(64) NOT NULL,
                `action` VARCHAR(64) NOT NULL,
                `ref_type` VARCHAR(32) NOT NULL DEFAULT '',
                `ref_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `payload` MEDIUMTEXT NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'pending',
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `result` TEXT NULL,
                `last_error` TEXT NULL,
                `next_try` DATETIME NOT NULL,
                `date_add` DATETIME NOT NULL,
                `date_upd` DATETIME NOT NULL,
                PRIMARY KEY (`id_job`),
                KEY `status_next` (`status`, `next_try`),
                KEY `ref` (`addon`, `ref_type`, `ref_id`)
            )" . $e,
        ];
    }

    public function installTables()
    {
        foreach (self::tables() as $sql) {
            if (!Db::getInstance()->execute($sql)) {
                $this->module->addError('SQL: ' . Db::getInstance()->getMsgError());

                return false;
            }
        }

        return true;
    }

    public function installCustomHooks()
    {
        foreach (self::customHooks() as $name => $title) {
            if (!Hook::getIdByName($name)) {
                $hook = new Hook();
                $hook->name = $name;
                $hook->title = $title;
                $hook->description = $title . ' (josratpvpresta)';
                $hook->position = 1;
                $hook->add();
            }
        }

        return true;
    }

    protected function langValues($value)
    {
        $out = [];
        foreach (Language::getLanguages(false) as $lang) {
            $out[(int) $lang['id_lang']] = $value;
        }

        return $out;
    }

    protected function createOrderState($configKey, $name, $color, array $flags)
    {
        $id = (int) Configuration::get($configKey);
        $state = new OrderState($id ?: null);
        if ($id && Validate::isLoadedObject($state) && !$state->deleted) {
            return true;
        }
        $state = new OrderState();
        $state->name = $this->langValues($name);
        $state->color = $color;
        $state->module_name = $this->module->name;
        $state->unremovable = 1;
        $state->hidden = 0;
        $state->send_email = 0;
        $state->invoice = 0;
        $state->pdf_invoice = 0;
        $state->pdf_delivery = 0;
        $state->delivery = 0;
        $state->deleted = 0;
        $state->template = $this->langValues('');
        foreach ($flags as $k => $v) {
            $state->{$k} = $v;
        }
        if (!$state->add()) {
            return false;
        }

        return Configuration::updateValue($configKey, (int) $state->id);
    }

    public function installOrderStates()
    {
        // Crea los que falten, recupera los borrados y corrige sus opciones (ver JtpStates)
        try {
            JtpStates::repairAll();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('josratpvpresta: ' . $e->getMessage(), 3);

            return false;
        }

        return true;
    }

    /**
     * Transportista "Recogida en tienda" solo visible en el TPV: es un transportista de
     * módulo externo y el módulo devuelve false fuera del TPV (no aparece en la web).
     */
    public function installCarrier()
    {
        $id = (int) Configuration::get('JTP_ID_CARRIER');
        if ($id) {
            $carrier = new Carrier($id);
            if (Validate::isLoadedObject($carrier)) {
                if ($carrier->deleted) {
                    $carrier->deleted = 0;
                    $carrier->update();
                }

                return true;
            }
        }
        $carrier = new Carrier();
        $carrier->name = 'Venta en tienda (TPV)';
        $carrier->delay = $this->langValues('Entrega en mostrador');
        $carrier->active = 1;
        $carrier->deleted = 0;
        $carrier->is_module = 1;
        $carrier->shipping_external = 1;
        $carrier->external_module_name = $this->module->name;
        $carrier->need_range = 1;
        $carrier->shipping_handling = 0;
        $carrier->range_behavior = 0;
        $carrier->is_free = 0;
        $carrier->shipping_method = Carrier::SHIPPING_METHOD_WEIGHT;
        if (property_exists($carrier, 'id_tax_rules_group')) {
            $carrier->id_tax_rules_group = 0;
        }
        $carrier->url = '';
        if (!$carrier->add()) {
            return false;
        }
        Configuration::updateValue('JTP_ID_CARRIER', (int) $carrier->id);

        $db = Db::getInstance();
        foreach (Group::getGroups(Configuration::get('PS_LANG_DEFAULT')) as $group) {
            $db->insert('carrier_group', ['id_carrier' => (int) $carrier->id, 'id_group' => (int) $group['id_group']], false, true, Db::INSERT_IGNORE);
        }
        $range = new RangeWeight();
        $range->id_carrier = (int) $carrier->id;
        $range->delimiter1 = 0;
        $range->delimiter2 = 1000000;
        $range->add();
        foreach (Zone::getZones(false) as $zone) {
            $carrier->addZone((int) $zone['id_zone']);
            $db->insert('delivery', [
                'id_carrier' => (int) $carrier->id,
                'id_range_price' => null,
                'id_range_weight' => (int) $range->id,
                'id_zone' => (int) $zone['id_zone'],
                'price' => 0,
            ], true);
        }
        // Asociar a todas las tiendas
        if (Shop::isFeatureActive()) {
            foreach (Shop::getShops(false, null, true) as $idShop) {
                $db->insert('carrier_shop', ['id_carrier' => (int) $carrier->id, 'id_shop' => (int) $idShop], false, true, Db::INSERT_IGNORE);
            }
        }
        $logo = _PS_MODULE_DIR_ . $this->module->name . '/views/img/carrier.jpg';
        if (file_exists($logo)) {
            @copy($logo, _PS_SHIP_IMG_DIR_ . (int) $carrier->id . '.jpg');
        }

        return true;
    }

    public function installDefaultCustomer()
    {
        $id = (int) Configuration::get('JTP_DEFAULT_CUSTOMER');
        if ($id && Validate::isLoadedObject(new Customer($id))) {
            return true;
        }
        $customer = new Customer();
        $customer->firstname = 'Cliente';
        $customer->lastname = 'TPV';
        $customer->email = 'cliente-tpv-' . Tools::strtolower(Tools::passwdGen(6)) . '@tpv.invalid';
        $customer->passwd = password_hash(Tools::passwdGen(24), PASSWORD_BCRYPT);
        $customer->active = 1;
        $customer->newsletter = 0;
        $customer->optin = 0;
        $customer->is_guest = 0;
        $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
        if (!$customer->add()) {
            return false;
        }
        $customer->updateGroup([(int) Configuration::get('PS_CUSTOMER_GROUP')]);
        JtpCustomerService::createShopAddress($customer);

        return Configuration::updateValue('JTP_DEFAULT_CUSTOMER', (int) $customer->id);
    }

    public function installPaymentMethods()
    {
        $db = Db::getInstance();
        if ((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'jtp_payment_method`')) {
            return true;
        }
        $methods = [
            ['cash', 'Efectivo', 1, 1],
            ['card', 'Tarjeta', 0, 0],
            ['bizum', 'Bizum', 0, 0],
        ];
        $pos = 0;
        foreach ($methods as $m) {
            $db->insert('jtp_payment_method', [
                'id_shop' => 0,
                'code' => $m[0],
                'name' => pSQL($m[1]),
                'is_cash' => (int) $m[2],
                'allow_change' => (int) $m[3],
                'active' => 1,
                'position' => $pos++,
            ]);
        }

        return true;
    }

    public function installDefaultRegister()
    {
        $db = Db::getInstance();
        if ((int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'jtp_register`')) {
            return true;
        }
        foreach (Shop::getShops(false, null, true) as $idShop) {
            $db->insert('jtp_register', [
                'id_shop' => (int) $idShop,
                'name' => 'Caja 1',
                'series' => 'T' . (int) $idShop,
                'return_series' => 'T' . (int) $idShop . 'D',
                'printer' => '',
                'open_drawer' => 1,
                'active' => 1,
                'date_add' => date('Y-m-d H:i:s'),
            ]);
        }

        return true;
    }

    public function installConfig()
    {
        $defaults = [
            'JTP_ALLOW_NO_STOCK' => 0,
            'JTP_REQUIRE_SESSION' => 1,
            'JTP_TICKET_AS_REFERENCE' => 1,
            'JTP_WEB_NUMERIC_REF' => 0,
            'JTP_REF_MODE' => 'ticket',
            'JTP_WEB_REF_MODE' => 'none',
            'JTP_WEB_SERIES' => 'W',
            'JTP_DISABLE_PS_INVOICE' => 0,
            'JTP_TICKET_HEADER' => '',
            'JTP_TICKET_FOOTER' => 'Gracias por su compra',
            'JTP_TICKET_SHOW_LOGO' => 1,
            'JTP_TICKET_SHOW_EMPLOYEE' => 1,
            'JTP_TICKET_SHOW_CUSTOMER' => 1,
            'JTP_TICKET_SHOW_REF' => 0,
            'JTP_TICKET_SHOW_TAXES' => 1,
            'JTP_TICKET_BARCODE' => 1,
            'JTP_TICKET_WIDTH' => 80,
            'JTP_TICKET_EMAIL' => 1,
            'JTP_PRINT_MODE' => 'browser',
            'JTP_QZ_FORMAT' => 'escpos',
            'JTP_QZ_QR' => 'image',
            'JTP_TICKET_TOP_MM' => 4,
            'JTP_BEEP' => 1,
            'JTP_AUTO_PRINT' => 1,
            'JTP_PRINT_COPIES' => 1,
            'JTP_VOUCHER_DAYS' => 365,
            'JTP_QUEUE_MAX_ATTEMPTS' => 10,
            'JTP_SEARCH_LIMIT' => 40,
            'JTP_SHOW_OOS' => 1,
            'JTP_SESSION_HOURS' => 14,
            'JTP_PRICE_TAX_INCL' => 1,
            'JTP_ROUTE' => 'tpv',
        ];
        foreach ($defaults as $k => $v) {
            if (Configuration::get($k) === false) {
                Configuration::updateGlobalValue($k, $v);
            }
        }
        if (!Configuration::get('JTP_SECRET')) {
            Configuration::updateGlobalValue('JTP_SECRET', bin2hex(random_bytes(32)));
        }
        if (!Configuration::get('JTP_CRON_TOKEN')) {
            Configuration::updateGlobalValue('JTP_CRON_TOKEN', Tools::strtolower(Tools::passwdGen(24)));
        }

        return true;
    }

    /**
     * PrestaShop 1.7 / 8 guardan la referencia del pedido en VARCHAR(9). Para usar
     * el número de ticket (T1-2026-000123) como referencia se amplía a 64 si es menor.
     */
    public function widenReferenceColumns()
    {
        $db = Db::getInstance();
        $cols = [
            ['orders', 'reference'],
            ['order_payment', 'order_reference'],
        ];
        foreach ($cols as $c) {
            $len = (int) $db->getValue('SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'' . pSQL(_DB_PREFIX_ . $c[0]) . '\'
                AND COLUMN_NAME = \'' . pSQL($c[1]) . '\'');
            if ($len > 0 && $len < 40) {
                $db->execute('ALTER TABLE `' . _DB_PREFIX_ . $c[0] . '` MODIFY `' . $c[1] . '` VARCHAR(64) NULL');
            }
        }

        return true;
    }
}
