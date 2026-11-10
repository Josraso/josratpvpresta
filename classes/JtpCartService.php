<?php
/**
 * josratpvpresta - carrito del TPV.
 *
 * El carrito es un Cart real de PrestaShop (el servidor manda siempre): así el total
 * que ve el cajero coincide exactamente con el del pedido. Cambios de precio o
 * descuento por línea = precio específico ligado al carrito (igual que el back office).
 * Descuento global = regla de carrito creada al vuelo con código (nunca automática).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpCartService
{
    /** @var array registro (caja) */
    protected $register;

    /** @var int */
    protected $idEmployee;

    public function __construct(array $register, $idEmployee)
    {
        $this->register = $register;
        $this->idEmployee = (int) $idEmployee;
    }

    // ------------------------------------------------------------------ carga

    /**
     * Carrito activo de la caja; si no existe o ya se convirtió en pedido, se crea uno nuevo.
     *
     * @return Cart
     */
    public function current()
    {
        $idCart = (int) $this->register['id_cart_active'];
        if ($idCart) {
            $cart = new Cart($idCart);
            $meta = $this->meta($idCart);
            if (Validate::isLoadedObject($cart) && !$cart->orderExists() && $meta && $meta['status'] === 'active') {
                $this->bindContext($cart);

                return $cart;
            }
        }

        return $this->createCart(JtpCustomerService::defaultId());
    }

    public function meta($idCart)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_cart') . ' WHERE id_cart = ' . (int) $idCart);

        return $row ?: null;
    }

    protected function setActive($idCart)
    {
        Db::getInstance()->update('jtp_register', ['id_cart_active' => (int) $idCart], 'id_register = ' . (int) $this->register['id_register']);
        $this->register['id_cart_active'] = (int) $idCart;
    }

    /**
     * @return Cart
     */
    public function createCart($idCustomer)
    {
        $ctx = Context::getContext();
        $customer = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($customer)) {
            $customer = new Customer(JtpCustomerService::defaultId());
        }
        $address = $this->addressFor($customer);
        $delivery = $this->deliveryFor($customer, $address);
        $cart = new Cart();
        $cart->id_shop = (int) $ctx->shop->id;
        $cart->id_shop_group = (int) $ctx->shop->id_shop_group;
        $cart->id_lang = (int) $ctx->language->id;
        $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_customer = (int) $customer->id;
        $cart->id_guest = 0;
        $cart->secure_key = $customer->secure_key;
        $cart->id_address_delivery = (int) $delivery->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->id_carrier = (int) Configuration::get('JTP_ID_CARRIER');
        if (!$cart->add()) {
            throw new JtpException('No se pudo crear el carrito');
        }
        $this->setDelivery($cart);
        Db::getInstance()->insert('jtp_cart', [
            'id_cart' => (int) $cart->id,
            'id_register' => (int) $this->register['id_register'],
            'id_employee' => $this->idEmployee,
            'status' => 'active',
            'date_add' => JtpTools::now(),
            'date_upd' => JtpTools::now(),
        ]);
        $this->setActive($cart->id);
        $this->bindContext($cart);

        return $cart;
    }

    protected function addressFor(Customer $customer)
    {
        $address = JtpCustomerService::invoiceAddress($customer->id);
        if (!$address) {
            $address = JtpCustomerService::createShopAddress($customer);
        }
        if (!$address) {
            throw new JtpException('El cliente no tiene dirección y no se pudo crear una');
        }

        return $address;
    }

    /** Entrega de la venta en tienda: la dirección "TPV" del cliente (país de la tienda), con su NIF */
    protected function deliveryFor(Customer $customer, Address $invoice)
    {
        $d = JtpCustomerService::shopAddressFor($customer, $invoice);

        return $d && Validate::isLoadedObject($d) ? $d : $invoice;
    }

    protected function setDelivery(Cart $cart)
    {
        $idCarrier = (int) Configuration::get('JTP_ID_CARRIER');
        $cart->id_carrier = $idCarrier;
        $cart->setDeliveryOption([(int) $cart->id_address_delivery => $idCarrier . ',']);
        $cart->update();
    }

    public function bindContext(Cart $cart)
    {
        $ctx = Context::getContext();
        $ctx->cart = $cart;
        $ctx->customer = new Customer((int) $cart->id_customer);
        $ctx->currency = new Currency((int) $cart->id_currency);
        if (isset($ctx->cookie)) {
            $ctx->cookie->id_cart = (int) $cart->id;
        }
        $address = new Address($this->taxAddress($cart));
        if (Validate::isLoadedObject($address)) {
            $ctx->country = new Country((int) $address->id_country);
        }
    }

    protected function touch($idCart)
    {
        Db::getInstance()->update('jtp_cart', ['date_upd' => JtpTools::now()], 'id_cart = ' . (int) $idCart);
    }

    protected function reload(Cart $cart)
    {
        $c = new Cart((int) $cart->id);
        $this->bindContext($c);

        return $c;
    }

    // ------------------------------------------------------------------ líneas

    protected function lineQty(Cart $cart, $idProduct, $idAttr)
    {
        return (int) Db::getInstance()->getValue('SELECT quantity FROM ' . JtpTools::t('cart_product') . '
            WHERE id_cart = ' . (int) $cart->id . ' AND id_product = ' . (int) $idProduct . '
            AND id_product_attribute = ' . (int) $idAttr . ' AND id_customization = 0');
    }

    protected function checkStock($idProduct, $idAttr, $wantedQty)
    {
        if (!Configuration::get('PS_STOCK_MANAGEMENT')) {
            return;
        }
        $product = new Product((int) $idProduct);
        $available = (int) StockAvailable::getQuantityAvailableByProduct((int) $idProduct, (int) $idAttr);
        if ($wantedQty <= $available || $product->is_virtual) {
            return;
        }
        if (JtpTools::cfgBool('JTP_ALLOW_NO_STOCK') || JtpEmployees::can($this->idEmployee, 'no_stock')) {
            return;
        }
        throw new JtpException(sprintf('Stock insuficiente (disponible: %d)', $available), ['stock' => $available]);
    }

    public function add($idProduct, $idAttr, $qty)
    {
        $cart = $this->current();
        $idProduct = (int) $idProduct;
        $idAttr = (int) $idAttr;
        $qty = max(1, (int) $qty);
        $product = new Product($idProduct, false, JtpTools::langId(), JtpTools::shopId());
        if (!Validate::isLoadedObject($product) || !$product->active) {
            throw new JtpException('Producto no disponible');
        }
        if (!$idAttr && $product->hasAttributes()) {
            throw new JtpException('Elige una variante', ['needs_variant' => true, 'id_product' => $idProduct]);
        }
        if ($idAttr && (int) Db::getInstance()->getValue('SELECT id_product FROM ' . JtpTools::t('product_attribute') . ' WHERE id_product_attribute = ' . $idAttr) !== $idProduct) {
            throw new JtpException('Variante no válida');
        }
        $errors = [];
        Hook::exec('actionJtpCartLineBefore', [
            'cart' => $cart, 'action' => 'add', 'id_product' => &$idProduct, 'id_product_attribute' => &$idAttr,
            'quantity' => &$qty, 'errors' => &$errors, 'id_employee' => $this->idEmployee,
        ]);
        if ($errors) {
            throw new JtpException(implode("\n", $errors));
        }
        $current = $this->lineQty($cart, $idProduct, $idAttr);
        $this->checkStock($idProduct, $idAttr, $current + $qty);
        $db = Db::getInstance();
        if ($current) {
            $db->execute('UPDATE ' . JtpTools::t('cart_product') . ' SET quantity = quantity + ' . $qty . '
                WHERE id_cart = ' . (int) $cart->id . ' AND id_product = ' . $idProduct . ' AND id_product_attribute = ' . $idAttr . ' AND id_customization = 0');
        } else {
            $db->insert('cart_product', [
                'id_cart' => (int) $cart->id,
                'id_product' => $idProduct,
                'id_address_delivery' => (int) $cart->id_address_delivery,
                'id_shop' => (int) $cart->id_shop,
                'id_product_attribute' => $idAttr,
                'id_customization' => 0,
                'quantity' => $qty,
                'date_add' => JtpTools::now(),
            ]);
            $original = Product::getPriceStatic($idProduct, true, $idAttr, 6, null, false, true, 1, false, (int) $cart->id_customer, (int) $cart->id, $this->taxAddress($cart));
            $pos = (int) $db->getValue('SELECT IFNULL(MAX(position),0)+1 FROM ' . JtpTools::t('jtp_cart_line') . ' WHERE id_cart = ' . (int) $cart->id);
            $db->execute('REPLACE INTO ' . JtpTools::t('jtp_cart_line') . ' (id_cart, id_product, id_product_attribute, original_price, mode, value, position)
                VALUES (' . (int) $cart->id . ', ' . $idProduct . ', ' . $idAttr . ', ' . (float) $original . ', \'\', 0, ' . $pos . ')');
        }
        $this->touch($cart->id);

        return $this->state($this->reload($cart), ['last' => $idProduct . '-' . $idAttr]);
    }

    public function setQty($idProduct, $idAttr, $qty)
    {
        $cart = $this->current();
        $qty = (int) $qty;
        if ($qty <= 0) {
            return $this->remove($idProduct, $idAttr);
        }
        $errors = [];
        Hook::exec('actionJtpCartLineBefore', [
            'cart' => $cart, 'action' => 'qty', 'id_product' => &$idProduct, 'id_product_attribute' => &$idAttr,
            'quantity' => &$qty, 'errors' => &$errors, 'id_employee' => $this->idEmployee,
        ]);
        if ($errors) {
            throw new JtpException(implode("\n", $errors));
        }
        $this->checkStock($idProduct, $idAttr, $qty);
        Db::getInstance()->execute('UPDATE ' . JtpTools::t('cart_product') . ' SET quantity = ' . $qty . '
            WHERE id_cart = ' . (int) $cart->id . ' AND id_product = ' . (int) $idProduct . ' AND id_product_attribute = ' . (int) $idAttr . ' AND id_customization = 0');
        $this->touch($cart->id);

        return $this->state($this->reload($cart));
    }

    public function remove($idProduct, $idAttr)
    {
        $cart = $this->current();
        $db = Db::getInstance();
        $where = 'id_cart = ' . (int) $cart->id . ' AND id_product = ' . (int) $idProduct . ' AND id_product_attribute = ' . (int) $idAttr;
        $db->delete('cart_product', $where . ' AND id_customization = 0');
        $db->delete('jtp_cart_line', $where);
        $db->delete('specific_price', $where);
        $this->touch($cart->id);

        return $this->state($this->reload($cart));
    }

    /**
     * Precio de línea: mode = price (precio unitario final IVA incl.), percent, amount (euros por unidad), '' (quitar).
     */
    public function setLinePrice($idProduct, $idAttr, $mode, $value)
    {
        $cart = $this->current();
        $idProduct = (int) $idProduct;
        $idAttr = (int) $idAttr;
        $value = (float) $value;
        $db = Db::getInstance();
        $line = $db->getRow('SELECT * FROM ' . JtpTools::t('jtp_cart_line') . ' WHERE id_cart = ' . (int) $cart->id . '
            AND id_product = ' . $idProduct . ' AND id_product_attribute = ' . $idAttr);
        if (!$line) {
            throw new JtpException('Línea no encontrada');
        }
        $perm = $mode === 'price' ? 'price' : 'discount';
        if ($mode !== '' && !JtpEmployees::can($this->idEmployee, $perm)) {
            throw new JtpException($mode === 'price' ? 'No tienes permiso para cambiar precios' : 'No tienes permiso para aplicar descuentos');
        }
        $original = (float) $line['original_price'];
        // La ecotasa es un importe fijo: los descuentos no le afectan y PrestaShop la suma aparte al precio
        $eco = JtpTools::ecotax($idProduct, $idAttr);
        $ecoTi = $eco > 0 ? $eco * (1 + JtpTools::ecotaxRate($this->taxAddress($cart)) / 100) : 0.0;
        switch ($mode) {
            case 'price':
                $target = $value;
                break;
            case 'percent':
                if ($value < 0 || $value > 100) {
                    throw new JtpException('El descuento debe estar entre 0 y 100 %');
                }
                $target = $ecoTi + ($original - $ecoTi) * (1 - $value / 100);
                break;
            case 'amount':
                $target = $original - $value;
                break;
            default:
                $mode = '';
                $target = null;
        }
        if ($target !== null && $target < 0) {
            throw new JtpException('El precio no puede ser negativo');
        }
        if ($target !== null && $ecoTi > 0 && $target < $ecoTi - 0.004) {
            throw new JtpException('El precio no puede ser menor que la ecotasa (' . JtpTools::money($ecoTi) . ')');
        }
        $where = 'id_cart = ' . (int) $cart->id . ' AND id_product = ' . $idProduct . ' AND id_product_attribute = ' . $idAttr;
        $db->delete('specific_price', $where);
        if ($target !== null) {
            $rate = (float) Tax::getProductTaxRate($idProduct, $this->taxAddress($cart));
            $priceTe = round(($target - $ecoTi) / (1 + $rate / 100), 6);
            $sp = new SpecificPrice();
            $sp->id_cart = (int) $cart->id;
            $sp->id_shop = 0;
            $sp->id_shop_group = 0;
            $sp->id_currency = 0;
            $sp->id_country = 0;
            $sp->id_group = 0;
            $sp->id_customer = 0;
            $sp->id_product = $idProduct;
            $sp->id_product_attribute = $idAttr;
            $sp->price = $priceTe;
            $sp->from_quantity = 1;
            $sp->reduction = 0;
            $sp->reduction_tax = 1;
            $sp->reduction_type = 'amount';
            $sp->from = '0000-00-00 00:00:00';
            $sp->to = '0000-00-00 00:00:00';
            if (!$sp->add()) {
                throw new JtpException('No se pudo aplicar el precio');
            }
        }
        $db->update('jtp_cart_line', ['mode' => pSQL($mode), 'value' => (float) $value], $where);
        $this->touch($cart->id);

        return $this->state($this->reload($cart));
    }

    // ------------------------------------------------------------------ descuentos y vales

    protected function newCartRule($name, $prefix)
    {
        $rule = new CartRule();
        $names = [];
        foreach (Language::getLanguages(false) as $l) {
            $names[(int) $l['id_lang']] = $name;
        }
        $rule->name = $names;
        $rule->code = $prefix . Tools::strtoupper(Tools::passwdGen(8, 'NO_NUMERIC'));
        $rule->id_customer = 0;
        $rule->date_from = date('Y-m-d H:i:s', time() - 3600);
        $rule->quantity = 1;
        $rule->quantity_per_user = 1;
        $rule->highlight = 0;
        $rule->partial_use = 0;
        $rule->active = 1;
        $rule->priority = 1;
        $rule->reduction_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $rule->minimum_amount_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');

        return $rule;
    }

    public function setDiscount($type, $value)
    {
        $cart = $this->current();
        if (!JtpEmployees::can($this->idEmployee, 'discount')) {
            throw new JtpException('No tienes permiso para aplicar descuentos');
        }
        $meta = $this->meta($cart->id);
        $this->removeDiscountRule($cart, $meta);
        $value = (float) $value;
        if ($value > 0) {
            $rule = $this->newCartRule('Descuento TPV', 'TPVD');
            $rule->date_to = date('Y-m-d H:i:s', time() + 86400 * 30);
            $rule->description = 'Descuento manual del TPV (carrito ' . (int) $cart->id . ')';
            if ($type === 'percent') {
                if ($value > 100) {
                    throw new JtpException('El descuento no puede superar el 100 %');
                }
                $rule->reduction_percent = $value;
            } else {
                $type = 'amount';
                $rule->reduction_amount = $value;
                $rule->reduction_tax = 1;
            }
            if (!$rule->add()) {
                throw new JtpException('No se pudo crear el descuento');
            }
            $cart->addCartRule((int) $rule->id);
            Db::getInstance()->update('jtp_cart', [
                'id_cart_rule_discount' => (int) $rule->id,
                'discount_type' => pSQL($type),
                'discount_value' => $value,
            ], 'id_cart = ' . (int) $cart->id);
        }
        $this->touch($cart->id);

        return $this->state($this->reload($cart));
    }

    protected function removeDiscountRule(Cart $cart, $meta)
    {
        if ($meta && (int) $meta['id_cart_rule_discount']) {
            $cart->removeCartRule((int) $meta['id_cart_rule_discount']);
            $rule = new CartRule((int) $meta['id_cart_rule_discount']);
            if (Validate::isLoadedObject($rule)) {
                $rule->delete();
            }
            Db::getInstance()->update('jtp_cart', ['id_cart_rule_discount' => 0, 'discount_type' => '', 'discount_value' => 0], 'id_cart = ' . (int) $cart->id);
        }
    }

    public function addVoucher($code)
    {
        $cart = $this->current();
        $code = trim((string) $code);
        $idRule = (int) CartRule::getIdByCode($code);
        if (!$idRule) {
            throw new JtpException('Vale o código no encontrado');
        }
        $rule = new CartRule($idRule, JtpTools::langId());
        if (!$cart->getProducts()) {
            throw new JtpException('Añade productos antes de aplicar el vale');
        }
        $error = $rule->checkValidity(Context::getContext(), false, true);
        if ($error) {
            throw new JtpException(is_string($error) ? $error : 'El vale no es válido para este carrito');
        }
        $cart->addCartRule($idRule);
        $this->touch($cart->id);

        return $this->state($this->reload($cart));
    }

    public function removeVoucher($idRule)
    {
        $cart = $this->current();
        $meta = $this->meta($cart->id);
        if ($meta && (int) $meta['id_cart_rule_discount'] === (int) $idRule) {
            $this->removeDiscountRule($cart, $meta);
        } else {
            $cart->removeCartRule((int) $idRule);
        }

        return $this->state($this->reload($cart));
    }

    // ------------------------------------------------------------------ cliente

    /**
     * @param int $idAddress dirección de facturación elegida (0 = la habitual del cliente)
     */
    public function setCustomer($idCustomer, $idAddress = 0)
    {
        $cart = $this->current();
        $customer = new Customer((int) $idCustomer ?: JtpCustomerService::defaultId());
        if (!Validate::isLoadedObject($customer)) {
            throw new JtpException('Cliente no encontrado');
        }
        $address = null;
        if ($idAddress) {
            $address = JtpCustomerService::ownAddress($customer->id, $idAddress);
            if (!$address) {
                throw new JtpException('Esa dirección no es de este cliente');
            }
        }
        $address = $address ?: $this->addressFor($customer);
        $delivery = $this->deliveryFor($customer, $address);
        $oldAddress = (int) $cart->id_address_delivery;
        $cart->id_customer = (int) $customer->id;
        $cart->secure_key = $customer->secure_key;
        $cart->id_address_delivery = (int) $delivery->id;
        $cart->id_address_invoice = (int) $address->id;
        $cart->update();
        if ($oldAddress !== (int) $delivery->id) {
            Db::getInstance()->update('cart_product', ['id_address_delivery' => (int) $delivery->id], 'id_cart = ' . (int) $cart->id);
        }
        $cart = $this->reload($cart);
        $this->setDelivery($cart);
        // Recalcular precios originales (pueden cambiar por grupo de cliente)
        $this->refreshOriginalPrices($cart);

        return $this->state($this->reload($cart));
    }

    protected function refreshOriginalPrices(Cart $cart)
    {
        $db = Db::getInstance();
        $lines = $db->executeS('SELECT * FROM ' . JtpTools::t('jtp_cart_line') . ' WHERE id_cart = ' . (int) $cart->id) ?: [];
        foreach ($lines as $l) {
            if ($l['mode'] !== '') {
                continue;
            }
            $p = Product::getPriceStatic((int) $l['id_product'], true, (int) $l['id_product_attribute'], 6, null, false, true, 1, false, (int) $cart->id_customer, (int) $cart->id, $this->taxAddress($cart));
            $db->update('jtp_cart_line', ['original_price' => (float) $p], 'id_cart = ' . (int) $cart->id . ' AND id_product = ' . (int) $l['id_product'] . ' AND id_product_attribute = ' . (int) $l['id_product_attribute']);
        }
    }

    public function setNote($note)
    {
        $cart = $this->current();
        Db::getInstance()->update('jtp_cart', ['note' => pSQL(Tools::substr((string) $note, 0, 1000))], 'id_cart = ' . (int) $cart->id);

        return $this->state($cart);
    }

    // ------------------------------------------------------------------ vaciar / aparcar

    public function clear()
    {
        $cart = $this->current();
        $this->discard($cart);

        return $this->state($this->createCart(JtpCustomerService::defaultId()));
    }

    /**
     * Elimina un carrito del TPV sin pedido (reglas de descuento y precios incluidos).
     */
    protected function discard(Cart $cart)
    {
        $db = Db::getInstance();
        $this->removeDiscountRule($cart, $this->meta($cart->id));
        $db->delete('specific_price', 'id_cart = ' . (int) $cart->id);
        $db->delete('jtp_cart_line', 'id_cart = ' . (int) $cart->id);
        $db->delete('jtp_cart', 'id_cart = ' . (int) $cart->id);
        if (!$cart->orderExists()) {
            $cart->delete();
        }
    }

    public function park($label)
    {
        $cart = $this->current();
        if (!$cart->getProducts()) {
            throw new JtpException('El ticket está vacío');
        }
        Db::getInstance()->update('jtp_cart', [
            'status' => 'parked',
            'label' => pSQL(Tools::substr(trim((string) $label), 0, 128)),
            'id_employee' => $this->idEmployee,
            'date_upd' => JtpTools::now(),
        ], 'id_cart = ' . (int) $cart->id);

        return $this->state($this->createCart(JtpCustomerService::defaultId()));
    }

    public function parkedList()
    {
        $rows = Db::getInstance()->executeS('SELECT j.*, CONCAT(e.firstname, \' \', e.lastname) employee,
                (SELECT SUM(cp.quantity) FROM ' . JtpTools::t('cart_product') . ' cp WHERE cp.id_cart = j.id_cart) items
            FROM ' . JtpTools::t('jtp_cart') . ' j
            LEFT JOIN ' . JtpTools::t('employee') . ' e ON e.id_employee = j.id_employee
            INNER JOIN ' . JtpTools::t('jtp_register') . ' r ON r.id_register = j.id_register
            WHERE j.status = \'parked\' AND r.id_shop = ' . JtpTools::shopId() . '
            ORDER BY j.date_upd DESC') ?: [];
        $out = [];
        foreach ($rows as $r) {
            $cart = new Cart((int) $r['id_cart']);
            if (!Validate::isLoadedObject($cart) || $cart->orderExists()) {
                continue;
            }
            $out[] = [
                'id_cart' => (int) $r['id_cart'],
                'label' => $r['label'],
                'employee' => $r['employee'],
                'items' => (int) $r['items'],
                'total' => JtpTools::round($cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING)),
                'date' => $r['date_upd'],
                'customer' => JtpCustomerService::isDefault($cart->id_customer) ? '' : JtpCustomerService::name($cart->id_customer),
            ];
        }

        return $out;
    }

    public function resume($idCart)
    {
        $meta = $this->meta($idCart);
        if (!$meta || $meta['status'] !== 'parked') {
            throw new JtpException('Ticket aparcado no encontrado');
        }
        // Si el ticket actual tiene productos, se aparca automáticamente
        $current = $this->current();
        if ($current->getProducts()) {
            Db::getInstance()->update('jtp_cart', ['status' => 'parked', 'label' => 'Auto ' . date('H:i'), 'date_upd' => JtpTools::now()], 'id_cart = ' . (int) $current->id);
        } else {
            $this->discard($current);
        }
        Db::getInstance()->update('jtp_cart', [
            'status' => 'active',
            'id_register' => (int) $this->register['id_register'],
            'date_upd' => JtpTools::now(),
        ], 'id_cart = ' . (int) $idCart);
        $this->setActive((int) $idCart);

        return $this->state($this->current());
    }

    public function deleteParked($idCart)
    {
        $meta = $this->meta($idCart);
        if (!$meta || $meta['status'] !== 'parked') {
            throw new JtpException('Ticket aparcado no encontrado');
        }
        $this->discard(new Cart((int) $idCart));

        return $this->parkedList();
    }

    /** Dirección con la que PrestaShop calcula los impuestos del carrito */
    protected function taxAddress(Cart $cart)
    {
        $type = Configuration::get('PS_TAX_ADDRESS_TYPE') === 'id_address_delivery' ? 'id_address_delivery' : 'id_address_invoice';

        return (int) $cart->{$type} ?: (int) $cart->id_address_invoice;
    }

    // ------------------------------------------------------------------ estado para la pantalla

    /**
     * Estado completo del carrito para la pantalla de venta.
     */
    public function state($cart = null, array $extra = [])
    {
        if (!$cart) {
            $cart = $this->current();
        }
        $db = Db::getInstance();
        $lines = [];
        $metaLines = [];
        foreach ($db->executeS('SELECT * FROM ' . JtpTools::t('jtp_cart_line') . ' WHERE id_cart = ' . (int) $cart->id) ?: [] as $l) {
            $metaLines[$l['id_product'] . '-' . $l['id_product_attribute']] = $l;
        }
        $products = $cart->getProducts(true);
        foreach ($products as $p) {
            $key = $p['id_product'] . '-' . $p['id_product_attribute'];
            $m = isset($metaLines[$key]) ? $metaLines[$key] : null;
            $unit = JtpTools::round($p['price_wt']);
            $original = $m ? JtpTools::round($m['original_price']) : $unit;
            // Ecotasa incluida en el precio (ecotasa nativa de PrestaShop), con su IVA
            $eco = isset($p['ecotax']) && Configuration::get('PS_USE_ECOTAX') ? (float) $p['ecotax'] : 0.0;
            $ecoTi = $eco > 0 ? JtpTools::round($eco * (1 + JtpTools::ecotaxRate($this->taxAddress($cart)) / 100)) : 0.0;
            $lines[] = [
                'key' => $key,
                'id_product' => (int) $p['id_product'],
                'id_product_attribute' => (int) $p['id_product_attribute'],
                'name' => $p['name'],
                'attributes' => isset($p['attributes_small']) ? $p['attributes_small'] : (isset($p['attributes']) ? $p['attributes'] : ''),
                'reference' => $p['reference'],
                'quantity' => (int) $p['cart_quantity'],
                'unit_price' => $unit,
                'original_price' => $original,
                'total' => JtpTools::round($p['total_wt']),
                'tax_rate' => (float) $p['rate'],
                'mode' => $m ? $m['mode'] : '',
                'value' => $m ? (float) $m['value'] : 0,
                'position' => $m ? (int) $m['position'] : 0,
                'stock' => (int) StockAvailable::getQuantityAvailableByProduct((int) $p['id_product'], (int) $p['id_product_attribute']),
                'ecotax' => $ecoTi,
                // Notas pequeñas bajo la línea (los addons pueden cambiarlas en actionJtpCartTotals)
                'notes' => $ecoTi > 0 ? ['Incluye ecotasa ' . JtpTools::money($ecoTi) . '/ud.'] : [],
            ];
        }
        usort($lines, function ($a, $b) {
            return $a['position'] - $b['position'];
        });
        $meta = $this->meta($cart->id);
        $discounts = [];
        foreach ($cart->getCartRules() as $r) {
            $discounts[] = [
                'id_cart_rule' => (int) $r['id_cart_rule'],
                'name' => $r['name'],
                'code' => $r['code'],
                'value' => JtpTools::round($r['value_real']),
                'manual' => $meta && (int) $meta['id_cart_rule_discount'] === (int) $r['id_cart_rule'],
            ];
        }
        $totalTi = JtpTools::round($cart->getOrderTotal(true, Cart::BOTH));
        $totalTe = JtpTools::round($cart->getOrderTotal(false, Cart::BOTH));
        $totals = [
            'products' => JtpTools::round($cart->getOrderTotal(true, Cart::ONLY_PRODUCTS)),
            'discounts' => JtpTools::round($cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS)),
            'shipping' => JtpTools::round($cart->getOrderTotal(true, Cart::ONLY_SHIPPING)),
            'total' => $totalTi,
            'total_tax_excl' => $totalTe,
            'tax' => JtpTools::round($totalTi - $totalTe),
            'items' => array_sum(array_column($lines, 'quantity')),
        ];
        Hook::exec('actionJtpCartTotals', ['cart' => $cart, 'lines' => &$lines, 'totals' => &$totals]);

        return array_merge([
            'id_cart' => (int) $cart->id,
            'customer' => JtpCustomerService::get((int) $cart->id_customer, (int) $cart->id_address_invoice),
            'lines' => $lines,
            'discounts' => $discounts,
            'manual_discount' => $meta ? ['type' => $meta['discount_type'], 'value' => (float) $meta['discount_value']] : null,
            'note' => $meta ? (string) $meta['note'] : '',
            'totals' => $totals,
        ], $extra);
    }
}
