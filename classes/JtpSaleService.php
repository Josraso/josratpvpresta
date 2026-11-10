<?php
/**
 * josratpvpresta - cobro (pedido nativo de PrestaShop + registro de venta TPV),
 * devoluciones y listados de ventas.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpSaleService
{
    /** @var Josratpvpresta */
    protected $module;

    public function __construct($module)
    {
        $this->module = $module;
    }

    // ================================================================== COBRO

    /**
     * @param array $payments [['code' => 'cash', 'amount' => 20, 'reference' => ''], ...]
     * @param array $extra    datos de los addons desde la ventana de cobro (p. ej. ['fs_full' => true])
     */
    public function checkout(JtpCartService $cs, array $register, $idEmployee, array $payments, array $extra = [])
    {
        $cart = $cs->current();
        if (!$cart->getProducts()) {
            throw new JtpException('El ticket está vacío');
        }
        $session = JtpSessions::openFor($register['id_register']);
        if (!$session && JtpTools::cfgBool('JTP_REQUIRE_SESSION')) {
            throw new JtpException('La caja está cerrada. Ábrela antes de cobrar.', ['session_closed' => true]);
        }
        // Validar vales antes de validateOrder (evita redirecciones internas de PrestaShop)
        CartRule::cleanCache();
        foreach ($cart->getCartRules() as $cr) {
            $rule = new CartRule((int) $cr['id_cart_rule']);
            $err = $rule->checkValidity(Context::getContext(), true, true);
            if ($err) {
                $cart->removeCartRule((int) $rule->id);
                throw new JtpException('El vale "' . $cr['name'] . '" ya no es válido y se ha quitado: ' . (is_string($err) ? $err : ''));
            }
        }
        $total = JtpTools::round($cart->getOrderTotal(true, Cart::BOTH));
        $methods = JtpRegisters::paymentMethods();

        // Normalizar pagos
        $lines = [];
        foreach ($payments as $p) {
            $code = isset($p['code']) ? (string) $p['code'] : '';
            $amount = JtpTools::round(isset($p['amount']) ? $p['amount'] : 0);
            if ($amount <= 0) {
                continue;
            }
            if (!isset($methods[$code])) {
                throw new JtpException('Método de pago no válido: ' . $code);
            }
            $lines[] = [
                'code' => $code,
                'name' => $methods[$code]['name'],
                'amount' => $amount,
                'is_cash' => $methods[$code]['is_cash'],
                'allow_change' => $methods[$code]['allow_change'],
                'reference' => Tools::substr(isset($p['reference']) ? (string) $p['reference'] : '', 0, 128),
            ];
        }
        $received = JtpTools::round(array_sum(array_column($lines, 'amount')));
        if ($total > 0 && $received + 0.004 < $total) {
            throw new JtpException(sprintf('Faltan %s por cobrar', JtpTools::money($total - $received)));
        }
        $change = JtpTools::round(max(0, $received - $total));
        if ($change > 0) {
            // El cambio sale del último método que admite cambio (normalmente efectivo)
            $left = $change;
            for ($i = count($lines) - 1; $i >= 0 && $left > 0; --$i) {
                if (!$lines[$i]['allow_change']) {
                    continue;
                }
                $take = min($left, $lines[$i]['amount']);
                $lines[$i]['amount'] = JtpTools::round($lines[$i]['amount'] - $take);
                $left = JtpTools::round($left - $take);
            }
            if ($left > 0) {
                throw new JtpException('El importe entregado supera el total y el método elegido no admite cambio');
            }
            $lines = array_values(array_filter($lines, function ($l) {
                return $l['amount'] > 0;
            }));
        }
        $errors = [];
        Hook::exec('actionJtpCheckoutValidate', [
            'cart' => $cart, 'register' => $register, 'id_employee' => (int) $idEmployee,
            'payments' => &$lines, 'total' => $total, 'errors' => &$errors, 'extra' => $extra,
        ]);
        if ($errors) {
            throw new JtpException(implode("\n", $errors));
        }
        $label = $lines ? implode(' + ', array_unique(array_column($lines, 'name'))) : 'TPV';
        $customer = new Customer((int) $cart->id_customer);
        $meta = $cs->meta($cart->id);

        JtpTools::$checkout = [
            'id_cart' => (int) $cart->id,
            'register' => $register,
            'id_session' => $session ? (int) $session['id_session'] : 0,
            'id_employee' => (int) $idEmployee,
            'payments' => $lines,
            'received' => $received,
            'change' => $change,
            'note' => $meta ? (string) $meta['note'] : '',
            'extra' => $extra,
            'id_sale' => 0,
        ];
        JtpTools::$blockMails = true;
        try {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) JtpStates::sale(),
                $total,
                Tools::substr($label, 0, 255),
                null,
                [],
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );
        } catch (Exception $e) {
            JtpTools::$blockMails = false;
            JtpTools::$checkout = null;
            throw new JtpException('No se pudo crear el pedido: ' . $e->getMessage());
        }
        JtpTools::$blockMails = false;
        $idOrder = (int) $this->module->currentOrder;
        $idSale = (int) JtpTools::$checkout['id_sale'];
        if (!$idSale) {
            // Por si otro módulo interrumpió el hook: registrar aquí
            $idSale = $this->recordSale(new Order($idOrder));
        }
        JtpTools::$checkout = null;

        // Limpieza del carrito del TPV
        Db::getInstance()->delete('specific_price', 'id_cart = ' . (int) $cart->id);
        Db::getInstance()->update('jtp_cart', ['status' => 'done', 'date_upd' => JtpTools::now()], 'id_cart = ' . (int) $cart->id);
        $sale = self::getSale($idSale);
        Hook::exec('actionJtpSaleCompleted', ['sale' => $sale, 'order' => new Order($idOrder), 'payments' => $lines, 'extra' => $extra]);

        $newCart = $cs->createCart(JtpCustomerService::defaultId());
        $hasCash = (bool) array_filter($lines, function ($l) {
            return $l['is_cash'];
        });

        return [
            'sale' => $sale,
            'ticket' => JtpTicket::sale($idSale),
            'open_drawer' => $hasCash && (int) $register['open_drawer'],
            'cart' => $cs->state($newCart),
        ];
    }

    /**
     * Crea el registro de venta TPV. Se llama desde hookActionValidateOrder (dentro de
     * validateOrder, con el pedido ya creado) para que el número sea correlativo sin huecos.
     */
    public function recordSale(Order $order)
    {
        $co = JtpTools::$checkout;
        if (!$co) {
            return 0;
        }
        $existing = (int) Db::getInstance()->getValue('SELECT id_sale FROM ' . JtpTools::t('jtp_sale') . ' WHERE id_cart = ' . (int) $order->id_cart);
        if ($existing) {
            return $existing;
        }
        $register = $co['register'];
        $num = JtpCounter::next($register['series']);
        $ticket = $num['formatted'];
        Hook::exec('actionJtpTicketNumber', ['number' => &$ticket, 'counter' => $num, 'register' => $register, 'order' => $order]);
        $customer = new Customer((int) $order->id_customer);
        $address = new Address((int) $order->id_address_invoice);
        $hasNif = Validate::isLoadedObject($address) && (JtpCustomerService::nif($address->dni) !== '' || JtpCustomerService::nif($address->vat_number) !== '');
        $docType = (!JtpCustomerService::isDefault($customer->id) && $hasNif) ? 'full' : 'simplified';
        Hook::exec('actionJtpDocumentType', ['type' => &$docType, 'order' => $order, 'customer' => $customer, 'address' => $address]);

        $db = Db::getInstance();
        $refMode = JtpTools::posRefMode();
        if ($refMode === 'ticket') {
            JtpTools::setOrderReference($order, $ticket);
        } elseif ($refMode === 'id') {
            JtpTools::setOrderReference($order, (string) JtpTools::cartFirstOrderId($order));
        }
        $db->insert('jtp_sale', [
            'id_order' => (int) $order->id,
            'id_cart' => (int) $order->id_cart,
            'id_shop' => (int) $order->id_shop,
            'id_register' => (int) $register['id_register'],
            'id_session' => (int) $co['id_session'],
            'id_employee' => (int) $co['id_employee'],
            'id_customer' => (int) $order->id_customer,
            'ticket_number' => pSQL($ticket),
            'series' => pSQL($num['series']),
            'year' => (int) $num['year'],
            'number' => (int) $num['number'],
            'doc_type' => pSQL($docType),
            'total' => (float) $order->total_paid_tax_incl,
            'received' => (float) $co['received'],
            'change_given' => (float) $co['change'],
            'note' => pSQL($co['note']),
            'date_add' => JtpTools::now(),
        ]);
        $idSale = (int) $db->Insert_ID();
        $cash = 0;
        foreach ($co['payments'] as $p) {
            $db->insert('jtp_sale_payment', [
                'id_sale' => $idSale,
                'code' => pSQL($p['code']),
                'name' => pSQL($p['name']),
                'amount' => (float) $p['amount'],
                'is_cash' => (int) $p['is_cash'],
                'reference' => pSQL($p['reference']),
            ]);
            if ($p['is_cash']) {
                $cash += (float) $p['amount'];
            }
        }
        if ($co['id_session'] && $cash != 0) {
            JtpSessions::addMovement($co['id_session'], $co['id_employee'], 'sale', JtpTools::round($cash), $ticket, $idSale);
        }
        JtpTools::$checkout['id_sale'] = $idSale;

        return $idSale;
    }

    public static function getSale($idSale)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_sale') . ' WHERE id_sale = ' . (int) $idSale);
        if (!$row) {
            return null;
        }
        $row['payments'] = Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('jtp_sale_payment') . ' WHERE id_sale = ' . (int) $idSale) ?: [];

        return $row;
    }

    public static function findSale($numberOrId)
    {
        $v = trim((string) $numberOrId);
        $db = Db::getInstance();
        $id = (int) $db->getValue('SELECT id_sale FROM ' . JtpTools::t('jtp_sale') . ' WHERE ticket_number = \'' . pSQL($v) . '\' AND id_shop = ' . JtpTools::shopId());
        if (!$id && ctype_digit($v)) {
            $id = (int) $db->getValue('SELECT id_sale FROM ' . JtpTools::t('jtp_sale') . ' WHERE id_order = ' . (int) $v . ' AND id_shop = ' . JtpTools::shopId());
        }

        return $id ? self::getSale($id) : null;
    }

    public static function getByOrder($idOrder)
    {
        $id = (int) Db::getInstance()->getValue('SELECT id_sale FROM ' . JtpTools::t('jtp_sale') . ' WHERE id_order = ' . (int) $idOrder);

        return $id ? self::getSale($id) : null;
    }

    /**
     * Listado de ventas (y devoluciones) para la pantalla del TPV.
     */
    public static function listSales($idEmployee, $filters = [])
    {
        $db = Db::getInstance();
        $where = ['s.id_shop = ' . JtpTools::shopId()];
        if (!JtpEmployees::can($idEmployee, 'all_sales')) {
            $where[] = 's.id_employee = ' . (int) $idEmployee;
        }
        $from = !empty($filters['date_from']) && Validate::isDate($filters['date_from']) ? $filters['date_from'] : '';
        $to = !empty($filters['date_to']) && Validate::isDate($filters['date_to']) ? $filters['date_to'] : '';
        if (!$from && !$to && !empty($filters['date']) && Validate::isDate($filters['date'])) {
            $from = $to = $filters['date'];
        }
        $q = trim((string) (isset($filters['q']) ? $filters['q'] : ''));
        if (!empty($filters['id_session'])) {
            $where[] = 's.id_session = ' . (int) $filters['id_session'];
        } elseif (!$from && !$to && $q === '') {
            // Sin búsqueda ni fechas: las de hoy
            $from = $to = date('Y-m-d');
        }
        // Con texto de búsqueda y sin fechas se busca en todas las fechas
        if ($from) {
            $where[] = 's.date_add >= \'' . pSQL($from) . ' 00:00:00\'';
        }
        if ($to) {
            $where[] = 's.date_add <= \'' . pSQL($to) . ' 23:59:59\'';
        }
        if ($q !== '') {
            $like = pSQL(str_replace(['%', '_'], ['\\%', '\\_'], $q));
            $where[] = '(s.ticket_number LIKE \'%' . $like . '%\' OR o.reference LIKE \'%' . $like . '%\' OR CONCAT(c.firstname, \' \', c.lastname) LIKE \'%' . $like . '%\'
                OR c.email LIKE \'%' . $like . '%\' OR c.company LIKE \'%' . $like . '%\'
                OR EXISTS (SELECT 1 FROM ' . JtpTools::t('address') . ' a WHERE a.id_customer = s.id_customer AND (a.dni LIKE \'%' . $like . '%\' OR a.vat_number LIKE \'%' . $like . '%\' OR a.phone LIKE \'%' . $like . '%\' OR a.phone_mobile LIKE \'%' . $like . '%\')))';
        }
        $rows = $db->executeS('SELECT s.id_sale, s.id_order, s.ticket_number, s.total, s.returned_total, s.date_add, s.doc_type,
                CONCAT(e.firstname, \' \', e.lastname) employee, s.id_customer, CONCAT(c.firstname, \' \', c.lastname) customer,
                r.name register,
                (SELECT GROUP_CONCAT(p.name SEPARATOR \' + \') FROM ' . JtpTools::t('jtp_sale_payment') . ' p WHERE p.id_sale = s.id_sale) payment
            FROM ' . JtpTools::t('jtp_sale') . ' s
            LEFT JOIN ' . JtpTools::t('employee') . ' e ON e.id_employee = s.id_employee
            LEFT JOIN ' . JtpTools::t('customer') . ' c ON c.id_customer = s.id_customer
            LEFT JOIN ' . JtpTools::t('orders') . ' o ON o.id_order = s.id_order
            LEFT JOIN ' . JtpTools::t('jtp_register') . ' r ON r.id_register = s.id_register
            WHERE ' . implode(' AND ', $where) . ' ORDER BY s.id_sale DESC LIMIT 300') ?: [];
        foreach ($rows as &$r) {
            if (JtpCustomerService::isDefault($r['id_customer'])) {
                $r['customer'] = '';
            }
            $r['total'] = JtpTools::round($r['total']);
            $r['returned_total'] = JtpTools::round($r['returned_total']);
        }
        unset($r);

        return $rows;
    }

    // ================================================================== DEVOLUCIONES

    /**
     * Líneas de una venta con cantidades ya devueltas y precio de reembolso por unidad
     * (el precio pagado, prorrateando los descuentos globales del pedido).
     */
    public static function returnable($idSale)
    {
        $sale = self::getSale($idSale);
        if (!$sale) {
            throw new JtpException('Venta no encontrada');
        }
        $order = new Order((int) $sale['id_order']);
        $db = Db::getInstance();
        $details = $db->executeS('SELECT od.*, IFNULL((SELECT SUM(rl.quantity) FROM ' . JtpTools::t('jtp_return_line') . ' rl
                WHERE rl.id_order_detail = od.id_order_detail), 0) returned
            FROM ' . JtpTools::t('order_detail') . ' od WHERE od.id_order = ' . (int) $order->id . ' ORDER BY od.id_order_detail') ?: [];
        $productsTi = (float) $order->total_products_wt;
        $paidProducts = (float) $order->total_paid_tax_incl - (float) $order->total_shipping_tax_incl - (float) $order->total_wrapping_tax_incl;
        $factor = $productsTi > 0 ? max(0, min(1, $paidProducts / $productsTi)) : 1;
        $lines = [];
        foreach ($details as $d) {
            $unit = JtpTools::round((float) $d['unit_price_tax_incl'] * $factor);
            $lines[] = [
                'id_order_detail' => (int) $d['id_order_detail'],
                'id_product' => (int) $d['product_id'],
                'id_product_attribute' => (int) $d['product_attribute_id'],
                'name' => $d['product_name'],
                'reference' => $d['product_reference'],
                'quantity' => (int) $d['product_quantity'],
                'returned' => (int) $d['returned'],
                'returnable' => max(0, (int) $d['product_quantity'] - (int) $d['returned']),
                'unit_refund' => $unit,
            ];
        }

        return [
            'sale' => $sale,
            'customer' => JtpCustomerService::isDefault($sale['id_customer']) ? null : JtpCustomerService::get($sale['id_customer']),
            'lines' => $lines,
        ];
    }

    /**
     * @param array $lines [['id_order_detail' => , 'quantity' => ], ...]
     * @param string $refundCode código de método de pago o 'voucher'
     */
    public function doReturn($idSale, array $lines, $refundCode, array $register, $idEmployee, $reason = '', $restock = true)
    {
        if (!JtpEmployees::can($idEmployee, 'return')) {
            throw new JtpException('No tienes permiso para hacer devoluciones');
        }
        $session = JtpSessions::openFor($register['id_register']);
        if (!$session && JtpTools::cfgBool('JTP_REQUIRE_SESSION')) {
            throw new JtpException('La caja está cerrada. Ábrela antes de devolver.', ['session_closed' => true]);
        }
        $info = self::returnable($idSale);
        $sale = $info['sale'];
        $byDetail = [];
        foreach ($info['lines'] as $l) {
            $byDetail[$l['id_order_detail']] = $l;
        }
        $toReturn = [];
        $total = 0;
        foreach ($lines as $l) {
            $idd = isset($l['id_order_detail']) ? (int) $l['id_order_detail'] : 0;
            $qty = isset($l['quantity']) ? (int) $l['quantity'] : 0;
            if ($qty <= 0) {
                continue;
            }
            if (!isset($byDetail[$idd])) {
                throw new JtpException('Línea no válida');
            }
            if ($qty > $byDetail[$idd]['returnable']) {
                throw new JtpException('No se pueden devolver más unidades de las vendidas: ' . $byDetail[$idd]['name']);
            }
            $lineTotal = JtpTools::round($byDetail[$idd]['unit_refund'] * $qty);
            $toReturn[] = $byDetail[$idd] + ['qty' => $qty, 'line_total' => $lineTotal];
            $total += $lineTotal;
        }
        $total = JtpTools::round($total);
        if (!$toReturn) {
            throw new JtpException('Selecciona al menos una unidad a devolver');
        }
        if ($refundCode === 'voucher') {
            $refund = ['code' => 'voucher', 'name' => 'Vale', 'is_cash' => false];
        } else {
            $methods = JtpRegisters::paymentMethods();
            if (!isset($methods[$refundCode])) {
                throw new JtpException('Método de reembolso no válido');
            }
            $refund = $methods[$refundCode];
        }
        $db = Db::getInstance();
        $num = JtpCounter::next($register['return_series']);
        $ticket = $num['formatted'];
        Hook::exec('actionJtpTicketNumber', ['number' => &$ticket, 'counter' => $num, 'register' => $register, 'return' => true]);

        $idRule = 0;
        $voucherCode = '';
        if ($refundCode === 'voucher') {
            $rule = new CartRule();
            $names = [];
            foreach (Language::getLanguages(false) as $lng) {
                $names[(int) $lng['id_lang']] = 'Vale devolución ' . $ticket;
            }
            $rule->name = $names;
            $rule->code = 'VALE' . Tools::strtoupper(Tools::passwdGen(8, 'NO_NUMERIC'));
            $rule->id_customer = JtpCustomerService::isDefault($sale['id_customer']) ? 0 : (int) $sale['id_customer'];
            $rule->date_from = date('Y-m-d H:i:s', time() - 60);
            $rule->date_to = date('Y-m-d H:i:s', time() + 86400 * max(1, (int) JtpTools::cfg('JTP_VOUCHER_DAYS', 365)));
            $rule->quantity = 1;
            $rule->quantity_per_user = 1;
            $rule->partial_use = 1;
            $rule->reduction_amount = $total;
            $rule->reduction_tax = 1;
            $rule->reduction_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
            $rule->minimum_amount_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
            $rule->highlight = 0;
            $rule->active = 1;
            $rule->description = 'Vale por devolución del ticket ' . $sale['ticket_number'];
            if (!$rule->add()) {
                throw new JtpException('No se pudo crear el vale');
            }
            $idRule = (int) $rule->id;
            $voucherCode = $rule->code;
        }

        $db->insert('jtp_return', [
            'id_sale' => (int) $sale['id_sale'],
            'id_order' => (int) $sale['id_order'],
            'id_shop' => (int) $sale['id_shop'],
            'id_register' => (int) $register['id_register'],
            'id_session' => $session ? (int) $session['id_session'] : 0,
            'id_employee' => (int) $idEmployee,
            'ticket_number' => pSQL($ticket),
            'series' => pSQL($num['series']),
            'year' => (int) $num['year'],
            'number' => (int) $num['number'],
            'total' => $total,
            'refund_code' => pSQL($refund['code']),
            'refund_name' => pSQL($refund['name']),
            'refund_is_cash' => (int) $refund['is_cash'],
            'id_cart_rule' => $idRule,
            'voucher_code' => pSQL($voucherCode),
            'restock' => (int) (bool) $restock,
            'reason' => pSQL(Tools::substr((string) $reason, 0, 255)),
            'date_add' => JtpTools::now(),
        ]);
        $idReturn = (int) $db->Insert_ID();
        $order = new Order((int) $sale['id_order']);
        foreach ($toReturn as $l) {
            $db->insert('jtp_return_line', [
                'id_return' => $idReturn,
                'id_order_detail' => (int) $l['id_order_detail'],
                'id_product' => (int) $l['id_product'],
                'id_product_attribute' => (int) $l['id_product_attribute'],
                'name' => pSQL($l['name']),
                'quantity' => (int) $l['qty'],
                'unit_price' => (float) $l['unit_refund'],
                'total' => (float) $l['line_total'],
            ]);
            $set = 'product_quantity_refunded = product_quantity_refunded + ' . (int) $l['qty'];
            if ($restock) {
                $set .= ', product_quantity_reinjected = product_quantity_reinjected + ' . (int) $l['qty'];
                StockAvailable::updateQuantity((int) $l['id_product'], (int) $l['id_product_attribute'], (int) $l['qty'], (int) $order->id_shop);
            }
            $db->execute('UPDATE ' . JtpTools::t('order_detail') . ' SET ' . $set . ' WHERE id_order_detail = ' . (int) $l['id_order_detail']);
        }
        $db->execute('UPDATE ' . JtpTools::t('jtp_sale') . ' SET returned_total = returned_total + ' . (float) $total . ' WHERE id_sale = ' . (int) $sale['id_sale']);
        if ($refund['is_cash'] && $session) {
            JtpSessions::addMovement($session['id_session'], $idEmployee, 'refund', -$total, $ticket, (int) $sale['id_sale'], $idReturn);
        }
        // Devolución total: estado "Devuelto en TPV" (no descuenta ni repone stock)
        $pending = (int) $db->getValue('SELECT SUM(product_quantity - product_quantity_refunded) FROM ' . JtpTools::t('order_detail') . ' WHERE id_order = ' . (int) $order->id);
        $idReturned = $pending <= 0 ? (int) JtpStates::returned() : 0;
        if ($idReturned) {
            JtpTools::$blockMails = true;
            $h = new OrderHistory();
            $h->id_order = (int) $order->id;
            $h->id_employee = (int) $idEmployee;
            $h->changeIdOrderState($idReturned, $order, true);
            $h->add();
            JtpTools::$blockMails = false;
        }
        $return = self::getReturn($idReturn);
        Hook::exec('actionJtpReturnCompleted', ['return' => $return, 'sale' => $sale, 'order' => $order]);

        return [
            'return' => $return,
            'ticket' => JtpTicket::returnTicket($idReturn),
            'open_drawer' => $refund['is_cash'] && (int) $register['open_drawer'],
        ];
    }

    public static function getReturn($idReturn)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_return') . ' WHERE id_return = ' . (int) $idReturn);
        if (!$row) {
            return null;
        }
        $row['lines'] = Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('jtp_return_line') . ' WHERE id_return = ' . (int) $idReturn) ?: [];

        return $row;
    }

    public static function returnsOfSale($idSale)
    {
        return Db::getInstance()->executeS('SELECT id_return, ticket_number, total, refund_name, date_add FROM ' . JtpTools::t('jtp_return') . '
            WHERE id_sale = ' . (int) $idSale . ' ORDER BY id_return') ?: [];
    }
}
