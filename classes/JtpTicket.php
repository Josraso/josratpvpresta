<?php
/**
 * josratpvpresta - datos estructurados de los tickets (venta, devolución, cierre Z).
 *
 * El servidor genera un JSON neutro y la pantalla lo pinta en HTML (navegador / email)
 * o en ESC/POS (QZ Tray). Los addons añaden bloques con actionJtpTicketBlocks y
 * pueden modificar el ticket completo (recibe 'ticket' por referencia).
 *
 * Bloque: ['type' => 'text'|'title'|'qr'|'barcode'|'image'|'separator'|'pair', 'value' => '...',
 *          'label' => '...', 'align' => 'left'|'center'|'right', 'bold' => bool, 'position' => 'top'|'bottom']
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpTicket
{
    public static function shop()
    {
        $lines = [];
        foreach (['PS_SHOP_ADDR1', 'PS_SHOP_ADDR2'] as $k) {
            if ($v = trim((string) Configuration::get($k))) {
                $lines[] = $v;
            }
        }
        $city = trim(Configuration::get('PS_SHOP_CODE') . ' ' . Configuration::get('PS_SHOP_CITY'));
        if ($city !== '') {
            $lines[] = $city;
        }
        if ($v = trim((string) Configuration::get('PS_SHOP_PHONE'))) {
            $lines[] = 'Tel. ' . $v;
        }

        return [
            'name' => (string) Configuration::get('PS_SHOP_NAME'),
            'lines' => $lines,
            'header' => (string) Configuration::get('JTP_TICKET_HEADER'),
            'footer' => (string) Configuration::get('JTP_TICKET_FOOTER'),
            'logo' => JtpTools::cfgBool('JTP_TICKET_SHOW_LOGO') ? JtpTools::shopLogoUrl() : '',
        ];
    }

    public static function options()
    {
        return [
            'show_employee' => JtpTools::cfgBool('JTP_TICKET_SHOW_EMPLOYEE'),
            'show_customer' => JtpTools::cfgBool('JTP_TICKET_SHOW_CUSTOMER'),
            'show_reference' => JtpTools::cfgBool('JTP_TICKET_SHOW_REF'),
            'show_taxes' => JtpTools::cfgBool('JTP_TICKET_SHOW_TAXES'),
            'barcode' => JtpTools::cfgBool('JTP_TICKET_BARCODE'),
        ];
    }

    protected static function customerBlock($idCustomer, $idAddress = 0)
    {
        if (JtpCustomerService::isDefault($idCustomer) || !JtpTools::cfgBool('JTP_TICKET_SHOW_CUSTOMER')) {
            return null;
        }
        $c = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($c)) {
            return null;
        }
        $a = $idAddress ? new Address((int) $idAddress) : JtpCustomerService::invoiceAddress($c->id);
        $out = [
            'name' => trim(($c->company ? $c->company . ' - ' : '') . $c->firstname . ' ' . $c->lastname),
            'nif' => '',
            'lines' => [],
        ];
        if ($a && Validate::isLoadedObject($a)) {
            $out['nif'] = JtpCustomerService::nif($a->vat_number) ?: JtpCustomerService::nif($a->dni);
            if ($a->alias !== 'TPV') {
                $out['lines'][] = trim($a->address1 . ' ' . $a->address2);
                $out['lines'][] = trim($a->postcode . ' ' . $a->city);
            }
        }

        return $out;
    }

    /**
     * Desglose de IVA a partir de las líneas del pedido, prorrateando los descuentos globales.
     */
    protected static function taxes(Order $order)
    {
        // Tipo real del impuesto (order_detail_tax -> tax); no se deduce del redondeo.
        // Con recargo de equivalencia la línea lleva dos impuestos: el mayor es el IVA y el resto el RE.
        $rows = Db::getInstance()->executeS('SELECT od.total_price_tax_excl te, od.total_price_tax_incl ti, od.tax_rate od_rate,
                od.ecotax, od.ecotax_tax_rate, od.product_quantity,
                (SELECT MAX(t.rate) FROM ' . JtpTools::t('order_detail_tax') . ' odt
                    INNER JOIN ' . JtpTools::t('tax') . ' t ON t.id_tax = odt.id_tax
                    WHERE odt.id_order_detail = od.id_order_detail) tax_rate,
                (SELECT SUM(t.rate) FROM ' . JtpTools::t('order_detail_tax') . ' odt
                    INNER JOIN ' . JtpTools::t('tax') . ' t ON t.id_tax = odt.id_tax
                    WHERE odt.id_order_detail = od.id_order_detail) tax_sum
            FROM ' . JtpTools::t('order_detail') . ' od WHERE od.id_order = ' . (int) $order->id) ?: [];
        $groups = [];
        foreach ($rows as $r) {
            $te = (float) $r['te'];
            $ti = (float) $r['ti'];
            $re = 0.0;
            if ($r['tax_rate'] !== null && $r['tax_rate'] !== '') {
                $rate = round((float) $r['tax_rate'], 2);
                $re = round((float) $r['tax_sum'] - (float) $r['tax_rate'], 2);
            } elseif ((float) $r['od_rate'] > 0) {
                $rate = round((float) $r['od_rate'], 2);
            } else {
                $rate = $te > 0 ? round(($ti / $te - 1) * 100) : 0.0;
            }
            // Ecotasa con un tipo distinto al del producto: va a su propio grupo
            $ecoTe = (float) $r['ecotax'] * (int) $r['product_quantity'];
            $ecoRate = round((float) $r['ecotax_tax_rate'], 2);
            if ($ecoTe > 0 && abs($ecoRate - ($rate + $re)) > 0.001) {
                $eRe = $re > 0 && $ecoRate > $re ? $re : 0.0;
                $eIva = round($ecoRate - $eRe, 2);
                $ek = $eIva . '|' . $eRe;
                if (!isset($groups[$ek])) {
                    $groups[$ek] = ['rate' => $eIva, 're_rate' => $eRe, 'base' => 0, 'tax' => 0];
                }
                $groups[$ek]['base'] += $ecoTe;
                $te -= $ecoTe;
            }
            $k = $rate . '|' . $re;
            if (!isset($groups[$k])) {
                $groups[$k] = ['rate' => $rate, 're_rate' => $re, 'base' => 0, 'tax' => 0];
            }
            $groups[$k]['base'] += $te;
            $groups[$k]['tax'] += $ti - $te;
        }
        $productsTe = (float) $order->total_products;
        $factor = $productsTe > 0 ? ((float) $order->total_paid_tax_excl - (float) $order->total_shipping_tax_excl) / $productsTe : 1;
        $out = [];
        foreach ($groups as $g) {
            $base = JtpTools::round($g['base'] * $factor);
            $row = [
                'rate' => $g['rate'],
                'base' => $base,
                'tax' => JtpTools::round($base * $g['rate'] / 100),
            ];
            if ($g['re_rate'] > 0) {
                $row['re_rate'] = $g['re_rate'];
                $row['re'] = JtpTools::round($base * $g['re_rate'] / 100);
            }
            $out[] = $row;
        }
        // Ajuste de redondeo para que base + cuota (+ recargo) = total
        if ($out) {
            $sum = 0;
            foreach ($out as $o) {
                $sum += $o['base'] + $o['tax'] + (isset($o['re']) ? $o['re'] : 0);
            }
            $diff = JtpTools::round((float) $order->total_paid_tax_incl - (float) $order->total_shipping_tax_incl - $sum);
            if ($diff != 0) {
                $out[count($out) - 1]['tax'] = JtpTools::round($out[count($out) - 1]['tax'] + $diff);
            }
        }
        usort($out, function ($a, $b) {
            return $a['rate'] < $b['rate'] ? -1 : 1;
        });

        return $out;
    }

    /**
     * Línea de producto y, si lleva ecotasa, la ecotasa en su propia línea justo debajo (como en la factura
     * de FacturaScripts): el producto queda con su precio sin la ecotasa y el total de las dos es el mismo.
     */
    protected static function pushWithEcotax(array &$lines, array $line, $ecoTi)
    {
        if ($ecoTi <= 0) {
            $lines[] = $line;

            return;
        }
        $qty = (int) $line['quantity'];
        $ecoTotal = JtpTools::round($ecoTi * $qty);
        $line['unit_price'] = JtpTools::round($line['unit_price'] - $ecoTi);
        if ($line['original_price'] !== null) {
            $line['original_price'] = JtpTools::round($line['original_price'] - $ecoTi);
        }
        $line['total'] = JtpTools::round($line['total'] - $ecoTotal);
        $lines[] = $line;
        $lines[] = [
            'name' => JtpTools::ecotaxLabel($line['id_product'], $line['id_product_attribute'], $line['id_order_detail']),
            'reference' => '',
            'quantity' => $qty,
            'unit_price' => $ecoTi,
            'original_price' => null,
            'total' => $ecoTotal,
            'id_order_detail' => $line['id_order_detail'],
            'id_product' => $line['id_product'],
            'id_product_attribute' => $line['id_product_attribute'],
            'notes' => [],
            // Línea de ecotasa: se imprime sangrada, debajo de su producto
            'sub' => true,
            'kind' => 'ecotax',
        ];
    }

    public static function sale($idSale)
    {
        $sale = JtpSaleService::getSale($idSale);
        if (!$sale) {
            return null;
        }
        $order = new Order((int) $sale['id_order']);
        $register = JtpRegisters::get($sale['id_register']);
        $lines = [];
        $details = Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('order_detail') . ' WHERE id_order = ' . (int) $order->id . ' ORDER BY id_order_detail') ?: [];
        foreach ($details as $d) {
            $unitTi = (float) $d['unit_price_tax_incl'];
            $unitTe = (float) $d['unit_price_tax_excl'];
            $ratio = $unitTe > 0 ? $unitTi / $unitTe : 1;
            $orig = JtpTools::round((float) $d['original_product_price'] * $ratio);
            $ecoTi = (float) $d['ecotax'] > 0 ? JtpTools::round((float) $d['ecotax'] * (1 + (float) $d['ecotax_tax_rate'] / 100)) : 0.0;
            $line = [
                'name' => $d['product_name'],
                'reference' => $d['product_reference'],
                'ean13' => $d['product_ean13'],
                'quantity' => (int) $d['product_quantity'],
                'unit_price' => JtpTools::round($unitTi),
                'original_price' => $orig > JtpTools::round($unitTi) + 0.009 ? $orig : null,
                'total' => JtpTools::round($d['total_price_tax_incl']),
                'id_order_detail' => (int) $d['id_order_detail'],
                'id_product' => (int) $d['product_id'],
                'id_product_attribute' => (int) $d['product_attribute_id'],
                'notes' => [],
            ];
            self::pushWithEcotax($lines, $line, $ecoTi);
        }
        // Los addons pueden cambiar o añadir notas a las líneas (p. ej. el nombre de la ecotasa)
        Hook::exec('actionJtpTicketLines', ['order' => $order, 'lines' => &$lines, 'type' => 'sale']);
        $discounts = [];
        foreach ($order->getCartRules() as $cr) {
            $discounts[] = ['name' => $cr['name'], 'value' => JtpTools::round($cr['value'])];
        }
        $payments = [];
        foreach ($sale['payments'] as $p) {
            $payments[] = ['name' => $p['name'], 'amount' => JtpTools::round($p['amount']), 'reference' => $p['reference']];
        }
        $ticket = [
            'type' => 'sale',
            'title' => 'TICKET',
            'doc_type' => $sale['doc_type'],
            'number' => $sale['ticket_number'],
            'date' => $sale['date_add'],
            'register' => $register ? $register['name'] : '',
            'employee' => JtpEmployees::name($sale['id_employee']),
            'id_order' => (int) $order->id,
            'shop' => self::shop(),
            'options' => self::options(),
            'customer' => self::customerBlock($sale['id_customer'], $order->id_address_invoice),
            'lines' => $lines,
            'discounts' => $discounts,
            'totals' => [
                'products' => JtpTools::round($order->total_products_wt),
                'discounts' => JtpTools::round($order->total_discounts_tax_incl),
                'total' => JtpTools::round($order->total_paid_tax_incl),
                'tax_excl' => JtpTools::round($order->total_paid_tax_excl),
            ],
            'taxes' => self::taxes($order),
            'payments' => $payments,
            'received' => JtpTools::round($sale['received']),
            'change' => JtpTools::round($sale['change_given']),
            'note' => (string) $sale['note'],
            'blocks' => [],
        ];
        self::addonBlocks($ticket, ['sale' => $sale, 'order' => $order]);

        return $ticket;
    }

    public static function returnTicket($idReturn)
    {
        $ret = JtpSaleService::getReturn($idReturn);
        if (!$ret) {
            return null;
        }
        $sale = JtpSaleService::getSale($ret['id_sale']);
        $register = JtpRegisters::get($ret['id_register']);
        $lines = [];
        foreach ($ret['lines'] as $l) {
            $od = Db::getInstance()->getRow('SELECT product_id, product_attribute_id, ecotax, ecotax_tax_rate FROM ' . JtpTools::t('order_detail')
                . ' WHERE id_order_detail = ' . (int) $l['id_order_detail']);
            $ecoTi = $od && (float) $od['ecotax'] > 0 ? JtpTools::round((float) $od['ecotax'] * (1 + (float) $od['ecotax_tax_rate'] / 100)) : 0.0;
            self::pushWithEcotax($lines, [
                'name' => $l['name'],
                'reference' => '',
                'quantity' => -(int) $l['quantity'],
                'unit_price' => JtpTools::round($l['unit_price']),
                'original_price' => null,
                'total' => -JtpTools::round($l['total']),
                'id_order_detail' => (int) $l['id_order_detail'],
                'id_product' => $od ? (int) $od['product_id'] : 0,
                'id_product_attribute' => $od ? (int) $od['product_attribute_id'] : 0,
                'notes' => [],
            ], $ecoTi);
        }
        Hook::exec('actionJtpTicketLines', ['order' => $sale ? new Order((int) $sale['id_order']) : null, 'lines' => &$lines, 'type' => 'return']);
        $ticket = [
            'type' => 'return',
            'title' => 'DEVOLUCIÓN',
            'number' => $ret['ticket_number'],
            'original_number' => $sale ? $sale['ticket_number'] : '',
            'date' => $ret['date_add'],
            'register' => $register ? $register['name'] : '',
            'employee' => JtpEmployees::name($ret['id_employee']),
            'id_order' => (int) $ret['id_order'],
            'shop' => self::shop(),
            'options' => self::options(),
            'customer' => $sale ? self::customerBlock($sale['id_customer']) : null,
            'lines' => $lines,
            'discounts' => [],
            'totals' => ['total' => -JtpTools::round($ret['total'])],
            'taxes' => [],
            'payments' => [['name' => $ret['refund_name'], 'amount' => -JtpTools::round($ret['total']), 'reference' => '']],
            'voucher' => $ret['voucher_code'] ? ['code' => $ret['voucher_code'], 'amount' => JtpTools::round($ret['total'])] : null,
            'reason' => $ret['reason'],
            'blocks' => [],
        ];
        self::addonBlocks($ticket, ['return' => $ret, 'sale' => $sale]);

        return $ticket;
    }

    public static function z($idSession)
    {
        $s = JtpSessions::summary($idSession);
        if (!$s) {
            return null;
        }
        $ticket = [
            'type' => 'z',
            'title' => $s['session']['status'] === 'closed' ? 'CIERRE DE CAJA (Z)' : 'ESTADO DE CAJA (X)',
            'number' => 'Sesión ' . (int) $idSession,
            'date' => $s['session']['date_close'] ?: JtpTools::now(),
            'register' => $s['register'],
            'employee' => $s['employee_close'] ?: $s['employee_open'],
            'shop' => self::shop(),
            'options' => self::options(),
            'summary' => $s,
            'blocks' => [],
        ];
        self::addonBlocks($ticket, ['summary' => $s]);

        return $ticket;
    }

    public static function movement($idMovement)
    {
        $m = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_cash_movement') . ' WHERE id_movement = ' . (int) $idMovement);
        if (!$m) {
            return null;
        }
        $session = JtpSessions::get($m['id_session']);
        $register = $session ? JtpRegisters::get($session['id_register']) : null;

        return [
            'type' => 'movement',
            'title' => (float) $m['amount'] >= 0 ? 'ENTRADA DE CAJA' : 'SALIDA DE CAJA',
            'number' => 'Mov. ' . (int) $m['id_movement'],
            'date' => $m['date_add'],
            'register' => $register ? $register['name'] : '',
            'employee' => JtpEmployees::name($m['id_employee']),
            'shop' => self::shop(),
            'options' => self::options(),
            'amount' => JtpTools::round($m['amount']),
            'reason' => $m['reason'],
            'blocks' => [],
        ];
    }

    protected static function addonBlocks(array &$ticket, array $params)
    {
        $params['ticket'] = &$ticket;
        $params['type'] = $ticket['type'];
        foreach (JtpTools::collect('actionJtpTicketBlocks', $params) as $module => $blocks) {
            if (!JtpAddons::isCompatible($module)) {
                continue;
            }
            foreach ($blocks as $b) {
                if (is_array($b) && !empty($b['type'])) {
                    $b['module'] = $module;
                    $ticket['blocks'][] = $b;
                }
            }
        }
    }

    // ------------------------------------------------------------------ versión email

    protected static function e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * HTML sencillo del ticket (email). Solo tablas y estilos en línea.
     */
    public static function toHtml(array $t)
    {
        $m = function ($v) {
            return JtpTools::money($v);
        };
        $h = '<table width="100%" cellpadding="4" cellspacing="0" style="font-family:Arial,sans-serif;font-size:13px;color:#222">';
        $h .= '<tr><td colspan="4" style="text-align:center"><strong style="font-size:16px">' . self::e($t['shop']['name']) . '</strong><br>';
        foreach ($t['shop']['lines'] as $l) {
            $h .= self::e($l) . '<br>';
        }
        if ($t['shop']['header'] !== '') {
            $h .= nl2br(self::e($t['shop']['header']));
        }
        $h .= '</td></tr>';
        $h .= '<tr><td colspan="4" style="text-align:center;border-top:1px dashed #999;border-bottom:1px dashed #999">'
            . '<strong>' . self::e($t['title']) . ' ' . self::e($t['number']) . '</strong><br>' . self::e($t['date'])
            . (!empty($t['original_number']) ? '<br>Ticket original: ' . self::e($t['original_number']) : '') . '</td></tr>';
        if (!empty($t['customer'])) {
            $h .= '<tr><td colspan="4">' . self::e($t['customer']['name']) . ($t['customer']['nif'] ? '<br>NIF: ' . self::e($t['customer']['nif']) : '');
            foreach ($t['customer']['lines'] as $l) {
                $h .= '<br>' . self::e($l);
            }
            $h .= '</td></tr>';
        }
        if (!empty($t['lines'])) {
            $h .= '<tr style="background:#f3f3f3"><th align="left">Artículo</th><th align="right">Ud.</th><th align="right">Precio</th><th align="right">Total</th></tr>';
            foreach ($t['lines'] as $l) {
                $notes = '';
                foreach (isset($l['notes']) ? (array) $l['notes'] : [] as $n) {
                    $notes .= '<br><small style="color:#666">' . self::e($n) . '</small>';
                }
                $h .= '<tr><td' . (!empty($l['sub']) ? ' style="padding-left:14px;font-size:12px;color:#555"' : '') . '>' . (!empty($l['sub']) ? '+ ' : '') . self::e($l['name']) . $notes . '</td><td align="right">' . (int) $l['quantity'] . '</td><td align="right">'
                    . $m($l['unit_price']) . '</td><td align="right">' . $m($l['total']) . '</td></tr>';
            }
        }
        foreach (isset($t['discounts']) ? $t['discounts'] : [] as $d) {
            $h .= '<tr><td colspan="3">' . self::e($d['name']) . '</td><td align="right">-' . $m($d['value']) . '</td></tr>';
        }
        if (isset($t['totals']['total'])) {
            $h .= '<tr><td colspan="3" style="border-top:1px solid #222"><strong>TOTAL</strong></td><td align="right" style="border-top:1px solid #222"><strong>' . $m($t['totals']['total']) . '</strong></td></tr>';
        }
        foreach (isset($t['taxes']) ? $t['taxes'] : [] as $x) {
            $h .= '<tr><td colspan="3" style="font-size:11px;color:#666">IVA ' . self::e($x['rate']) . '% s/ ' . $m($x['base']) . '</td><td align="right" style="font-size:11px;color:#666">' . $m($x['tax']) . '</td></tr>';
            if (!empty($x['re_rate'])) {
                $h .= '<tr><td colspan="3" style="font-size:11px;color:#666">RE ' . self::e(str_replace('.', ',', (string) $x['re_rate'])) . '% s/ ' . $m($x['base']) . '</td><td align="right" style="font-size:11px;color:#666">' . $m($x['re']) . '</td></tr>';
            }
        }
        foreach (isset($t['payments']) ? $t['payments'] : [] as $p) {
            $h .= '<tr><td colspan="3">' . self::e($p['name']) . '</td><td align="right">' . $m($p['amount']) . '</td></tr>';
        }
        if (!empty($t['change'])) {
            $h .= '<tr><td colspan="3">Cambio</td><td align="right">' . $m($t['change']) . '</td></tr>';
        }
        if (!empty($t['voucher'])) {
            $h .= '<tr><td colspan="4" style="text-align:center"><strong>VALE ' . self::e($t['voucher']['code']) . ' - ' . $m($t['voucher']['amount']) . '</strong></td></tr>';
        }
        foreach ($t['blocks'] as $b) {
            if ($b['type'] === 'image' && !empty($b['value'])) {
                $src = preg_match('#^(https?:|data:)#', $b['value']) ? $b['value'] : 'data:image/png;base64,' . $b['value'];
                $h .= '<tr><td colspan="4" style="text-align:center"><img src="' . self::e($src) . '" alt="" style="max-width:160px">'
                    . (!empty($b['label']) ? '<br><small>' . self::e($b['label']) . '</small>' : '') . '</td></tr>';
            }
            if ($b['type'] === 'qr' && !empty($b['value'])) {
                // En el email no se dibuja el QR: si es un enlace, se pone como enlace
                $txt = !empty($b['label']) ? $b['label'] : 'Código QR';
                $h .= '<tr><td colspan="4" style="text-align:center">' . (preg_match('#^https?://#', $b['value'])
                    ? '<a href="' . self::e($b['value']) . '">' . self::e($txt) . '</a>' : self::e($txt . ': ' . $b['value'])) . '</td></tr>';
            }
            if (in_array($b['type'], ['text', 'title', 'pair'])) {
                $txt = $b['type'] === 'pair' ? (isset($b['label']) ? $b['label'] . ': ' : '') . $b['value'] : $b['value'];
                $h .= '<tr><td colspan="4" style="text-align:' . (isset($b['align']) ? self::e($b['align']) : 'left') . '">' . nl2br(self::e($txt)) . '</td></tr>';
            }
        }
        $h .= '<tr><td colspan="4" style="text-align:center;border-top:1px dashed #999">' . nl2br(self::e($t['shop']['footer'])) . '</td></tr>';

        return $h . '</table>';
    }

    public static function toText(array $t)
    {
        $out = [$t['shop']['name'], $t['title'] . ' ' . $t['number'], $t['date'], str_repeat('-', 32)];
        foreach (isset($t['lines']) ? $t['lines'] : [] as $l) {
            $out[] = $l['quantity'] . ' x ' . $l['name'] . '  ' . JtpTools::money($l['total']);
        }
        if (isset($t['totals']['total'])) {
            $out[] = str_repeat('-', 32);
            $out[] = 'TOTAL: ' . JtpTools::money($t['totals']['total']);
        }
        $out[] = $t['shop']['footer'];

        return implode("\n", $out);
    }
}
