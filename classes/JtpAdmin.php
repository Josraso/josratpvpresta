<?php
/**
 * josratpvpresta - configuración en Módulos > Configurar (sin pestañas en el menú lateral).
 * Formularios con HelperForm y listados con HelperList nativos.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpAdmin
{
    /** @var Josratpvpresta */
    protected $module;

    /** @var Context */
    protected $context;

    protected $tab;

    protected $output = '';

    public function __construct($module)
    {
        $this->module = $module;
        $this->context = Context::getContext();
        $this->tab = Tools::getValue('jtp_tab', 'general');
        if (!array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'general';
        }
    }

    protected function tabs()
    {
        return [
            'general' => ['General', 'icon-cogs'],
            'ticket' => ['Ticket', 'icon-file-text'],
            'print' => ['Impresión', 'icon-print'],
            'registers' => ['Cajas', 'icon-inbox'],
            'payments' => ['Métodos de pago', 'icon-credit-card'],
            'employees' => ['Empleados', 'icon-user'],
            'devices' => ['Dispositivos', 'icon-desktop'],
            'sales' => ['Ventas', 'icon-shopping-cart'],
            'sessions' => ['Cierres de caja', 'icon-money'],
            'queue' => ['Cola', 'icon-exchange'],
            'addons' => ['Addons', 'icon-puzzle-piece'],
            'maintenance' => ['Mantenimiento', 'icon-eraser'],
        ];
    }

    protected function baseUrl($tab = null)
    {
        return $this->currentIndex($tab) . '&token=' . $this->token();
    }

    protected function languages()
    {
        $out = [];
        $default = (int) Configuration::get('PS_LANG_DEFAULT');
        foreach (Language::getLanguages(false) as $l) {
            $l['is_default'] = (int) $l['id_lang'] === $default ? 1 : 0;
            $out[] = $l;
        }

        return $out;
    }

    protected function currentIndex($tab = null)
    {
        return __PS_BASE_URI__ . basename(_PS_ADMIN_DIR_) . '/index.php?controller=AdminModules&configure=' . $this->module->name . '&jtp_tab=' . ($tab ?: $this->tab);
    }

    protected function token()
    {
        return Tools::getAdminTokenLite('AdminModules');
    }

    protected function redirect($tab, $conf = 4)
    {
        Tools::redirectAdmin($this->baseUrl($tab) . '&conf=' . (int) $conf);
    }

    protected function err($msg)
    {
        $this->output .= $this->module->displayError($msg);
    }

    protected function ok($msg)
    {
        $this->output .= $this->module->displayConfirmation($msg);
    }

    protected function sw($name, $label, $desc = '')
    {
        return [
            'type' => 'switch',
            'label' => $label,
            'name' => $name,
            'is_bool' => true,
            'desc' => $desc,
            'values' => [
                ['id' => $name . '_on', 'value' => 1, 'label' => 'Sí'],
                ['id' => $name . '_off', 'value' => 0, 'label' => 'No'],
            ],
        ];
    }

    // ================================================================== render

    public function render()
    {
        $this->process();
        $method = 'tab' . Tools::ucfirst($this->tab);
        $content = $this->{$method}();
        $registers = [];
        foreach (JtpRegisters::forShop() as $r) {
            $registers[] = [
                'name' => $r['name'],
                'url' => JtpAuth::backOfficeLink((int) $this->context->employee->id, (int) $r['id_register']),
            ];
        }
        $tabs = [];
        foreach ($this->tabs() as $k => $t) {
            $tabs[] = ['key' => $k, 'label' => $t[0], 'icon' => $t[1], 'url' => $this->baseUrl($k), 'active' => $k === $this->tab];
        }
        $this->context->smarty->assign([
            'jtp_registers' => $registers,
            'jtp_tabs' => $tabs,
            'jtp_version' => Josratpvpresta::VERSION,
            'jtp_pos_url' => $this->context->link->getModuleLink('josratpvpresta', 'pos'),
            'jtp_multishop_all' => Shop::isFeatureActive() && Shop::getContext() !== Shop::CONTEXT_SHOP,
        ]);

        return $this->output
            . $this->module->display(_PS_MODULE_DIR_ . 'josratpvpresta/josratpvpresta.php', 'views/templates/admin/header.tpl')
            . $content;
    }

    protected function process()
    {
        if (Tools::isSubmit('submitJtpGeneral')) {
            $this->saveGeneral();
        } elseif (Tools::isSubmit('submitJtpTicket')) {
            $this->saveKeys(['JTP_TICKET_HEADER', 'JTP_TICKET_FOOTER', 'JTP_TICKET_SHOW_LOGO', 'JTP_TICKET_SHOW_EMPLOYEE',
                'JTP_TICKET_SHOW_CUSTOMER', 'JTP_TICKET_SHOW_REF', 'JTP_TICKET_SHOW_TAXES', 'JTP_TICKET_BARCODE',
                'JTP_TICKET_WIDTH', 'JTP_TICKET_EMAIL', ]);
            $this->redirect('ticket');
        } elseif (Tools::isSubmit('submitJtpPrint')) {
            $this->savePrint();
        } elseif (Tools::isSubmit('submitJtpRegister')) {
            $this->saveRegister();
        } elseif (Tools::isSubmit('deletejtp_register')) {
            $this->deleteRegister((int) Tools::getValue('id_register'));
        } elseif (Tools::isSubmit('statusjtp_register')) {
            Db::getInstance()->execute('UPDATE ' . JtpTools::t('jtp_register') . ' SET active = 1 - active WHERE id_register = ' . (int) Tools::getValue('id_register'));
            $this->redirect('registers', 5);
        } elseif (Tools::isSubmit('submitJtpPayment')) {
            $this->savePayment();
        } elseif (Tools::isSubmit('statusjtp_payment_method')) {
            Db::getInstance()->execute('UPDATE ' . JtpTools::t('jtp_payment_method') . ' SET active = 1 - active WHERE id_payment_method = ' . (int) Tools::getValue('id_payment_method'));
            $this->redirect('payments', 5);
        } elseif (Tools::isSubmit('deletejtp_payment_method')) {
            Db::getInstance()->delete('jtp_payment_method', 'id_payment_method = ' . (int) Tools::getValue('id_payment_method') . ' AND code <> \'cash\'');
            $this->redirect('payments', 1);
        } elseif (Tools::isSubmit('submitJtpEmployee')) {
            $this->saveEmployee();
        } elseif (Tools::isSubmit('deletejtp_device')) {
            Db::getInstance()->update('jtp_device', ['revoked' => 1], 'id_device = \'' . pSQL(Tools::getValue('id_device')) . '\'');
            $this->redirect('devices', 1);
        } elseif (Tools::isSubmit('jtpQueueRetry')) {
            JtpQueue::retry((int) Tools::getValue('id_job'));
            $this->redirect('queue', 4);
        } elseif (Tools::isSubmit('jtpQueueDelete')) {
            JtpQueue::delete((int) Tools::getValue('id_job'));
            $this->redirect('queue', 1);
        } elseif (Tools::isSubmit('jtpQueueRetryAll')) {
            Db::getInstance()->update('jtp_queue', ['status' => JtpQueue::PENDING, 'attempts' => 0, 'next_try' => JtpTools::now()], 'status = \'' . JtpQueue::ERROR . '\'');
            $this->redirect('queue', 4);
        } elseif (Tools::isSubmit('jtpQzGenerate')) {
            $this->generateQzCertificate();
        } elseif (Tools::isSubmit('jtpQzDownload')) {
            $cert = (string) Configuration::get('JTP_QZ_CERT');
            if ($cert !== '') {
                header('Content-Type: application/x-x509-ca-cert');
                // Con el nombre que QZ Tray reconoce en su carpeta de instalación
                header('Content-Disposition: attachment; filename="override.crt"');
                echo $cert;
                exit;
            }
        } elseif (Tools::isSubmit('jtpQueueRun')) {
            $stats = JtpQueue::process(50);
            $this->ok(sprintf('Cola procesada: %d trabajos, %d correctos, %d con error.', $stats['processed'], $stats['ok'], $stats['failed']));
        } elseif (Tools::isSubmit('jtpAddonAction')) {
            $name = (string) Tools::getValue('jtp_addon');
            $action = (string) Tools::getValue('jtpAddonAction');
            if (!in_array($action, ['install', 'enable', 'disable', 'upgrade'], true)) {
                $this->err('Acción no válida.');
            } else {
                $error = JtpAddons::action($name, $action);
                if ($error === '') {
                    $this->redirect('addons', ['install' => 12, 'enable' => 5, 'disable' => 5, 'upgrade' => 4][$action]);
                }
                $this->err($name . ': ' . $error);
            }
        } elseif (Tools::isSubmit('submitJtpStates')) {
            try {
                JtpStates::repairAll(true);
                $this->redirect('general', 4);
            } catch (Exception $e) {
                $this->err($e->getMessage());
            }
        } elseif (Tools::isSubmit('submitJtpPurgeBackup') || Tools::isSubmit('submitJtpPurge')) {
            $this->processPurge();
        } elseif (Tools::isSubmit('viewjtp_sale')) {
            $sale = JtpSaleService::getSale((int) Tools::getValue('id_sale'));
            if ($sale) {
                Tools::redirectAdmin($this->orderLink((int) $sale['id_order']));
            }
        }
    }

    // ================================================================== Mantenimiento (limpiar datos de prueba)

    protected function isSuperAdmin()
    {
        return isset($this->context->employee) && (int) $this->context->employee->id_profile === (int) _PS_ADMIN_PROFILE_;
    }

    /** Elementos marcados en el formulario */
    protected function purgeKeys()
    {
        $keys = [];
        foreach (array_keys(JtpPurge::items()) as $k) {
            if (Tools::getValue('JTP_PURGE_' . $k)) {
                $keys[] = $k;
            }
        }
        if (Tools::getValue('JTP_PURGE_counters')) {
            $keys[] = 'counters';
        }

        return $keys;
    }

    protected function processPurge()
    {
        if (!$this->isSuperAdmin()) {
            $this->err('Solo un superadministrador puede limpiar los datos.');

            return;
        }
        $keys = $this->purgeKeys();
        if (!$keys) {
            $this->err('Marca qué quieres borrar.');

            return;
        }
        if (Tools::isSubmit('submitJtpPurgeBackup')) {
            $sql = JtpPurge::dump($keys);
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="tpv-copia-' . date('Ymd-His') . '.sql"');
            header('Content-Length: ' . strlen($sql));
            echo $sql;
            exit;
        }
        if (trim((string) Tools::getValue('jtp_purge_confirm')) !== 'BORRAR') {
            $this->err('Para borrar escribe BORRAR (en mayúsculas) en la casilla de confirmación.');

            return;
        }
        try {
            $r = JtpPurge::purge($keys, (int) $this->context->employee->id);
        } catch (Exception $e) {
            $this->err($e->getMessage());

            return;
        }
        $lines = [];
        foreach ($r['deleted'] as $label => $n) {
            $lines[] = htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ': ' . (int) $n;
        }
        if ($r['counters']) {
            $lines[] = 'Contadores reiniciados (el siguiente número será el 1): ' . htmlspecialchars(implode(', ', $r['counters']), ENT_QUOTES, 'UTF-8');
        }
        $msg = 'Datos de prueba borrados.<br>' . implode('<br>', $lines)
            . '<br>Copia de seguridad guardada en la tienda: <code>' . htmlspecialchars($r['backup'], ENT_QUOTES, 'UTF-8') . '</code>';
        $this->ok($msg);
        if ($r['kept']) {
            $this->output .= $this->module->displayWarning('No se han reiniciado estos contadores porque el primer número nuevo coincidiría con uno que ya existe: '
                . htmlspecialchars(implode('; ', $r['kept']), ENT_QUOTES, 'UTF-8')
                . '. Para empezar desde 1 sin borrar nada de PrestaShop ni de FacturaScripts, cambia el nombre de la serie (pestaña Cajas, o "Serie de pedidos web" en General).');
        }
    }

    protected function tabMaintenance()
    {
        if (!$this->isSuperAdmin()) {
            return $this->module->displayWarning('Solo un superadministrador puede limpiar los datos del TPV.');
        }
        $items = JtpPurge::items();
        $query = [];
        $values = ['jtp_purge_confirm' => ''];
        $group = null;
        foreach ($items as $k => $it) {
            $query[] = ['id' => $k, 'name' => $it['group'] . ' · ' . $it['label'] . ' (' . (int) $it['count'] . ')'];
            $values['JTP_PURGE_' . $k] = Tools::isSubmit('submitJtpPurge') ? (bool) Tools::getValue('JTP_PURGE_' . $k) : true;
        }
        $counters = JtpPurge::counters();
        $rows = '';
        foreach ($counters as $c) {
            $blocked = $c['orders'] > 0 || $c['fs'] > 0;
            $rows .= '<tr><td>' . htmlspecialchars($c['series'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $c['year'] . '</td><td>' . (int) $c['last_number'] . '</td><td>'
                . ($blocked ? '<span class="text-warning">No se reinicia: ' . ($c['orders'] ? (int) $c['orders'] . ' pedidos en PrestaShop' : '') . ($c['orders'] && $c['fs'] ? ' y ' : '') . ($c['fs'] ? (int) $c['fs'] . ' documentos en FacturaScripts' : '') . ' con esa numeración</span>'
                    : '<span class="text-success">Se reinicia (el siguiente será el 1)</span>') . '</td></tr>';
        }
        $query[] = ['id' => 'counters', 'name' => 'TPV · Contadores de numeración (tickets, devoluciones, pedidos web, órdenes de taller), solo los que se pueden reiniciar'];
        $values['JTP_PURGE_counters'] = Tools::isSubmit('submitJtpPurge') ? (bool) Tools::getValue('JTP_PURGE_counters') : true;
        $info = '<div class="alert alert-info">Borra los datos que han generado el TPV y sus addons mientras probabas, para empezar de cero. '
            . '<strong>No toca nada de PrestaShop</strong> (pedidos, clientes, productos, stock, carritos) <strong>ni la configuración</strong> de los módulos '
            . '(cajas, métodos de pago, empleados, dispositivos, formatos de etiquetas, tipos de ecotasa, enlaces de productos con FacturaScripts...). '
            . 'Tampoco borra nada en FacturaScripts: lo que se envió allí se limpia en FacturaScripts.</div>'
            . '<div class="alert alert-warning">No se puede deshacer. Antes de borrar se guarda una copia en la tienda (carpeta var/josratpvpresta-copias) y puedes descargarla con el botón de la izquierda.</div>'
            . ($rows ? '<table class="table"><thead><tr><th>Serie</th><th>Año</th><th>Último número</th><th>Al reiniciar</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '');
        $form = ['form' => [
            'legend' => ['title' => 'Limpiar datos de prueba', 'icon' => 'icon-eraser'],
            'input' => [
                ['type' => 'html', 'name' => 'jtp_purge_info', 'html_content' => $info],
                [
                    'type' => 'checkbox', 'label' => 'Qué se borra', 'name' => 'JTP_PURGE',
                    'values' => ['query' => $query, 'id' => 'id', 'name' => 'name'],
                    'desc' => 'Entre paréntesis, los registros que hay ahora.',
                ],
                [
                    'type' => 'text', 'label' => 'Confirmación', 'name' => 'jtp_purge_confirm', 'class' => 'fixed-width-lg',
                    'desc' => 'Escribe BORRAR para confirmar.',
                ],
            ],
            'buttons' => [
                ['type' => 'submit', 'name' => 'submitJtpPurgeBackup', 'title' => 'Descargar copia (SQL)', 'icon' => 'process-icon-download', 'class' => 'btn btn-default pull-left'],
            ],
            'submit' => ['title' => 'Borrar lo marcado', 'icon' => 'process-icon-delete', 'class' => 'btn btn-danger pull-right'],
        ]];

        return $this->renderForm($form, $values, 'submitJtpPurge');
    }

    protected function orderLink($idOrder)
    {
        return $this->context->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => (int) $idOrder], ['id_order' => (int) $idOrder, 'vieworder' => 1]);
    }

    protected function saveKeys(array $keys)
    {
        foreach ($keys as $k) {
            Configuration::updateValue($k, (string) Tools::getValue($k), true);
        }
    }

    protected function renderForm(array $form, array $values, $submit)
    {
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = $this->module->name;
        $helper->token = $this->token();
        $helper->currentIndex = $this->currentIndex();
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = $submit;
        $helper->show_toolbar = false;
        $helper->tpl_vars = [
            'fields_value' => $values,
            'languages' => $this->languages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm([$form]);
    }

    /** Opciones del desplegable de la dirección del cliente por defecto */
    protected function defaultAddressOptions($idCustomer)
    {
        $options = [['id' => 0, 'name' => 'Automática: su dirección "TPV" (la de la tienda)']];
        foreach (JtpCustomerService::defaultAddressChoices($idCustomer) as $id => $label) {
            $options[] = ['id' => $id, 'name' => $label];
        }

        return $options;
    }

    protected function values(array $keys)
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = Tools::getValue($k, Configuration::get($k));
        }

        return $out;
    }

    // ================================================================== General

    protected function saveGeneral()
    {
        $idCustomer = (int) Tools::getValue('JTP_DEFAULT_CUSTOMER');
        if (!Validate::isLoadedObject(new Customer($idCustomer))) {
            $this->err('El cliente por defecto no existe');

            return;
        }
        // Dirección del cliente por defecto: si se cambia de cliente, se vuelve a la automática y se elige después
        $idAddress = (int) Tools::getValue('JTP_DEFAULT_ADDRESS');
        if ($idCustomer !== (int) Configuration::get('JTP_DEFAULT_CUSTOMER')
            || ($idAddress && !JtpCustomerService::ownAddress($idCustomer, $idAddress))) {
            $idAddress = 0;
        }
        Configuration::updateValue('JTP_DEFAULT_ADDRESS', $idAddress);
        $series = Tools::strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) Tools::getValue('JTP_WEB_SERIES')));
        if ($series === '') {
            $series = 'W';
        }
        $route = trim(preg_replace('/[^a-z0-9\-\/]/', '', Tools::strtolower((string) Tools::getValue('JTP_ROUTE'))), '/');
        $wasDisabled = (bool) Configuration::get('JTP_DISABLE_PS_INVOICE');
        $disable = (bool) Tools::getValue('JTP_DISABLE_PS_INVOICE');
        if ($disable && !$wasDisabled) {
            Configuration::updateValue('JTP_PS_INVOICE_BACKUP', (int) Configuration::get('PS_INVOICE'));
            Configuration::updateValue('PS_INVOICE', 0);
        } elseif (!$disable && $wasDisabled) {
            Configuration::updateValue('PS_INVOICE', (int) Configuration::get('JTP_PS_INVOICE_BACKUP'));
        }
        Configuration::updateValue('JTP_DEFAULT_CUSTOMER', $idCustomer);
        Configuration::updateValue('JTP_WEB_SERIES', $series);
        Configuration::updateValue('JTP_ROUTE', $route ?: 'tpv');
        Configuration::updateValue('JTP_SEARCH_LIMIT', max(10, min(200, (int) Tools::getValue('JTP_SEARCH_LIMIT'))));
        Configuration::updateValue('JTP_VOUCHER_DAYS', max(1, (int) Tools::getValue('JTP_VOUCHER_DAYS')));
        Configuration::updateValue('JTP_SESSION_HOURS', max(1, min(72, (int) Tools::getValue('JTP_SESSION_HOURS'))));
        // Estados de pedido que usa el TPV (0 = el propio)
        foreach (JtpStates::definitions() as $def) {
            $idState = (int) Tools::getValue($def['use']);
            if ($idState) {
                $st = new OrderState($idState);
                if (!Validate::isLoadedObject($st) || (int) $st->deleted) {
                    $idState = 0;
                }
            }
            Configuration::updateValue($def['use'], $idState);
        }
        $refMode = (string) Tools::getValue('JTP_REF_MODE');
        $webRefMode = (string) Tools::getValue('JTP_WEB_REF_MODE');
        Configuration::updateValue('JTP_REF_MODE', in_array($refMode, ['ticket', 'id', 'none'], true) ? $refMode : 'ticket');
        Configuration::updateValue('JTP_WEB_REF_MODE', in_array($webRefMode, ['series', 'id', 'none'], true) ? $webRefMode : 'none');
        // compatibilidad con los interruptores anteriores (los addons o versiones viejas pueden leerlos)
        Configuration::updateValue('JTP_TICKET_AS_REFERENCE', (int) ($refMode === 'ticket'));
        Configuration::updateValue('JTP_WEB_NUMERIC_REF', (int) ($webRefMode === 'series'));
        foreach (['JTP_REQUIRE_SESSION', 'JTP_ALLOW_NO_STOCK', 'JTP_SHOW_OOS', 'JTP_DISABLE_PS_INVOICE', 'JTP_BEEP'] as $k) {
            Configuration::updateValue($k, (int) (bool) Tools::getValue($k));
        }
        $this->redirect('general');
    }

    protected function tabGeneral()
    {
        $defaultCustomer = new Customer((int) Configuration::get('JTP_DEFAULT_CUSTOMER'));
        $form = ['form' => [
            'legend' => ['title' => 'Ajustes generales', 'icon' => 'icon-cogs'],
            'input' => [
                [
                    'type' => 'text', 'label' => 'Cliente por defecto (ID)', 'name' => 'JTP_DEFAULT_CUSTOMER', 'class' => 'fixed-width-sm',
                    'desc' => 'Cliente para ventas sin identificar. Actual: ' . (Validate::isLoadedObject($defaultCustomer) ? $defaultCustomer->firstname . ' ' . $defaultCustomer->lastname : '—'),
                ],
                [
                    'type' => 'select', 'label' => 'Dirección del cliente por defecto', 'name' => 'JTP_DEFAULT_ADDRESS',
                    'options' => ['query' => $this->defaultAddressOptions((int) $defaultCustomer->id), 'id' => 'id', 'name' => 'name'],
                    'desc' => 'La que usan las ventas sin identificar. Importante si el cliente por defecto ya existía y tiene varias direcciones: si no se elige, se usa su dirección "TPV" (la de la tienda), y si no tiene, se le crea. Si cambias de cliente, guarda primero y elige después su dirección.',
                ],
                $this->sw('JTP_REQUIRE_SESSION', 'Exigir caja abierta', 'No se puede cobrar ni devolver sin abrir la caja (recomendado).'),
                $this->sw('JTP_ALLOW_NO_STOCK', 'Permitir vender sin stock', 'Para todos los empleados. También se puede dar el permiso por empleado.'),
                $this->sw('JTP_SHOW_OOS', 'Mostrar productos sin stock en la búsqueda'),
                ['type' => 'text', 'label' => 'Resultados por búsqueda', 'name' => 'JTP_SEARCH_LIMIT', 'class' => 'fixed-width-sm'],
                $this->sw('JTP_BEEP', 'Pitido al añadir productos', 'Pitido corto al añadir un producto (también con el lector) y uno grave si el código no se encuentra o hay un error.'),
                [
                    'type' => 'select', 'label' => 'Referencia de los pedidos del TPV', 'name' => 'JTP_REF_MODE',
                    'options' => ['query' => [
                        ['id' => 'ticket', 'name' => 'Número de ticket (T1-2026-000123)'],
                        ['id' => 'id', 'name' => 'ID del pedido (95298)'],
                        ['id' => 'none', 'name' => 'No tocar: la que pongan PrestaShop u otro módulo'],
                    ], 'id' => 'id', 'name' => 'name'],
                    'desc' => 'La pone el TPV al crear el pedido, aunque haya otro módulo que cambie las referencias.',
                ],
                [
                    'type' => 'select', 'label' => 'Referencia de los pedidos web', 'name' => 'JTP_WEB_REF_MODE',
                    'options' => ['query' => [
                        ['id' => 'none', 'name' => 'No tocar: la que pongan PrestaShop u otro módulo'],
                        ['id' => 'series', 'name' => 'Serie de abajo (W-2026-000123)'],
                        ['id' => 'id', 'name' => 'ID del pedido (95298)'],
                    ], 'id' => 'id', 'name' => 'name'],
                    'desc' => 'Pedidos de la tienda online. Si tienes un módulo que ya cambia las referencias, elige "No tocar" o el mismo formato para que no se pisen.',
                ],
                ['type' => 'text', 'label' => 'Serie de pedidos web', 'name' => 'JTP_WEB_SERIES', 'class' => 'fixed-width-md'],
                $this->sw('JTP_DISABLE_PS_INVOICE', 'Desactivar facturas de PrestaShop', 'Atención: afecta a toda la tienda (también pedidos web). Úsalo si facturas desde otro sistema (FacturaScripts). Los pedidos del TPV nunca generan factura de PrestaShop.'),
                ['type' => 'text', 'label' => 'Validez de vales por devolución (días)', 'name' => 'JTP_VOUCHER_DAYS', 'class' => 'fixed-width-sm'],
                ['type' => 'text', 'label' => 'Duración de la sesión de empleado (horas)', 'name' => 'JTP_SESSION_HOURS', 'class' => 'fixed-width-sm'],
                [
                    'type' => 'text', 'label' => 'URL amigable del TPV', 'name' => 'JTP_ROUTE', 'class' => 'fixed-width-lg',
                    'desc' => 'Con URL amigables activas el TPV queda en ' . Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . '<ruta>',
                ],
                [
                    'type' => 'select', 'label' => 'Estado de las ventas', 'name' => 'JTP_USE_OS_SALE',
                    'options' => ['query' => JtpStates::options('sale'), 'id' => 'id', 'name' => 'name'],
                    'desc' => 'Estado con el que se crean los pedidos del TPV. El del TPV no envía correos ni genera factura ni albarán de PrestaShop.',
                ],
                [
                    'type' => 'select', 'label' => 'Estado de las devoluciones totales', 'name' => 'JTP_USE_OS_RETURNED',
                    'options' => ['query' => JtpStates::options('returned'), 'id' => 'id', 'name' => 'name'],
                    'desc' => 'Estado al que pasa el pedido cuando se devuelve entero en el TPV.',
                ],
            ],
            'submit' => ['title' => 'Guardar'],
        ]];

        // Avisos de los estados de PrestaShop elegidos en lugar de los del TPV
        $warn = '';
        foreach (JtpStates::definitions() as $def) {
            $chosen = (int) Configuration::get($def['use']);
            if ($chosen && ($w = JtpStates::warnings($chosen))) {
                $warn .= $this->module->displayWarning($def['label'] . ' del TPV con un estado de PrestaShop que ' . implode('; ', $w) . '.');
            }
        }

        $values = $this->values([
            'JTP_DEFAULT_CUSTOMER', 'JTP_DEFAULT_ADDRESS', 'JTP_REQUIRE_SESSION', 'JTP_ALLOW_NO_STOCK', 'JTP_SHOW_OOS', 'JTP_SEARCH_LIMIT', 'JTP_BEEP',
            'JTP_WEB_SERIES', 'JTP_DISABLE_PS_INVOICE',
            'JTP_VOUCHER_DAYS', 'JTP_SESSION_HOURS', 'JTP_ROUTE', 'JTP_USE_OS_SALE', 'JTP_USE_OS_RETURNED',
        ]);
        // si aún no se ha guardado, lo que equivalía con los interruptores anteriores
        $values['JTP_REF_MODE'] = Tools::getValue('JTP_REF_MODE', JtpTools::posRefMode());
        $values['JTP_WEB_REF_MODE'] = Tools::getValue('JTP_WEB_REF_MODE', JtpTools::webRefMode());

        return $warn . $this->renderForm($form, $values, 'submitJtpGeneral') . $this->statesPanel();
    }

    /** Estado de los dos estados propios del TPV y botón para regenerarlos */
    protected function statesPanel()
    {
        $labels = [
            'ok' => '<span class="label label-success">Correcto</span>',
            'changed' => '<span class="label label-warning">Opciones cambiadas</span>',
            'deleted' => '<span class="label label-danger">Borrado</span>',
            'missing' => '<span class="label label-danger">No existe</span>',
        ];
        $rows = '';
        $bad = false;
        foreach (JtpStates::definitions() as $kind => $def) {
            $st = JtpStates::status($kind);
            // Si el TPV no tiene su estado (o está borrado), se crea o recupera ya
            if ($st['status'] === 'missing' || $st['status'] === 'deleted') {
                try {
                    JtpStates::regenerate($kind);
                    $note = $st['status'] === 'missing' ? 'Creado ahora: no existía ningún estado propio del TPV.' : 'Recuperado ahora: estaba borrado.';
                    $st = JtpStates::status($kind);
                    $st['issues'][] = $note;
                } catch (Exception $e) {
                    $st['issues'][] = $e->getMessage();
                }
            }
            $bad = $bad || $st['status'] !== 'ok';
            $chosen = (int) Configuration::get($def['use']);
            $rows .= '<tr><td>' . htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . ($st['id'] ? (int) $st['id'] : '—') . '</td>'
                . '<td><span style="display:inline-block;width:12px;height:12px;border-radius:2px;vertical-align:middle;background:'
                . htmlspecialchars($st['color'], ENT_QUOTES, 'UTF-8') . '"></span> ' . htmlspecialchars($st['name'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . $labels[$st['status']]
                . ($st['issues'] ? '<br><small>' . htmlspecialchars(implode(', ', $st['issues']), ENT_QUOTES, 'UTF-8') . '</small>' : '') . '</td>'
                . '<td>' . ($chosen ? '<span class="text-warning">No: usa otro estado (id ' . $chosen . ')</span>' : 'Sí') . '</td></tr>';
        }
        $html = '<p>El TPV usa dos estados de pedido propios, creados por él. Si no encuentra los suyos (o están borrados) los crea o recupera solo al abrir esta pestaña. '
            . 'Si les han cambiado las opciones, <strong>Regenerar estados</strong> les devuelve las correctas '
            . '(pagado y enviado; sin correo, factura ni albarán). Nunca toca estados que no sean del TPV ni los pedidos que ya tienen esos estados. '
            . 'Además, si al cobrar falta el estado de ventas, el TPV lo regenera solo y lo apunta en el registro de PrestaShop.</p>'
            . '<table class="table"><thead><tr><th>Para</th><th>ID</th><th>Estado</th><th>Situación</th><th>En uso</th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
        $form = ['form' => [
            'legend' => ['title' => 'Estados del TPV', 'icon' => 'icon-flag'],
            'input' => [['type' => 'html', 'name' => 'jtp_states_info', 'html_content' => $html]],
            'submit' => ['title' => 'Regenerar estados', 'icon' => $bad ? 'process-icon-refresh' : 'process-icon-refresh', 'class' => 'btn ' . ($bad ? 'btn-warning' : 'btn-default') . ' pull-right'],
        ]];

        return $this->renderForm($form, ['jtp_states_info' => ''], 'submitJtpStates');
    }

    // ================================================================== Ticket

    protected function tabTicket()
    {
        $form = ['form' => [
            'legend' => ['title' => 'Ticket', 'icon' => 'icon-file-text'],
            'input' => [
                ['type' => 'textarea', 'label' => 'Cabecera', 'name' => 'JTP_TICKET_HEADER', 'rows' => 4, 'desc' => 'Razón social, NIF, etc. Nombre, dirección y teléfono se toman de Parámetros de la tienda > Contacto.'],
                ['type' => 'textarea', 'label' => 'Pie', 'name' => 'JTP_TICKET_FOOTER', 'rows' => 4, 'desc' => 'Condiciones de cambio, agradecimiento…'],
                [
                    'type' => 'select', 'label' => 'Ancho de papel', 'name' => 'JTP_TICKET_WIDTH',
                    'options' => ['query' => [['id' => 80, 'name' => '80 mm (48 caracteres)'], ['id' => 58, 'name' => '58 mm (32 caracteres)']], 'id' => 'id', 'name' => 'name'],
                ],
                $this->sw('JTP_TICKET_SHOW_LOGO', 'Mostrar logotipo'),
                $this->sw('JTP_TICKET_SHOW_EMPLOYEE', 'Mostrar empleado'),
                $this->sw('JTP_TICKET_SHOW_CUSTOMER', 'Mostrar datos del cliente', 'Solo si no es el cliente por defecto.'),
                $this->sw('JTP_TICKET_SHOW_REF', 'Mostrar referencia de producto'),
                $this->sw('JTP_TICKET_SHOW_TAXES', 'Mostrar desglose de IVA'),
                $this->sw('JTP_TICKET_BARCODE', 'Código de barras del nº de ticket', 'Permite escanear el ticket para devoluciones.'),
                $this->sw('JTP_TICKET_EMAIL', 'Permitir enviar el ticket por email'),
            ],
            'submit' => ['title' => 'Guardar'],
        ]];

        return $this->renderForm($form, $this->values([
            'JTP_TICKET_HEADER', 'JTP_TICKET_FOOTER', 'JTP_TICKET_WIDTH', 'JTP_TICKET_SHOW_LOGO', 'JTP_TICKET_SHOW_EMPLOYEE',
            'JTP_TICKET_SHOW_CUSTOMER', 'JTP_TICKET_SHOW_REF', 'JTP_TICKET_SHOW_TAXES', 'JTP_TICKET_BARCODE', 'JTP_TICKET_EMAIL',
        ]), 'submitJtpTicket');
    }

    // ================================================================== Impresión

    protected function savePrint()
    {
        Configuration::updateValue('JTP_PRINT_MODE', Tools::getValue('JTP_PRINT_MODE') === 'qz' ? 'qz' : 'browser');
        Configuration::updateValue('JTP_QZ_FORMAT', Tools::getValue('JTP_QZ_FORMAT') === 'html' ? 'html' : 'escpos');
        Configuration::updateValue('JTP_QZ_QR', Tools::getValue('JTP_QZ_QR') === 'native' ? 'native' : 'image');
        Configuration::updateValue('JTP_TICKET_TOP_MM', max(0, min(30, (int) Tools::getValue('JTP_TICKET_TOP_MM'))));
        Configuration::updateValue('JTP_AUTO_PRINT', (int) (bool) Tools::getValue('JTP_AUTO_PRINT'));
        Configuration::updateValue('JTP_PRINT_COPIES', max(1, min(5, (int) Tools::getValue('JTP_PRINT_COPIES'))));
        $cert = trim((string) Tools::getValue('JTP_QZ_CERT'));
        Configuration::updateValue('JTP_QZ_CERT', $cert, true);
        $key = trim((string) Tools::getValue('JTP_QZ_KEY'));
        if (Tools::getValue('JTP_QZ_KEY_DELETE')) {
            Configuration::updateValue('JTP_QZ_KEY', '');
        } elseif ($key !== '') {
            if (!openssl_pkey_get_private($key)) {
                $this->err('La clave privada no es válida (debe ser PEM: -----BEGIN PRIVATE KEY-----)');

                return;
            }
            Configuration::updateValue('JTP_QZ_KEY', $key, true);
        }
        $this->redirect('print');
    }

    protected function tabPrint()
    {
        $hasKey = trim((string) Configuration::get('JTP_QZ_KEY')) !== '';
        $form = ['form' => [
            'legend' => ['title' => 'Impresión', 'icon' => 'icon-print'],
            'description' => 'Con QZ Tray el ticket sale directo a la impresora térmica, sin diálogo, y se abre el cajón. Sigue la guía de abajo.',
            'input' => [
                [
                    'type' => 'select', 'label' => 'Modo de impresión', 'name' => 'JTP_PRINT_MODE',
                    'options' => ['query' => [['id' => 'browser', 'name' => 'Navegador (diálogo de impresión)'], ['id' => 'qz', 'name' => 'QZ Tray (impresión directa)']], 'id' => 'id', 'name' => 'name'],
                ],
                [
                    'type' => 'select', 'label' => 'Formato con QZ Tray', 'name' => 'JTP_QZ_FORMAT',
                    'options' => ['query' => [['id' => 'escpos', 'name' => 'ESC/POS (impresoras térmicas, más rápido)'], ['id' => 'html', 'name' => 'HTML (cualquier impresora)']], 'id' => 'id', 'name' => 'name'],
                ],
                [
                    'type' => 'select', 'label' => 'Códigos QR en ESC/POS', 'name' => 'JTP_QZ_QR',
                    'options' => ['query' => [['id' => 'image', 'name' => 'Como imagen (funciona en todas las impresoras)'], ['id' => 'native', 'name' => 'Comando QR de la impresora (más rápido, no todas lo tienen)']], 'id' => 'id', 'name' => 'name'],
                    'desc' => 'Si el QR (p. ej. el de VERI*FACTU) no sale en el ticket pero sí el texto de debajo, usa "Como imagen".',
                ],
                ['type' => 'text', 'label' => 'Margen superior del ticket (mm)', 'name' => 'JTP_TICKET_TOP_MM', 'class' => 'fixed-width-sm',
                    'desc' => 'Para impresión con el navegador o con QZ Tray en formato HTML: muchas impresoras no imprimen los primeros milímetros y cortan el logo o el nombre de la tienda. En ESC/POS no hace falta.'],
                $this->sw('JTP_AUTO_PRINT', 'Imprimir automáticamente al cobrar'),
                ['type' => 'text', 'label' => 'Copias', 'name' => 'JTP_PRINT_COPIES', 'class' => 'fixed-width-sm'],
                ['type' => 'textarea', 'label' => 'Certificado QZ (PEM)', 'name' => 'JTP_QZ_CERT', 'rows' => 6, 'desc' => '-----BEGIN CERTIFICATE----- …'],
                ['type' => 'textarea', 'label' => 'Clave privada QZ (PEM)', 'name' => 'JTP_QZ_KEY', 'rows' => 6, 'desc' => $hasKey ? 'Hay una clave guardada. Déjalo vacío para conservarla.' : 'No hay clave guardada.'],
                $this->sw('JTP_QZ_KEY_DELETE', 'Borrar clave guardada'),
            ],
            'submit' => ['title' => 'Guardar'],
        ]];
        $values = $this->values(['JTP_PRINT_MODE', 'JTP_QZ_FORMAT', 'JTP_QZ_QR', 'JTP_TICKET_TOP_MM', 'JTP_AUTO_PRINT', 'JTP_PRINT_COPIES', 'JTP_QZ_CERT']);
        $values['JTP_QZ_KEY'] = '';
        $values['JTP_QZ_KEY_DELETE'] = 0;

        return $this->renderForm($form, $values, 'submitJtpPrint') . $this->qzGuide();
    }

    /**
     * Genera un certificado autofirmado y su clave privada para firmar las peticiones a QZ Tray.
     */
    protected function generateQzCertificate()
    {
        if (!function_exists('openssl_pkey_new')) {
            $this->err('La extensión OpenSSL de PHP no está disponible en el servidor');

            return;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (!$key) {
            $this->err('No se pudo generar la clave: ' . openssl_error_string());

            return;
        }
        $domain = Tools::getShopDomain(false) ?: 'tpv.local';
        $dn = [
            'countryName' => 'ES',
            'organizationName' => Tools::substr((string) (Configuration::get('PS_SHOP_NAME') ?: 'TPV'), 0, 60),
            'commonName' => Tools::substr($domain, 0, 60),
        ];
        $email = (string) Configuration::get('PS_SHOP_EMAIL');
        if (Validate::isEmail($email)) {
            $dn['emailAddress'] = $email;
        }
        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        $x509 = $csr ? openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], random_int(1000, 2000000000)) : false;
        if (!$x509) {
            $this->err('No se pudo generar el certificado: ' . openssl_error_string());

            return;
        }
        $certPem = '';
        $keyPem = '';
        openssl_x509_export($x509, $certPem);
        openssl_pkey_export($key, $keyPem);
        Configuration::updateValue('JTP_QZ_CERT', $certPem, true);
        Configuration::updateValue('JTP_QZ_KEY', $keyPem, true);
        $this->redirect('print', 4);
    }

    protected function qzGuide()
    {
        $cert = (string) Configuration::get('JTP_QZ_CERT');
        $info = null;
        if ($cert !== '' && function_exists('openssl_x509_parse')) {
            $p = @openssl_x509_parse($cert);
            if ($p) {
                $info = [
                    'cn' => isset($p['subject']['CN']) ? $p['subject']['CN'] : '',
                    'to' => date('d/m/Y', (int) $p['validTo_time_t']),
                ];
            }
        }
        $this->context->smarty->assign([
            'jtp_qz_has_cert' => $cert !== '',
            'jtp_qz_has_key' => trim((string) Configuration::get('JTP_QZ_KEY')) !== '',
            'jtp_qz_info' => $info,
            'jtp_qz_generate' => $this->baseUrl('print') . '&jtpQzGenerate=1',
            'jtp_qz_download' => $this->baseUrl('print') . '&jtpQzDownload=1',
            // Descarga directa en el navegador con el nombre override.crt (no depende de las cabeceras del servidor)
            'jtp_qz_cert_b64' => $cert !== '' ? base64_encode($cert) : '',
            'jtp_qz_mode' => Configuration::get('JTP_PRINT_MODE'),
            'jtp_registers_url' => $this->baseUrl('registers'),
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . 'josratpvpresta/josratpvpresta.php', 'views/templates/admin/qz_guide.tpl');
    }

    // ================================================================== Cajas

    protected function saveRegister()
    {
        $id = (int) Tools::getValue('id_register');
        $name = trim((string) Tools::getValue('name'));
        $series = Tools::strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) Tools::getValue('series')));
        $rseries = Tools::strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) Tools::getValue('return_series')));
        if ($name === '' || $series === '' || $rseries === '') {
            $this->err('Nombre, serie y serie de devoluciones son obligatorios (letras y números)');

            return;
        }
        if ($series === $rseries) {
            $this->err('La serie de devoluciones debe ser distinta a la de ventas');

            return;
        }
        $dup = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . JtpTools::t('jtp_register') . '
            WHERE id_register <> ' . $id . ' AND (series IN (\'' . pSQL($series) . '\', \'' . pSQL($rseries) . '\') OR return_series IN (\'' . pSQL($series) . '\', \'' . pSQL($rseries) . '\'))');
        if ($dup) {
            $this->err('Esa serie ya la usa otra caja');

            return;
        }
        $row = [
            'name' => pSQL($name),
            'series' => pSQL($series),
            'return_series' => pSQL($rseries),
            'printer' => pSQL(trim((string) Tools::getValue('printer'))),
            'printer_a4' => pSQL(trim((string) Tools::getValue('printer_a4'))),
            'open_drawer' => (int) (bool) Tools::getValue('open_drawer'),
            'active' => (int) (bool) Tools::getValue('active'),
        ];
        if ($id) {
            Db::getInstance()->update('jtp_register', $row, 'id_register = ' . $id);
        } else {
            $row['id_shop'] = (int) $this->context->shop->id;
            $row['date_add'] = JtpTools::now();
            Db::getInstance()->insert('jtp_register', $row);
        }
        $this->redirect('registers');
    }

    protected function deleteRegister($id)
    {
        $used = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . JtpTools::t('jtp_sale') . ' WHERE id_register = ' . (int) $id);
        if ($used) {
            Db::getInstance()->update('jtp_register', ['active' => 0], 'id_register = ' . (int) $id);
            $this->redirect('registers', 5);
        }
        Db::getInstance()->delete('jtp_register', 'id_register = ' . (int) $id);
        $this->redirect('registers', 1);
    }

    protected function tabRegisters()
    {
        if (Tools::isSubmit('addjtp_register') || Tools::isSubmit('updatejtp_register') || Tools::isSubmit('submitJtpRegister')) {
            $id = (int) Tools::getValue('id_register');
            $r = $id ? JtpRegisters::get($id) : null;
            $n = count(JtpRegisters::forShop(null, false)) + 1;
            $values = [
                'id_register' => $id,
                'name' => Tools::getValue('name', $r ? $r['name'] : 'Caja ' . $n),
                'series' => Tools::getValue('series', $r ? $r['series'] : 'T' . $n),
                'return_series' => Tools::getValue('return_series', $r ? $r['return_series'] : 'T' . $n . 'D'),
                'printer' => Tools::getValue('printer', $r ? $r['printer'] : ''),
                'printer_a4' => Tools::getValue('printer_a4', $r && isset($r['printer_a4']) ? $r['printer_a4'] : ''),
                'open_drawer' => Tools::getValue('open_drawer', $r ? $r['open_drawer'] : 1),
                'active' => Tools::getValue('active', $r ? $r['active'] : 1),
            ];
            $form = ['form' => [
                'legend' => ['title' => $id ? 'Editar caja' : 'Nueva caja', 'icon' => 'icon-inbox'],
                'input' => [
                    ['type' => 'hidden', 'name' => 'id_register'],
                    ['type' => 'text', 'label' => 'Nombre', 'name' => 'name', 'required' => true],
                    ['type' => 'text', 'label' => 'Serie de tickets', 'name' => 'series', 'required' => true, 'class' => 'fixed-width-md', 'desc' => 'Ej. T1 → T1-2026-000001. Se reinicia cada año.'],
                    ['type' => 'text', 'label' => 'Serie de devoluciones', 'name' => 'return_series', 'required' => true, 'class' => 'fixed-width-md', 'desc' => 'Ej. T1D → T1D-2026-000001'],
                    ['type' => 'text', 'label' => 'Impresora (QZ Tray)', 'name' => 'printer', 'desc' => 'Nombre exacto de la impresora en el sistema. Vacío = impresora predeterminada.'],
                    ['type' => 'text', 'label' => 'Impresora A4 (QZ Tray)', 'name' => 'printer_a4', 'desc' => 'Opcional. Nombre exacto de una impresora de folios para documentos en PDF (p. ej. las facturas completas de FacturaScripts). Vacío = sin impresora A4: el PDF se abre en el navegador.'],
                    $this->sw('open_drawer', 'Abrir cajón en cobros en efectivo'),
                    $this->sw('active', 'Activa'),
                ],
                'submit' => ['title' => 'Guardar'],
                'buttons' => [['href' => $this->baseUrl('registers'), 'title' => 'Volver', 'icon' => 'process-icon-back']],
            ]];

            return $this->renderForm($form, $values, 'submitJtpRegister');
        }
        $rows = JtpRegisters::forShop(null, false);
        foreach ($rows as &$r) {
            $s = JtpSessions::openFor($r['id_register']);
            $r['session'] = $s ? 'Abierta desde ' . $s['date_open'] : 'Cerrada';
            $r['next'] = JtpTools::formatNumber($r['series'], date('Y'), JtpCounter::peek($r['series']) + 1);
        }
        unset($r);
        $helper = $this->listHelper('jtp_register', 'id_register', 'Cajas', ['edit', 'delete']);
        $helper->toolbar_btn['new'] = ['href' => $this->currentIndex('registers') . '&addjtp_register&token=' . $this->token(), 'desc' => 'Nueva caja'];

        return $this->newButton($this->currentIndex('registers') . '&addjtp_register&token=' . $this->token(), 'Añadir caja') . $helper->generateList($rows, [
            'id_register' => ['title' => 'ID', 'class' => 'fixed-width-xs'],
            'name' => ['title' => 'Nombre'],
            'series' => ['title' => 'Serie'],
            'return_series' => ['title' => 'Serie devol.'],
            'next' => ['title' => 'Siguiente nº'],
            'printer' => ['title' => 'Impresora'],
            'session' => ['title' => 'Caja'],
            'active' => ['title' => 'Activa', 'active' => 'status', 'type' => 'bool', 'align' => 'center', 'class' => 'fixed-width-sm'],
        ]);
    }

    protected function listHelper($table, $identifier, $title, array $actions)
    {
        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->show_toolbar = true;
        $helper->no_link = true;
        $helper->actions = $actions;
        $helper->identifier = $identifier;
        $helper->table = $table;
        $helper->title = $title;
        $helper->token = $this->token();
        $helper->currentIndex = $this->currentIndex();
        $helper->module = $this->module;
        $helper->toolbar_btn = [];

        return $helper;
    }

    /** Botón "Añadir" sobre una lista (la cabecera simple de HelperList no muestra los de la barra) */
    protected function newButton($href, $label)
    {
        return '<p><a class="btn btn-default" href="' . htmlspecialchars($href, ENT_QUOTES) . '"><i class="icon-plus-sign"></i> ' . htmlspecialchars($label, ENT_QUOTES) . '</a></p>';
    }

    // ================================================================== Métodos de pago

    protected function savePayment()
    {
        $id = (int) Tools::getValue('id_payment_method');
        $name = trim((string) Tools::getValue('name'));
        $code = Tools::strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) Tools::getValue('code')));
        if ($name === '' || $code === '') {
            $this->err('Nombre y código son obligatorios');

            return;
        }
        $dup = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . JtpTools::t('jtp_payment_method') . ' WHERE code = \'' . pSQL($code) . '\' AND id_payment_method <> ' . $id);
        if ($dup || $code === 'voucher') {
            $this->err('Ese código ya existe o está reservado');

            return;
        }
        $row = [
            'name' => pSQL($name),
            'is_cash' => (int) (bool) Tools::getValue('is_cash'),
            'allow_change' => (int) (bool) Tools::getValue('allow_change'),
            'active' => (int) (bool) Tools::getValue('active'),
            'position' => (int) Tools::getValue('position'),
        ];
        if ($id) {
            Db::getInstance()->update('jtp_payment_method', $row, 'id_payment_method = ' . $id);
        } else {
            $row['code'] = pSQL($code);
            $row['id_shop'] = 0;
            Db::getInstance()->insert('jtp_payment_method', $row);
        }
        $this->redirect('payments');
    }

    protected function tabPayments()
    {
        if (Tools::isSubmit('addjtp_payment_method') || Tools::isSubmit('updatejtp_payment_method') || Tools::isSubmit('submitJtpPayment')) {
            $id = (int) Tools::getValue('id_payment_method');
            $r = $id ? Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_payment_method') . ' WHERE id_payment_method = ' . $id) : null;
            $values = [
                'id_payment_method' => $id,
                'code' => Tools::getValue('code', $r ? $r['code'] : ''),
                'name' => Tools::getValue('name', $r ? $r['name'] : ''),
                'is_cash' => Tools::getValue('is_cash', $r ? $r['is_cash'] : 0),
                'allow_change' => Tools::getValue('allow_change', $r ? $r['allow_change'] : 0),
                'active' => Tools::getValue('active', $r ? $r['active'] : 1),
                'position' => Tools::getValue('position', $r ? $r['position'] : 10),
            ];
            $form = ['form' => [
                'legend' => ['title' => $id ? 'Editar método de pago' : 'Nuevo método de pago', 'icon' => 'icon-credit-card'],
                'input' => [
                    ['type' => 'hidden', 'name' => 'id_payment_method'],
                    ['type' => 'text', 'label' => 'Código', 'name' => 'code', 'required' => true, 'class' => 'fixed-width-lg', 'readonly' => (bool) $id, 'desc' => 'Identificador interno (sin espacios). Lo usan los addons, p. ej. para mapear formas de pago de FacturaScripts.'],
                    ['type' => 'text', 'label' => 'Nombre', 'name' => 'name', 'required' => true],
                    $this->sw('is_cash', 'Es efectivo', 'Cuenta en el arqueo de caja y abre el cajón.'),
                    $this->sw('allow_change', 'Admite cambio', 'Permite entregar más del total y devolver la diferencia.'),
                    ['type' => 'text', 'label' => 'Posición', 'name' => 'position', 'class' => 'fixed-width-sm'],
                    $this->sw('active', 'Activo'),
                ],
                'submit' => ['title' => 'Guardar'],
                'buttons' => [['href' => $this->baseUrl('payments'), 'title' => 'Volver', 'icon' => 'process-icon-back']],
            ]];

            return $this->renderForm($form, $values, 'submitJtpPayment');
        }
        $rows = Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('jtp_payment_method') . ' ORDER BY position, id_payment_method') ?: [];
        $helper = $this->listHelper('jtp_payment_method', 'id_payment_method', 'Métodos de pago', ['edit', 'delete']);
        $helper->toolbar_btn['new'] = ['href' => $this->currentIndex('payments') . '&addjtp_payment_method&token=' . $this->token(), 'desc' => 'Nuevo método'];
        $html = $this->newButton($this->currentIndex('payments') . '&addjtp_payment_method&token=' . $this->token(), 'Añadir método de pago') . $helper->generateList($rows, [
            'id_payment_method' => ['title' => 'ID', 'class' => 'fixed-width-xs'],
            'code' => ['title' => 'Código'],
            'name' => ['title' => 'Nombre'],
            'is_cash' => ['title' => 'Efectivo', 'type' => 'bool', 'align' => 'center'],
            'allow_change' => ['title' => 'Cambio', 'type' => 'bool', 'align' => 'center'],
            'position' => ['title' => 'Posición', 'class' => 'fixed-width-sm'],
            'active' => ['title' => 'Activo', 'active' => 'status', 'type' => 'bool', 'align' => 'center', 'class' => 'fixed-width-sm'],
        ]);
        $addonMethods = array_filter(JtpRegisters::paymentMethods(false), function ($m) {
            return $m['source'] !== 'base';
        });
        if ($addonMethods) {
            $this->context->smarty->assign(['jtp_addon_methods' => $addonMethods]);
            $html .= $this->module->display(_PS_MODULE_DIR_ . 'josratpvpresta/josratpvpresta.php', 'views/templates/admin/addon_methods.tpl');
        }

        return $html;
    }

    // ================================================================== Empleados

    protected function saveEmployee()
    {
        $id = (int) Tools::getValue('id_employee');
        $emp = new Employee($id);
        if (!Validate::isLoadedObject($emp)) {
            $this->err('Empleado no encontrado');

            return;
        }
        $pin = trim((string) Tools::getValue('pin'));
        if ($pin !== '' && !preg_match('/^\d{4,8}$/', $pin)) {
            $this->err('El PIN debe tener entre 4 y 8 números');

            return;
        }
        $data = ['enabled' => Tools::getValue('enabled')];
        foreach (array_keys(JtpEmployees::permissionKeys()) as $k) {
            $data[$k] = Tools::getValue($k);
        }
        JtpEmployees::save($id, $data, $pin);
        if (Tools::getValue('pin_delete')) {
            Db::getInstance()->update('jtp_employee', ['pin' => ''], 'id_employee = ' . $id);
        }
        $this->redirect('employees');
    }

    protected function tabEmployees()
    {
        if (Tools::isSubmit('updatejtp_employee') || Tools::isSubmit('submitJtpEmployee')) {
            $id = (int) Tools::getValue('id_employee');
            $emp = new Employee($id);
            $row = JtpEmployees::get($id);
            $isAdmin = (int) $emp->id_profile === (int) _PS_ADMIN_PROFILE_;
            $inputs = [
                ['type' => 'hidden', 'name' => 'id_employee'],
                $this->sw('enabled', 'Puede usar el TPV con PIN'),
                ['type' => 'password', 'label' => 'PIN (4-8 números)', 'name' => 'pin', 'class' => 'fixed-width-md', 'desc' => ($row && $row['pin'] !== '') ? 'Tiene PIN. Déjalo vacío para conservarlo.' : 'Sin PIN.'],
                $this->sw('pin_delete', 'Borrar PIN'),
            ];
            $values = ['id_employee' => $id, 'enabled' => $row ? $row['enabled'] : 1, 'pin' => '', 'pin_delete' => 0];
            $defaults = ['perm_discount' => 1, 'perm_price' => 0, 'perm_return' => 1, 'perm_cash_move' => 1, 'perm_close' => 1, 'perm_no_stock' => 0, 'perm_all_sales' => 0, 'perm_favorites' => 1];
            foreach (JtpEmployees::permissionKeys() as $k => $label) {
                $inputs[] = $this->sw($k, $label);
                $values[$k] = $row ? $row[$k] : $defaults[$k];
            }
            $form = ['form' => [
                'legend' => ['title' => 'TPV: ' . $emp->firstname . ' ' . $emp->lastname, 'icon' => 'icon-user'],
                'description' => $isAdmin ? 'Este empleado es SuperAdmin: en el TPV tiene todos los permisos.' : null,
                'input' => $inputs,
                'submit' => ['title' => 'Guardar'],
                'buttons' => [['href' => $this->baseUrl('employees'), 'title' => 'Volver', 'icon' => 'process-icon-back']],
            ]];

            return $this->renderForm($form, $values, 'submitJtpEmployee');
        }
        $rows = Db::getInstance()->executeS('SELECT e.id_employee, CONCAT(e.firstname, \' \', e.lastname) name, e.email, pl.name profile,
                IFNULL(j.enabled, 0) enabled, IF(IFNULL(j.pin, \'\') <> \'\', 1, 0) has_pin
            FROM ' . JtpTools::t('employee') . ' e
            LEFT JOIN ' . JtpTools::t('profile_lang') . ' pl ON (pl.id_profile = e.id_profile AND pl.id_lang = ' . (int) $this->context->language->id . ')
            LEFT JOIN ' . JtpTools::t('jtp_employee') . ' j ON j.id_employee = e.id_employee
            WHERE e.active = 1 ORDER BY e.firstname') ?: [];
        $helper = $this->listHelper('jtp_employee', 'id_employee', 'Empleados del TPV', ['edit']);

        return $helper->generateList($rows, [
            'id_employee' => ['title' => 'ID', 'class' => 'fixed-width-xs'],
            'name' => ['title' => 'Empleado'],
            'email' => ['title' => 'Email'],
            'profile' => ['title' => 'Perfil'],
            'enabled' => ['title' => 'TPV con PIN', 'type' => 'bool', 'align' => 'center'],
            'has_pin' => ['title' => 'PIN', 'type' => 'bool', 'align' => 'center'],
        ]);
    }

    // ================================================================== Dispositivos

    protected function tabDevices()
    {
        $rows = Db::getInstance()->executeS('SELECT d.*, CONCAT(e.firstname, \' \', e.lastname) employee
            FROM ' . JtpTools::t('jtp_device') . ' d LEFT JOIN ' . JtpTools::t('employee') . ' e ON e.id_employee = d.id_employee_auth
            WHERE d.revoked = 0 ORDER BY d.last_seen DESC') ?: [];
        foreach ($rows as &$r) {
            $r['short'] = Tools::substr($r['id_device'], 0, 8);
        }
        unset($r);
        $helper = $this->listHelper('jtp_device', 'id_device', 'Dispositivos autorizados', ['delete']);

        return $helper->generateList($rows, [
            'short' => ['title' => 'ID'],
            'name' => ['title' => 'Equipo / navegador'],
            'employee' => ['title' => 'Autorizado por'],
            'date_add' => ['title' => 'Autorizado', 'type' => 'datetime'],
            'last_seen' => ['title' => 'Último uso', 'type' => 'datetime'],
        ]) . '<div class="alert alert-info">Un dispositivo queda autorizado al abrir el TPV desde este panel. Después, en ese equipo, los empleados pueden entrar con su PIN. Eliminar un dispositivo le quita el acceso.</div>';
    }

    // ================================================================== Ventas

    protected function tabSales()
    {
        $from = Tools::getValue('jtp_from', date('Y-m-d', strtotime('-7 days')));
        $to = Tools::getValue('jtp_to', date('Y-m-d'));
        if (!Validate::isDate($from)) {
            $from = date('Y-m-d');
        }
        if (!Validate::isDate($to)) {
            $to = date('Y-m-d');
        }
        $form = ['form' => [
            'legend' => ['title' => 'Filtrar ventas', 'icon' => 'icon-filter'],
            'input' => [
                ['type' => 'date', 'label' => 'Desde', 'name' => 'jtp_from'],
                ['type' => 'date', 'label' => 'Hasta', 'name' => 'jtp_to'],
            ],
            'submit' => ['title' => 'Filtrar', 'icon' => 'process-icon-refresh'],
        ]];
        $html = $this->renderForm($form, ['jtp_from' => $from, 'jtp_to' => $to], 'submitJtpSalesFilter');
        $rows = Db::getInstance()->executeS('SELECT s.id_sale, s.id_order, s.ticket_number, s.date_add, s.total, s.returned_total,
                r.name register, CONCAT(e.firstname, \' \', e.lastname) employee,
                IF(s.id_customer = ' . JtpCustomerService::defaultId() . ', \'\', CONCAT(c.firstname, \' \', c.lastname)) customer,
                (SELECT GROUP_CONCAT(p.name SEPARATOR \' + \') FROM ' . JtpTools::t('jtp_sale_payment') . ' p WHERE p.id_sale = s.id_sale) payment
            FROM ' . JtpTools::t('jtp_sale') . ' s
            LEFT JOIN ' . JtpTools::t('jtp_register') . ' r ON r.id_register = s.id_register
            LEFT JOIN ' . JtpTools::t('employee') . ' e ON e.id_employee = s.id_employee
            LEFT JOIN ' . JtpTools::t('customer') . ' c ON c.id_customer = s.id_customer
            WHERE s.id_shop = ' . (int) $this->context->shop->id . ' AND DATE(s.date_add) BETWEEN \'' . pSQL($from) . '\' AND \'' . pSQL($to) . '\'
            ORDER BY s.id_sale DESC LIMIT 1000') ?: [];
        $sum = 0;
        $ret = 0;
        foreach ($rows as $r) {
            $sum += (float) $r['total'];
            $ret += (float) $r['returned_total'];
        }
        $helper = $this->listHelper('jtp_sale', 'id_sale', sprintf('Ventas: %d tickets · %s · devuelto %s', count($rows), JtpTools::money($sum), JtpTools::money($ret)), ['view']);
        $helper->currentIndex .= '&jtp_from=' . urlencode($from) . '&jtp_to=' . urlencode($to);

        return $html . $helper->generateList($rows, [
            'ticket_number' => ['title' => 'Ticket'],
            'date_add' => ['title' => 'Fecha', 'type' => 'datetime'],
            'register' => ['title' => 'Caja'],
            'employee' => ['title' => 'Empleado'],
            'customer' => ['title' => 'Cliente'],
            'payment' => ['title' => 'Pago'],
            'total' => ['title' => 'Total', 'type' => 'price', 'align' => 'text-right'],
            'returned_total' => ['title' => 'Devuelto', 'type' => 'price', 'align' => 'text-right'],
            'id_order' => ['title' => 'Pedido', 'class' => 'fixed-width-sm'],
        ]);
    }

    // ================================================================== Cierres

    protected function tabSessions()
    {
        if (Tools::isSubmit('viewjtp_session')) {
            $summary = JtpSessions::summary((int) Tools::getValue('id_session'));
            if ($summary) {
                $this->context->smarty->assign(['jtp_s' => $summary, 'jtp_back' => $this->baseUrl('sessions')]);

                return $this->module->display(_PS_MODULE_DIR_ . 'josratpvpresta/josratpvpresta.php', 'views/templates/admin/session.tpl');
            }
        }
        $rows = Db::getInstance()->executeS('SELECT s.*, r.name register,
                CONCAT(eo.firstname, \' \', eo.lastname) emp_open, CONCAT(ec.firstname, \' \', ec.lastname) emp_close,
                IF(s.status = \'open\', \'Abierta\', \'Cerrada\') status_label
            FROM ' . JtpTools::t('jtp_session') . ' s
            LEFT JOIN ' . JtpTools::t('jtp_register') . ' r ON r.id_register = s.id_register
            LEFT JOIN ' . JtpTools::t('employee') . ' eo ON eo.id_employee = s.id_employee_open
            LEFT JOIN ' . JtpTools::t('employee') . ' ec ON ec.id_employee = s.id_employee_close
            WHERE s.id_shop = ' . (int) $this->context->shop->id . ' ORDER BY s.id_session DESC LIMIT 300') ?: [];
        $helper = $this->listHelper('jtp_session', 'id_session', 'Aperturas y cierres de caja', ['view']);

        return $helper->generateList($rows, [
            'id_session' => ['title' => 'ID', 'class' => 'fixed-width-xs'],
            'register' => ['title' => 'Caja'],
            'date_open' => ['title' => 'Apertura', 'type' => 'datetime'],
            'emp_open' => ['title' => 'Abrió'],
            'date_close' => ['title' => 'Cierre', 'type' => 'datetime'],
            'emp_close' => ['title' => 'Cerró'],
            'expected_cash' => ['title' => 'Efectivo esperado', 'type' => 'price', 'align' => 'text-right'],
            'counted_cash' => ['title' => 'Contado', 'type' => 'price', 'align' => 'text-right'],
            'difference' => ['title' => 'Diferencia', 'type' => 'price', 'align' => 'text-right'],
            'status_label' => ['title' => 'Estado'],
        ]);
    }

    // ================================================================== Cola

    protected function tabQueue()
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('jtp_queue') . ' ORDER BY FIELD(status, \'error\', \'running\', \'pending\', \'done\'), id_job DESC LIMIT 300') ?: [];
        $counts = Db::getInstance()->executeS('SELECT status, COUNT(*) n FROM ' . JtpTools::t('jtp_queue') . ' GROUP BY status') ?: [];
        $c = ['pending' => 0, 'running' => 0, 'error' => 0, 'done' => 0];
        foreach ($counts as $r) {
            $c[$r['status']] = (int) $r['n'];
        }
        $this->context->smarty->assign([
            'jtp_jobs' => $rows,
            'jtp_counts' => $c,
            'jtp_url' => $this->baseUrl('queue'),
            'jtp_cron' => $this->context->link->getModuleLink('josratpvpresta', 'cron', ['token' => Configuration::getGlobalValue('JTP_CRON_TOKEN')]),
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . 'josratpvpresta/josratpvpresta.php', 'views/templates/admin/queue.tpl');
    }

    // ================================================================== Addons

    protected function tabAddons()
    {
        $addons = JtpAddons::disk();
        foreach ($addons as &$a) {
            $a['configure'] = $a['enabled'] ? $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $a['module']]) : '';
            $a['action_url'] = $this->baseUrl('addons') . '&jtp_addon=' . urlencode($a['module']);
            $a['missing_txt'] = implode(', ', $a['missing']);
        }
        unset($a);
        $this->context->smarty->assign([
            'jtp_addons' => $addons,
            'jtp_hooks' => JtpInstaller::customHooks(),
            'jtp_addons_html' => Hook::exec('displayJtpAdminConfig'),
        ]);

        return $this->module->display(_PS_MODULE_DIR_ . 'josratpvpresta/josratpvpresta.php', 'views/templates/admin/addons.tpl');
    }
}
