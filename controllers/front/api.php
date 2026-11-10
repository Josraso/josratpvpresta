<?php
/**
 * josratpvpresta - API JSON de la pantalla de venta.
 *
 * POST JSON {"action": "...", "data": {...}} con cabecera X-JTP-CSRF.
 * Respuesta {"ok": true, "data": ...} o {"ok": false, "error": "...", "data": {...}}
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'josratpvpresta/classes/JtpFrontController.php';

class JosratpvprestaApiModuleFrontController extends JtpFrontController
{
    protected $response = null;

    /** Acciones sin sesión de empleado (requieren dispositivo autorizado) */
    protected static $public = ['employees', 'login'];

    /** Acciones que no necesitan caja seleccionada */
    protected static $noRegister = ['boot', 'logout', 'selectRegister', 'employees', 'login'];

    public function init()
    {
        // Cualquier aviso PHP de otros módulos no debe romper el JSON
        ob_start();
        parent::init();
    }

    public function initContent()
    {
    }

    public function postProcess()
    {
        $raw = Tools::file_get_contents('php://input');
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            $body = ['action' => Tools::getValue('action'), 'data' => []];
        }
        $action = isset($body['action']) ? (string) $body['action'] : '';
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : [];
        try {
            $this->response = ['ok' => true, 'data' => $this->dispatch($action, $data)];
        } catch (JtpException $e) {
            $this->response = ['ok' => false, 'error' => $e->getMessage(), 'data' => $e->data];
        } catch (Exception $e) {
            PrestaShopLogger::addLog('josratpvpresta API [' . $action . ']: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 3);
            $this->response = ['ok' => false, 'error' => 'Error interno: ' . $e->getMessage(), 'data' => []];
        } catch (Throwable $e) {
            PrestaShopLogger::addLog('josratpvpresta API [' . $action . ']: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), 3);
            $this->response = ['ok' => false, 'error' => 'Error interno: ' . $e->getMessage(), 'data' => []];
        }
    }

    public function display()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo JtpTools::jsonEncode($this->response === null ? ['ok' => false, 'error' => 'Sin respuesta'] : $this->response);

        return true;
    }

    // ------------------------------------------------------------------ helpers

    protected function employeeId()
    {
        return (int) $this->jtpSession['e'];
    }

    protected function requirePerm($perm, $msg)
    {
        if (!JtpEmployees::can($this->employeeId(), $perm)) {
            throw new JtpException($msg);
        }
    }

    protected function cart()
    {
        return new JtpCartService($this->jtpRegister, $this->employeeId());
    }

    protected function v(array $d, $k, $default = null)
    {
        return array_key_exists($k, $d) ? $d[$k] : $default;
    }

    protected function dispatch($action, array $d)
    {
        if (!JtpAuth::currentDevice()) {
            throw new JtpException('Dispositivo no autorizado', ['auth' => 'device']);
        }
        if (!in_array($action, self::$public)) {
            if (!$this->jtpSession) {
                throw new JtpException('Sesión caducada. Vuelve a entrar.', ['auth' => 'login']);
            }
            $csrf = isset($_SERVER['HTTP_X_JTP_CSRF']) ? (string) $_SERVER['HTTP_X_JTP_CSRF'] : '';
            if (!hash_equals((string) $this->jtpSession['c'], $csrf)) {
                throw new JtpException('Token de seguridad no válido. Recarga la página.', ['auth' => 'csrf']);
            }
            if (!in_array($action, self::$noRegister) && !$this->jtpRegister) {
                throw new JtpException('Selecciona una caja', ['auth' => 'register']);
            }
        }
        $method = 'a' . str_replace(['.', '_', '-'], '', ucwords($action, '._-'));
        if (!method_exists($this, $method)) {
            throw new JtpException('Acción desconocida: ' . $action);
        }

        return $this->{$method}($d);
    }

    // ================================================================== sesión

    protected function aEmployees(array $d)
    {
        $out = [];
        foreach (JtpEmployees::pinList() as $e) {
            $out[] = ['id' => (int) $e['id_employee'], 'name' => trim($e['firstname'] . ' ' . $e['lastname'])];
        }

        return $out;
    }

    protected function aLogin(array $d)
    {
        $r = JtpAuth::loginPin((int) $this->v($d, 'id_employee'), (string) $this->v($d, 'pin'));
        if (!is_array($r)) {
            throw new JtpException($r);
        }

        return ['csrf' => $r['c']];
    }

    protected function aLogout(array $d)
    {
        JtpAuth::logout();

        return true;
    }

    protected function aSelectRegister(array $d)
    {
        $reg = JtpRegisters::get((int) $this->v($d, 'id_register'));
        if (!$reg || !(int) $reg['active'] || (int) $reg['id_shop'] !== JtpTools::shopId()) {
            throw new JtpException('Caja no válida');
        }
        JtpAuth::setRegister((int) $reg['id_register']);
        $this->jtpSession['r'] = (int) $reg['id_register'];
        $this->jtpRegister = $reg;

        return $this->aBoot([]);
    }

    protected function printConfig()
    {
        $reg = $this->jtpRegister;

        return [
            'mode' => (string) JtpTools::cfg('JTP_PRINT_MODE', 'browser'),
            'format' => (string) JtpTools::cfg('JTP_QZ_FORMAT', 'escpos'),
            'qr' => JtpTools::cfg('JTP_QZ_QR', 'image') === 'native' ? 'native' : 'image',
            'top_mm' => (int) JtpTools::cfg('JTP_TICKET_TOP_MM', 4),
            'beep' => JtpTools::cfg('JTP_BEEP', 1) ? true : false,
            'printer' => $reg ? (string) $reg['printer'] : '',
            'printer_a4' => $reg && isset($reg['printer_a4']) ? (string) $reg['printer_a4'] : '',
            'open_drawer' => $reg ? (bool) $reg['open_drawer'] : false,
            'width' => (int) JtpTools::cfg('JTP_TICKET_WIDTH', 80),
            'auto' => JtpTools::cfgBool('JTP_AUTO_PRINT'),
            'copies' => max(1, (int) JtpTools::cfg('JTP_PRINT_COPIES', 1)),
            'signed' => trim((string) Configuration::get('JTP_QZ_CERT')) !== '' && trim((string) Configuration::get('JTP_QZ_KEY')) !== '',
            'email' => JtpTools::cfgBool('JTP_TICKET_EMAIL'),
        ];
    }

    protected function aBoot(array $d)
    {
        $idEmployee = $this->employeeId();
        $out = [
            'employee' => ['id' => $idEmployee, 'name' => JtpEmployees::name($idEmployee)],
            'permissions' => JtpEmployees::permissions($idEmployee),
            'registers' => array_map(function ($r) {
                return ['id' => (int) $r['id_register'], 'name' => $r['name'], 'series' => $r['series']];
            }, JtpRegisters::forShop()),
            'register' => null,
            'session' => null,
            'addons' => array_values(JtpAddons::all()),
            'print' => $this->printConfig(),
            'require_session' => JtpTools::cfgBool('JTP_REQUIRE_SESSION'),
            'default_customer' => JtpCustomerService::defaultId(),
            'countries' => array_map(function ($c) {
                $out = ['id' => (int) $c['id_country'], 'name' => $c['name'], 'states' => [], 'phones' => JtpCustomerService::phoneFieldsFor((int) $c['id_country'])];
                if (!empty($c['contains_states'])) {
                    foreach (State::getStatesByIdCountry((int) $c['id_country'], true) as $st) {
                        $out['states'][] = ['id' => (int) $st['id_state'], 'name' => $st['name']];
                    }
                }

                return $out;
            }, array_values(Country::getCountries(JtpTools::langId(), true))),
            'default_country' => (int) Configuration::get('PS_COUNTRY_DEFAULT'),
        ];
        if ($this->jtpRegister) {
            $reg = $this->jtpRegister;
            $session = JtpSessions::openFor($reg['id_register']);
            $out['register'] = ['id' => (int) $reg['id_register'], 'name' => $reg['name'], 'series' => $reg['series']];
            $out['session'] = $session ? [
                'id' => (int) $session['id_session'],
                'date_open' => $session['date_open'],
                'employee' => JtpEmployees::name($session['id_employee_open']),
            ] : null;
            $out['payment_methods'] = array_values(JtpRegisters::paymentMethods());
            $out['favorites'] = JtpCatalog::favorites();
            $out['favorite_ids'] = JtpCatalog::favoriteIds();
            $out['categories'] = JtpCatalog::categories(0);
            $out['cart'] = $this->cart()->state();
            $out['parked_count'] = count($this->cart()->parkedList());
        }

        return $out;
    }

    // ================================================================== catálogo

    protected function aSearch(array $d)
    {
        return JtpCatalog::search((string) $this->v($d, 'q', ''), (int) $this->v($d, 'id_category', 0), (int) $this->v($d, 'page', 1));
    }

    protected function aCategories(array $d)
    {
        return JtpCatalog::categories((int) $this->v($d, 'id_category', 0));
    }

    protected function aProduct(array $d)
    {
        $cart = $this->cart()->current();

        return JtpCatalog::product((int) $this->v($d, 'id_product'), (int) $cart->id_customer);
    }

    /**
     * Escaneo: si es un producto/combinación lo añade; si es un número de ticket lo devuelve
     * para que la pantalla abra la devolución.
     */
    protected function aScan(array $d)
    {
        $code = trim((string) $this->v($d, 'code', ''));
        $hit = JtpCatalog::scan($code);
        if ($hit) {
            $product = new Product($hit['id_product']);
            if (!$hit['id_product_attribute'] && $product->hasAttributes()) {
                return ['type' => 'variant', 'product' => JtpCatalog::product($hit['id_product'], (int) $this->cart()->current()->id_customer)];
            }

            return ['type' => 'cart', 'cart' => $this->cart()->add($hit['id_product'], $hit['id_product_attribute'], max(1, (int) $this->v($d, 'qty', 1)))];
        }
        $sale = JtpSaleService::findSale($code);
        if ($sale) {
            return ['type' => 'sale', 'id_sale' => (int) $sale['id_sale'], 'ticket_number' => $sale['ticket_number']];
        }
        $idRule = (int) CartRule::getIdByCode($code);
        if ($idRule) {
            return ['type' => 'cart', 'cart' => $this->cart()->addVoucher($code)];
        }
        // Búsqueda normal como último recurso
        $res = JtpCatalog::search($code);
        if (count($res['products']) === 1 && !$res['products'][0]['has_combinations']) {
            return ['type' => 'cart', 'cart' => $this->cart()->add($res['products'][0]['id_product'], 0, 1)];
        }

        return ['type' => 'search', 'result' => $res];
    }

    protected function aFavoriteToggle(array $d)
    {
        $this->requirePerm('favorites', 'No tienes permiso para gestionar favoritos');
        $on = JtpCatalog::toggleFavorite((int) $this->v($d, 'id_product'));

        return ['favorite' => $on, 'favorites' => JtpCatalog::favorites(), 'favorite_ids' => JtpCatalog::favoriteIds()];
    }

    // ================================================================== carrito

    protected function aCartGet(array $d)
    {
        return $this->cart()->state();
    }

    protected function aCartAdd(array $d)
    {
        return $this->cart()->add((int) $this->v($d, 'id_product'), (int) $this->v($d, 'id_product_attribute', 0), (int) $this->v($d, 'qty', 1));
    }

    protected function aCartQty(array $d)
    {
        return $this->cart()->setQty((int) $this->v($d, 'id_product'), (int) $this->v($d, 'id_product_attribute', 0), (int) $this->v($d, 'qty', 1));
    }

    protected function aCartRemove(array $d)
    {
        return $this->cart()->remove((int) $this->v($d, 'id_product'), (int) $this->v($d, 'id_product_attribute', 0));
    }

    protected function aCartLine(array $d)
    {
        return $this->cart()->setLinePrice((int) $this->v($d, 'id_product'), (int) $this->v($d, 'id_product_attribute', 0), (string) $this->v($d, 'mode', ''), (float) $this->v($d, 'value', 0));
    }

    protected function aCartDiscount(array $d)
    {
        return $this->cart()->setDiscount((string) $this->v($d, 'type', 'percent'), (float) $this->v($d, 'value', 0));
    }

    protected function aCartVoucher(array $d)
    {
        return $this->cart()->addVoucher((string) $this->v($d, 'code', ''));
    }

    protected function aCartVoucherRemove(array $d)
    {
        return $this->cart()->removeVoucher((int) $this->v($d, 'id_cart_rule'));
    }

    protected function aCartCustomer(array $d)
    {
        return $this->cart()->setCustomer((int) $this->v($d, 'id_customer', 0), (int) $this->v($d, 'id_address', 0));
    }

    protected function aCartNote(array $d)
    {
        return $this->cart()->setNote((string) $this->v($d, 'note', ''));
    }

    protected function aCartClear(array $d)
    {
        return $this->cart()->clear();
    }

    protected function aCartPark(array $d)
    {
        return $this->cart()->park((string) $this->v($d, 'label', ''));
    }

    protected function aParkedList(array $d)
    {
        return $this->cart()->parkedList();
    }

    protected function aParkedResume(array $d)
    {
        return $this->cart()->resume((int) $this->v($d, 'id_cart'));
    }

    protected function aParkedDelete(array $d)
    {
        return $this->cart()->deleteParked((int) $this->v($d, 'id_cart'));
    }

    // ================================================================== clientes

    protected function aCustomerSearch(array $d)
    {
        return JtpCustomerService::search((string) $this->v($d, 'q', ''));
    }

    protected function aCustomerCreate(array $d)
    {
        $id = JtpCustomerService::create($d);

        return $this->cart()->setCustomer($id);
    }

    protected function aCustomerGet(array $d)
    {
        $c = JtpCustomerService::get((int) $this->v($d, 'id_customer', 0), (int) $this->v($d, 'id_address', 0));
        if (!$c) {
            throw new JtpException('Cliente no encontrado');
        }

        return $c;
    }

    /**
     * Modificar cliente y una de sus direcciones (id_address; 'new' = añadir otra).
     * El ticket queda con ese cliente y esa dirección.
     */
    protected function aCustomerUpdate(array $d)
    {
        $r = JtpCustomerService::update((int) $this->v($d, 'id_customer', 0), $d);

        return $this->cart()->setCustomer($r['id_customer'], $r['id_address']);
    }

    /** Direcciones de facturación de un cliente */
    protected function aCustomerAddresses(array $d)
    {
        $id = (int) $this->v($d, 'id_customer', 0);
        $cart = $this->cart()->current();

        return JtpCustomerService::addresses($id, (int) $cart->id_customer === $id ? (int) $cart->id_address_invoice : 0);
    }

    /** Cambiar la dirección de facturación del ticket */
    protected function aCartAddress(array $d)
    {
        $cs = $this->cart();
        $cart = $cs->current();

        return $cs->setCustomer((int) $cart->id_customer, (int) $this->v($d, 'id_address', 0));
    }

    // ================================================================== cobro y ventas

    protected function aCheckout(array $d)
    {
        $svc = new JtpSaleService($this->module);
        $payments = $this->v($d, 'payments', []);
        $extra = $this->v($d, 'extra', []);

        return $svc->checkout($this->cart(), $this->jtpRegister, $this->employeeId(), is_array($payments) ? $payments : [], is_array($extra) ? $extra : []);
    }

    protected function aTicket(array $d)
    {
        $type = (string) $this->v($d, 'type', 'sale');
        $id = (int) $this->v($d, 'id', 0);
        switch ($type) {
            case 'return':
                $t = JtpTicket::returnTicket($id);
                break;
            case 'z':
                $t = JtpTicket::z($id);
                break;
            case 'movement':
                $t = JtpTicket::movement($id);
                break;
            default:
                $t = JtpTicket::sale($id);
        }
        if (!$t) {
            throw new JtpException('Ticket no encontrado');
        }

        return $t;
    }

    protected function aTicketEmail(array $d)
    {
        $sale = JtpSaleService::getSale((int) $this->v($d, 'id_sale'));
        if (!$sale) {
            throw new JtpException('Venta no encontrada');
        }
        $email = trim((string) $this->v($d, 'email', ''));
        if (!Validate::isEmail($email)) {
            throw new JtpException('Email no válido');
        }
        $ticket = JtpTicket::sale($sale['id_sale']);
        $ok = Mail::Send(
            JtpTools::langId(),
            'jtp_ticket',
            'Su ticket ' . $ticket['number'],
            ['{ticket_html}' => JtpTicket::toHtml($ticket), '{ticket_txt}' => JtpTicket::toText($ticket), '{ticket_number}' => $ticket['number'], '{shop_name}' => Configuration::get('PS_SHOP_NAME')],
            $email,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'josratpvpresta/mails/',
            false,
            JtpTools::shopId()
        );
        if (!$ok) {
            throw new JtpException('No se pudo enviar el email');
        }
        // Guardar email en el cliente si era un placeholder
        $customer = new Customer((int) $sale['id_customer']);
        if (Validate::isLoadedObject($customer) && !JtpCustomerService::isDefault($customer->id)
            && JtpCustomerService::isPlaceholderEmail($customer->email) && !Customer::customerExists($email)) {
            $customer->email = $email;
            $customer->update();
        }

        return true;
    }

    protected function aSales(array $d)
    {
        return JtpSaleService::listSales($this->employeeId(), [
            'date' => (string) $this->v($d, 'date', ''),
            'date_from' => (string) $this->v($d, 'date_from', ''),
            'date_to' => (string) $this->v($d, 'date_to', ''),
            'q' => (string) $this->v($d, 'q', ''),
            'id_session' => (int) $this->v($d, 'id_session', 0),
        ]);
    }

    protected function aSaleDetail(array $d)
    {
        $info = JtpSaleService::returnable((int) $this->v($d, 'id_sale'));
        $info['returns'] = JtpSaleService::returnsOfSale((int) $this->v($d, 'id_sale'));

        return $info;
    }

    protected function aReturn(array $d)
    {
        $svc = new JtpSaleService($this->module);
        $lines = $this->v($d, 'lines', []);

        return $svc->doReturn(
            (int) $this->v($d, 'id_sale'),
            is_array($lines) ? $lines : [],
            (string) $this->v($d, 'refund', 'cash'),
            $this->jtpRegister,
            $this->employeeId(),
            (string) $this->v($d, 'reason', ''),
            (bool) $this->v($d, 'restock', true)
        );
    }

    // ================================================================== caja

    protected function aSessionStatus(array $d)
    {
        $s = JtpSessions::openFor($this->jtpRegister['id_register']);

        return $s ? JtpSessions::summary($s['id_session']) : null;
    }

    protected function aSessionOpen(array $d)
    {
        $this->requirePerm('close', 'No tienes permiso para abrir la caja');
        $s = JtpSessions::open($this->jtpRegister['id_register'], $this->employeeId(), JtpTools::round($this->v($d, 'amount', 0)));

        return ['session' => ['id' => (int) $s['id_session'], 'date_open' => $s['date_open'], 'employee' => JtpEmployees::name($s['id_employee_open'])]];
    }

    protected function aSessionMove(array $d)
    {
        $this->requirePerm('cash_move', 'No tienes permiso para mover efectivo');
        $s = JtpSessions::openFor($this->jtpRegister['id_register']);
        if (!$s) {
            throw new JtpException('La caja está cerrada');
        }
        $amount = abs(JtpTools::round($this->v($d, 'amount', 0)));
        if ($amount <= 0) {
            throw new JtpException('Importe no válido');
        }
        $type = $this->v($d, 'type') === 'out' ? 'out' : 'in';
        $reason = trim((string) $this->v($d, 'reason', ''));
        if ($reason === '') {
            throw new JtpException('Indica el motivo');
        }
        $id = JtpSessions::addMovement($s['id_session'], $this->employeeId(), $type, $type === 'out' ? -$amount : $amount, $reason);

        return ['ticket' => JtpTicket::movement($id), 'open_drawer' => (bool) $this->jtpRegister['open_drawer']];
    }

    protected function aSessionClose(array $d)
    {
        $this->requirePerm('close', 'No tienes permiso para cerrar la caja');
        $s = JtpSessions::openFor($this->jtpRegister['id_register']);
        if (!$s) {
            throw new JtpException('La caja no está abierta');
        }
        JtpSessions::close($s['id_session'], $this->employeeId(), JtpTools::round($this->v($d, 'counted', 0)), (string) $this->v($d, 'note', ''));

        return ['ticket' => JtpTicket::z($s['id_session'])];
    }

    protected function aSessionHistory(array $d)
    {
        return Db::getInstance()->executeS('SELECT s.id_session, s.date_open, s.date_close, s.status, s.expected_cash, s.counted_cash, s.difference
            FROM ' . JtpTools::t('jtp_session') . ' s WHERE s.id_register = ' . (int) $this->jtpRegister['id_register'] . '
            ORDER BY s.id_session DESC LIMIT 30') ?: [];
    }

    // ================================================================== QZ Tray

    protected function aQzCert(array $d)
    {
        return (string) Configuration::get('JTP_QZ_CERT');
    }

    protected function aQzSign(array $d)
    {
        $key = (string) Configuration::get('JTP_QZ_KEY');
        if (trim($key) === '') {
            throw new JtpException('Sin clave de firma de QZ Tray');
        }
        $pkey = openssl_pkey_get_private($key);
        if (!$pkey) {
            throw new JtpException('La clave privada de QZ Tray no es válida');
        }
        $signature = '';
        openssl_sign((string) $this->v($d, 'request', ''), $signature, $pkey, OPENSSL_ALGO_SHA512);

        return base64_encode($signature);
    }

    // ================================================================== addons

    /**
     * Acción propia de un addon: {"action":"addon","data":{"module":"miaddon","call":"x","params":{...}}}
     * El addon responde en hookActionJtpApi rellenando $params['response'].
     */
    protected function aAddon(array $d)
    {
        $module = (string) $this->v($d, 'module', '');
        if (!Validate::isModuleName($module) || !JtpAddons::isCompatible($module)) {
            throw new JtpException('Addon no disponible: ' . $module);
        }
        $idModule = (int) Module::getModuleIdByName($module);
        $response = null;
        $error = null;
        Hook::exec('actionJtpApi', [
            'call' => (string) $this->v($d, 'call', ''),
            'params' => (array) $this->v($d, 'params', []),
            'id_employee' => $this->employeeId(),
            'register' => $this->jtpRegister,
            'response' => &$response,
            'error' => &$error,
        ], $idModule);
        if ($error) {
            throw new JtpException((string) $error);
        }

        return $response;
    }
}
