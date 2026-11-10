<?php
/**
 * josratpvpresta - clientes desde el TPV: búsqueda, alta y direcciones.
 * El NIF se guarda en la dirección (campo dni y, si hay empresa, vat_number).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpCustomerService
{
    public static function defaultId()
    {
        return (int) Configuration::get('JTP_DEFAULT_CUSTOMER');
    }

    public static function isDefault($idCustomer)
    {
        return (int) $idCustomer === self::defaultId();
    }

    /**
     * Dirección del cliente por defecto para las ventas sin identificar.
     * - La elegida en Configuración > General (JTP_DEFAULT_ADDRESS), si sigue siendo suya.
     * - Si no hay ninguna elegida: su dirección "TPV" (la de la tienda), y si no tiene, se le crea una.
     *   Así un cliente de contado que ya existía y tiene direcciones de otras personas no usa una de ellas.
     *
     * @return Address|null
     */
    public static function defaultAddress()
    {
        $idCustomer = self::defaultId();
        if (!$idCustomer) {
            return null;
        }
        $chosen = (int) Configuration::get('JTP_DEFAULT_ADDRESS');
        if ($chosen && ($a = self::ownAddress($idCustomer, $chosen))) {
            return $a;
        }
        $idTpv = (int) Db::getInstance()->getValue('SELECT id_address FROM ' . JtpTools::t('address') . '
            WHERE id_customer = ' . (int) $idCustomer . ' AND alias = \'TPV\' AND deleted = 0 ORDER BY id_address DESC');
        if ($idTpv) {
            $a = new Address($idTpv);
            if (Validate::isLoadedObject($a)) {
                return $a;
            }
        }
        $customer = new Customer($idCustomer);

        return Validate::isLoadedObject($customer) ? self::createShopAddress($customer) : null;
    }

    /**
     * Direcciones del cliente por defecto para elegir en la configuración (todas, también la "TPV").
     *
     * @return array [id_address => texto]
     */
    public static function defaultAddressChoices($idCustomer)
    {
        $out = [];
        foreach (self::addressRows((int) $idCustomer) as $r) {
            $nif = self::nif($r['vat_number']) ?: self::nif($r['dni']);
            $out[(int) $r['id_address']] = ($r['alias'] === 'TPV' ? 'TPV (datos de la tienda)' : $r['alias'])
                . ' · ' . trim($r['firstname'] . ' ' . ($r['lastname'] === '-' ? '' : $r['lastname']))
                . ' · ' . trim($r['address1'] . ', ' . $r['postcode'] . ' ' . $r['city'], ', ')
                . ($nif ? ' · ' . $nif : '') . ' (#' . (int) $r['id_address'] . ')';
        }

        return $out;
    }

    public static function search($q)
    {
        $q = trim((string) $q);
        if (Tools::strlen($q) < 2) {
            return [];
        }
        $like = pSQL(str_replace(['%', '_'], ['\%', '\_'], $q));
        $terms = array_filter(preg_split('/\s+/', $like));
        $nameConds = [];
        foreach ($terms as $t) {
            $nameConds[] = '(c.firstname LIKE \'%' . $t . '%\' OR c.lastname LIKE \'%' . $t . '%\' OR c.company LIKE \'%' . $t . '%\')';
        }
        $conds = [
            '(' . implode(' AND ', $nameConds) . ')',
            'c.email LIKE \'%' . $like . '%\'',
            'a.dni LIKE \'' . $like . '%\'',
            'a.vat_number LIKE \'' . $like . '%\'',
            'a.phone LIKE \'%' . $like . '%\'',
            'a.phone_mobile LIKE \'%' . $like . '%\'',
        ];
        if (ctype_digit($q)) {
            $conds[] = 'c.id_customer = ' . (int) $q;
        }
        $shopCond = Shop::isFeatureActive() ? ' AND c.id_shop IN (' . implode(',', array_map('intval', Shop::getContextListShopID())) . ')' : '';
        $rows = Db::getInstance()->executeS('SELECT c.id_customer, c.firstname, c.lastname, c.company, c.email,
                MAX(a.dni) dni, MAX(a.vat_number) vat_number, MAX(a.phone) phone, MAX(a.phone_mobile) phone_mobile, COUNT(DISTINCT IF(a.alias <> \'TPV\', a.id_address, NULL)) addr_count
            FROM ' . JtpTools::t('customer') . ' c
            LEFT JOIN ' . JtpTools::t('address') . ' a ON (a.id_customer = c.id_customer AND a.deleted = 0)
            WHERE c.deleted = 0 AND c.id_customer <> ' . self::defaultId() . $shopCond . ' AND (' . implode(' OR ', $conds) . ')
            GROUP BY c.id_customer ORDER BY c.lastname, c.firstname LIMIT 30') ?: [];

        $out = [];
        foreach ($rows as $r) {
            $f = self::format($r);
            $f['address_count'] = (int) $r['addr_count'];
            $out[] = $f;
        }

        return $out;
    }

    public static function format(array $r)
    {
        $email = (string) $r['email'];

        return [
            'id_customer' => (int) $r['id_customer'],
            'name' => trim($r['firstname'] . ' ' . ($r['lastname'] === '-' ? '' : $r['lastname'])),
            'company' => (string) $r['company'],
            'email' => self::isPlaceholderEmail($email) ? '' : $email,
            'nif' => self::nif($r['vat_number']) ?: self::nif($r['dni']),
            'phone' => implode(' / ', array_unique(array_filter([trim((string) $r['phone_mobile']), trim((string) $r['phone'])], 'strlen'))),
        ];
    }

    /** Marcador para países que exigen DNI en la dirección cuando el cliente no lo da */
    const NO_NIF = '-';

    /**
     * NIF real de una dirección ('' si es el marcador o está vacío).
     */
    public static function nif($value)
    {
        $v = trim((string) $value);

        return ($v === '' || $v === self::NO_NIF || $v === '.') ? '' : $v;
    }

    protected static function dniFor($idCountry, $nif)
    {
        if ($nif !== '') {
            return $nif;
        }
        if (method_exists('Address', 'dniRequired') && Address::dniRequired((int) $idCountry)) {
            return self::NO_NIF;
        }

        return '';
    }

    public static function name($idCustomer)
    {
        $c = new Customer((int) $idCustomer);

        return Validate::isLoadedObject($c) ? trim($c->firstname . ' ' . $c->lastname) : '';
    }

    public static function isPlaceholderEmail($email)
    {
        return (bool) preg_match('/@tpv\.invalid$/', (string) $email);
    }

    public static function get($idCustomer, $idAddress = 0)
    {
        $c = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($c)) {
            return null;
        }
        $address = ($idAddress ? self::ownAddress($c->id, $idAddress) : null) ?: self::invoiceAddress($c->id);
        $data = self::format([
            'id_customer' => $c->id, 'firstname' => $c->firstname, 'lastname' => $c->lastname,
            'company' => $c->company, 'email' => $c->email,
            'dni' => $address ? $address->dni : '', 'vat_number' => $address ? $address->vat_number : '',
            'phone' => $address ? $address->phone : '', 'phone_mobile' => $address ? $address->phone_mobile : '',
        ]);
        $data['is_default'] = self::isDefault($c->id);
        $data['firstname'] = $c->firstname;
        $data['lastname'] = $c->lastname === '-' ? '' : $c->lastname;
        $data['address'] = $address && $address->alias !== 'TPV' ? [
            'address1' => $address->address1, 'address2' => $address->address2, 'postcode' => $address->postcode,
            'city' => $address->city, 'country' => Country::getNameById(JtpTools::langId(), $address->id_country),
            'id_country' => (int) $address->id_country, 'id_state' => (int) $address->id_state,
            'state' => $address->id_state ? (string) State::getNameById((int) $address->id_state) : '',
        ] : null;
        $data['billing_missing'] = $data['is_default'] ? [] : self::billingMissing($address);
        $data['id_address'] = $address ? (int) $address->id : 0;
        $data['phone_fixed'] = $address && !($address->alias === 'TPV' && $address->phone === (string) Configuration::get('PS_SHOP_PHONE')) ? (string) $address->phone : '';
        $data['phone_mobile'] = $address ? (string) $address->phone_mobile : '';
        $data['address_count'] = $data['is_default'] ? 0 : count(self::addresses($c->id));
        if ($address && $address->alias !== 'TPV') {
            // Datos de facturación de esta dirección (pueden ser otra persona o empresa)
            $data['address']['alias'] = $address->alias;
            $data['address']['company'] = (string) $address->company;
            $data['address']['name'] = trim($address->firstname . ' ' . ($address->lastname === '-' ? '' : $address->lastname));
        }
        // Datos extra de los addons (p. ej. recargo de equivalencia): ['clave' => valor]
        $data['flags'] = [];
        if (!$data['is_default']) {
            $extra = Hook::exec('actionJtpCustomerData', ['id_customer' => (int) $c->id, 'id_address' => $data['id_address']], null, true);
            foreach ((array) $extra as $r) {
                if (is_array($r)) {
                    $data['flags'] = array_merge($data['flags'], $r);
                }
            }
        }

        return $data;
    }

    /**
     * Dirección de facturación: la última no borrada del cliente (PrestaShop no marca
     * una dirección "de facturación" en el cliente, sino en cada pedido).
     *
     * @return Address|null
     */
    public static function invoiceAddress($idCustomer)
    {
        // Cliente por defecto (contado): la dirección elegida en la configuración, nunca una cualquiera de las suyas
        if (self::isDefault($idCustomer)) {
            $default = self::defaultAddress();
            if ($default) {
                return $default;
            }
        }
        $id = (int) Db::getInstance()->getValue('SELECT id_address FROM ' . JtpTools::t('address') . '
            WHERE id_customer = ' . (int) $idCustomer . ' AND deleted = 0 ORDER BY ((dni <> \'\' AND dni <> \'-\') OR vat_number <> \'\') DESC, date_upd DESC');
        if (!$id) {
            return null;
        }
        $a = new Address($id);

        return Validate::isLoadedObject($a) ? $a : null;
    }

    /**
     * Crea una dirección con los datos de la tienda (clientes sin dirección).
     */
    /**
     * Dirección "TPV" (la de la tienda) del cliente con el NIF de su dirección de facturación: es la de entrega
     * de las ventas en tienda, con la que se calcula el IVA (siempre el del país de la tienda).
     */
    public static function shopAddressFor(Customer $customer, ?Address $invoice = null)
    {
        $nif = '';
        if ($invoice && Validate::isLoadedObject($invoice)) {
            if ($invoice->alias === 'TPV') {
                return $invoice;
            }
            $nif = self::nif($invoice->vat_number) ?: self::nif($invoice->dni);
        }
        $country = (int) (Configuration::get('PS_SHOP_COUNTRY_ID') ?: Configuration::get('PS_COUNTRY_DEFAULT'));
        foreach (Db::getInstance()->executeS('SELECT id_address, dni, vat_number FROM ' . JtpTools::t('address') . '
            WHERE id_customer = ' . (int) $customer->id . ' AND alias = \'TPV\' AND deleted = 0 AND id_country = ' . $country . '
            ORDER BY id_address DESC') ?: [] as $r) {
            if ((self::nif($r['vat_number']) ?: self::nif($r['dni'])) === $nif) {
                return new Address((int) $r['id_address']);
            }
        }

        return self::createShopAddress($customer, $nif);
    }

    public static function createShopAddress(Customer $customer, $nif = '', $phone = '')
    {
        $a = new Address();
        $a->id_customer = (int) $customer->id;
        $a->alias = 'TPV';
        $a->firstname = $customer->firstname;
        $a->lastname = $customer->lastname;
        $a->company = (string) $customer->company;
        $a->address1 = Configuration::get('PS_SHOP_ADDR1') ?: 'Tienda';
        $a->address2 = (string) Configuration::get('PS_SHOP_ADDR2');
        $a->postcode = Configuration::get('PS_SHOP_CODE') ?: '00000';
        $a->city = Configuration::get('PS_SHOP_CITY') ?: 'Tienda';
        $a->id_country = (int) (Configuration::get('PS_SHOP_COUNTRY_ID') ?: Configuration::get('PS_COUNTRY_DEFAULT'));
        $a->id_state = (int) Configuration::get('PS_SHOP_STATE_ID');
        $p = self::phonesOf($phone);
        $a->phone = $p['phone'] !== '' || $p['phone_mobile'] !== '' ? $p['phone'] : (string) Configuration::get('PS_SHOP_PHONE');
        $a->phone_mobile = $p['phone_mobile'];
        $a->dni = self::dniFor($a->id_country, (string) $nif);
        if (!$a->add()) {
            return null;
        }

        return $a;
    }

    /**
     * Alta de cliente desde el TPV. Si hay NIF o email que ya existen devuelve el existente.
     */
    public static function create(array $d)
    {
        $firstname = trim((string) (isset($d['firstname']) ? $d['firstname'] : ''));
        $lastname = trim((string) (isset($d['lastname']) ? $d['lastname'] : ''));
        $email = trim((string) (isset($d['email']) ? $d['email'] : ''));
        $nif = Tools::strtoupper(preg_replace('/[\s\-\.]/', '', (string) (isset($d['nif']) ? $d['nif'] : '')));
        $company = trim((string) (isset($d['company']) ? $d['company'] : ''));
        $phone = self::phonesIn($d);
        if ($firstname === '' && $company !== '') {
            $firstname = $company;
        }
        if ($firstname === '') {
            throw new JtpException('Indica al menos el nombre');
        }
        if ($lastname === '') {
            $lastname = '-';
        }
        if (!Validate::isName($firstname) || !Validate::isName($lastname)) {
            throw new JtpException('Nombre o apellidos con caracteres no válidos');
        }
        if ($email !== '' && !Validate::isEmail($email)) {
            throw new JtpException('Email no válido');
        }
        // La dirección se valida antes de dar de alta nada (provincia, CP del país…)
        $addr = self::addressInput($d);
        $existingId = 0;
        if ($email !== '') {
            foreach (Customer::getCustomersByEmail($email) as $ex) {
                if (!(int) $ex['deleted']) {
                    $existingId = (int) $ex['id_customer'];
                    break;
                }
            }
        }
        if (!$existingId && $nif !== '') {
            $existingId = (int) Db::getInstance()->getValue('SELECT a.id_customer FROM ' . JtpTools::t('address') . ' a
                INNER JOIN ' . JtpTools::t('customer') . ' c ON (c.id_customer = a.id_customer AND c.deleted = 0)
                WHERE a.deleted = 0 AND (a.dni = \'' . pSQL($nif) . '\' OR a.vat_number = \'' . pSQL($nif) . '\')');
        }
        if ($existingId) {
            // Ya existía: si no tenía dirección de facturación completa y ahora se da, se le añade
            if ($addr && self::billingMissing(self::invoiceAddress($existingId))) {
                $ex = new Customer($existingId);
                self::replaceAddress($ex, self::invoiceAddress($existingId), $addr, $nif ?: null, self::hasPhone($phone) ? $phone : null, $company !== '' ? $company : null);
            }

            return $existingId;
        }
        $c = new Customer();
        $c->firstname = $firstname;
        $c->lastname = $lastname;
        $c->company = $company;
        $c->email = $email !== '' ? $email : 'tpv-' . Tools::strtolower(Tools::passwdGen(10)) . '@tpv.invalid';
        $c->passwd = password_hash(Tools::passwdGen(24), PASSWORD_BCRYPT);
        $c->active = 1;
        $c->is_guest = 0;
        $c->newsletter = 0;
        $c->optin = 0;
        $c->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
        if (!$c->add()) {
            throw new JtpException('No se pudo crear el cliente');
        }
        $c->updateGroup([(int) Configuration::get('PS_CUSTOMER_GROUP')]);

        if ($addr) {
            self::saveAddress($c, $addr, $nif, $phone, $company);
        } else {
            JtpCustomerService::createShopAddress($c, $nif, $phone);
        }
        Hook::exec('actionJtpCustomerCreated', ['customer' => $c]);

        return (int) $c->id;
    }

    /**
     * Datos de dirección del formulario del TPV, validados. null si no se ha rellenado ninguno.
     *
     * @return array|null ['address1','postcode','city','id_country','id_state']
     *
     * @throws JtpException
     */
    public static function addressInput(array $d)
    {
        $g = function ($k) use ($d) {
            return trim((string) (isset($d[$k]) ? $d[$k] : ''));
        };
        $a = [
            'address1' => $g('address1'),
            'postcode' => Tools::strtoupper($g('postcode')),
            'city' => $g('city'),
            'id_country' => (int) $g('id_country') ?: (int) Configuration::get('PS_COUNTRY_DEFAULT'),
            'id_state' => (int) $g('id_state'),
        ];
        if ($a['address1'] === '' && $a['postcode'] === '' && $a['city'] === '') {
            return null;
        }
        $missing = [];
        foreach (['address1' => 'dirección', 'postcode' => 'código postal', 'city' => 'ciudad'] as $k => $label) {
            if ($a[$k] === '') {
                $missing[] = $label;
            }
        }
        $country = new Country($a['id_country'], JtpTools::langId());
        if (!Validate::isLoadedObject($country) || !$country->active) {
            throw new JtpException('País no válido');
        }
        if ($country->contains_states) {
            $stateCountry = $a['id_state'] ? (int) Db::getInstance()->getValue('SELECT id_country FROM ' . JtpTools::t('state') . ' WHERE id_state = ' . (int) $a['id_state'] . ' AND active = 1') : 0;
            if ($stateCountry !== (int) $country->id) {
                $a['id_state'] = 0;
                $missing[] = 'provincia';
            }
        } else {
            $a['id_state'] = 0;
        }
        $pf = self::phoneFieldsFor($a['id_country']);
        foreach ($pf['required'] as $f) {
            if (in_array($f, $pf['fields']) && trim((string) (isset($d[$f]) ? $d[$f] : '')) === '') {
                $missing[] = $f === 'phone' ? 'teléfono' : 'móvil';
            }
        }
        if ($missing) {
            throw new JtpException('Para la dirección falta: ' . implode(', ', $missing) . '. Si no quieres dirección, deja vacíos dirección, CP y ciudad.');
        }
        if (!Validate::isAddress($a['address1'])) {
            throw new JtpException('La dirección tiene caracteres no válidos');
        }
        if (!Validate::isCityName($a['city'])) {
            throw new JtpException('La ciudad tiene caracteres no válidos');
        }
        if (!Validate::isPostCode($a['postcode']) || ($country->need_zip_code && $country->zip_code_format && !$country->checkZipCode($a['postcode']))) {
            $fmt = $country->zip_code_format ? str_replace(['N', 'L', 'C'], ['0', 'A', $country->iso_code], $country->zip_code_format) : '';
            throw new JtpException('Código postal no válido para ' . $country->name . ($fmt ? ' (formato ' . $fmt . ')' : ''));
        }

        return $a;
    }

    /**
     * Crea la dirección de facturación del cliente. Lanza el motivo si PrestaShop no la acepta.
     */
    protected static function saveAddress(Customer $c, array $a, $nif, $phone, $company, ?array $names = null, $alias = 'Facturación')
    {
        $ad = new Address();
        $ad->id_customer = (int) $c->id;
        $ad->alias = Tools::substr(trim((string) $alias) ?: 'Facturación', 0, 32);
        $ad->firstname = $names ? $names[0] : $c->firstname;
        $ad->lastname = $names ? $names[1] : $c->lastname;
        $ad->company = (string) $company;
        $ad->address1 = $a['address1'];
        $ad->postcode = $a['postcode'];
        $ad->city = $a['city'];
        $ad->id_country = (int) $a['id_country'];
        $ad->id_state = (int) $a['id_state'];
        $p = self::phonesOf($phone);
        $ad->phone = $p['phone'];
        $ad->phone_mobile = $p['phone_mobile'];
        $ad->dni = self::dniFor($ad->id_country, (string) $nif);
        if ($company !== '' && $nif !== '') {
            $ad->vat_number = $nif;
        }
        $err = $ad->validateFields(false, true);
        if ($err !== true) {
            throw new JtpException('Dirección no válida: ' . strip_tags((string) $err));
        }
        if (!$ad->add()) {
            throw new JtpException('No se pudo guardar la dirección');
        }

        return $ad;
    }

    /**
     * Sustituye la dirección de facturación (la anterior se borra; PrestaShop la conserva
     * para los pedidos antiguos que la usen). null en un dato = se mantiene el de la anterior.
     */
    protected static function replaceAddress(Customer $c, $old, $addr, $nif, $phone, $company, ?array $names = null)
    {
        $hasOld = $old && Validate::isLoadedObject($old);
        $realOld = $hasOld && $old->alias !== 'TPV';
        if (!$addr) {
            if (!$realOld) {
                // Sin dirección real: se mantiene la de la tienda con el NIF nuevo
                $a = self::createShopAddress($c, $nif !== null ? $nif : ($hasOld ? (self::nif($old->vat_number) ?: self::nif($old->dni)) : ''), $phone !== null ? $phone : ($hasOld ? ['phone' => $old->phone, 'phone_mobile' => $old->phone_mobile] : ''));
                if ($a && $hasOld) {
                    $old->delete();
                }

                return $a;
            }
            $addr = [
                'address1' => $old->address1, 'postcode' => $old->postcode, 'city' => $old->city,
                'id_country' => (int) $old->id_country, 'id_state' => (int) $old->id_state,
            ];
        }
        $new = self::saveAddress(
            $c,
            $addr,
            $nif !== null ? $nif : ($hasOld ? (self::nif($old->vat_number) ?: self::nif($old->dni)) : ''),
            $phone !== null ? $phone : ($hasOld ? ['phone' => $old->phone, 'phone_mobile' => $old->phone_mobile] : ''),
            $company !== null ? $company : (string) $c->company,
            $names,
            $realOld ? $old->alias : 'Facturación'
        );
        if ($hasOld) {
            $old->delete();
        }

        return $new;
    }

    /**
     * Modificar datos del cliente y su dirección de facturación desde el TPV.
     */
    /**
     * @return array ['id_customer' => int, 'id_address' => int] (la dirección guardada)
     */
    public static function update($idCustomer, array $d)
    {
        $c = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($c) || self::isDefault($c->id)) {
            throw new JtpException('Cliente no encontrado');
        }
        $firstname = trim((string) (isset($d['firstname']) ? $d['firstname'] : ''));
        $lastname = trim((string) (isset($d['lastname']) ? $d['lastname'] : '')) ?: '-';
        $email = trim((string) (isset($d['email']) ? $d['email'] : ''));
        $nif = Tools::strtoupper(preg_replace('/[\s\-\.]/', '', (string) (isset($d['nif']) ? $d['nif'] : '')));
        $company = trim((string) (isset($d['company']) ? $d['company'] : ''));
        $phone = self::phonesIn($d);
        if ($firstname === '' && $company !== '') {
            $firstname = $company;
        }
        if ($firstname === '') {
            throw new JtpException('Indica al menos el nombre');
        }
        if (!Validate::isName($firstname) || !Validate::isName($lastname)) {
            throw new JtpException('Nombre o apellidos con caracteres no válidos');
        }
        if ($email !== '' && !Validate::isEmail($email)) {
            throw new JtpException('Email no válido');
        }
        $addr = self::addressInput($d);
        if ($email !== '' && Tools::strtolower($email) !== Tools::strtolower($c->email)) {
            foreach (Customer::getCustomersByEmail($email) as $ex) {
                if (!(int) $ex['deleted'] && (int) $ex['id_customer'] !== (int) $c->id) {
                    throw new JtpException('Ese email ya es de otro cliente');
                }
            }
            $c->email = $email;
        }
        // Qué dirección se edita: la indicada, una nueva ('new') o la habitual
        $which = isset($d['id_address']) ? (string) $d['id_address'] : '';
        $old = null;
        if ($which === 'new') {
            if (!$addr) {
                throw new JtpException('Para añadir una dirección rellena dirección, CP y ciudad');
            }
        } else {
            $old = ((int) $which ? self::ownAddress($c->id, (int) $which) : null) ?: self::invoiceAddress($c->id);
        }
        // Si esa dirección es de otra persona (p. ej. factura a nombre de otro), se mantiene su nombre;
        // si era la del cliente, sigue al cliente
        $names = null;
        if ($old && $old->alias !== 'TPV' && (trim($old->firstname) !== trim($c->firstname) || trim($old->lastname) !== trim($c->lastname))) {
            $names = [$old->firstname, $old->lastname];
        }
        $c->firstname = $firstname;
        $c->lastname = $lastname;
        if ((string) $c->company === '' || count(self::addresses($c->id)) <= 1) {
            $c->company = $company;
        }
        if (!$c->update()) {
            throw new JtpException('No se pudo guardar el cliente');
        }
        if ($which === 'new') {
            $alias = 'Facturación ' . (count(self::addresses($c->id)) + 1);
            $new = self::saveAddress($c, $addr, $nif, $phone, $company, null, $alias);
            // La "dirección" provisional de la tienda ya no hace falta
            foreach (self::addressRows($c->id) as $r) {
                if ($r['alias'] === 'TPV') {
                    (new Address((int) $r['id_address']))->delete();
                }
            }
        } else {
            $new = self::replaceAddress($c, $old, $addr, $nif, $phone, $company, $names);
        }

        return ['id_customer' => (int) $c->id, 'id_address' => $new ? (int) $new->id : 0];
    }

    /** Dirección no borrada de este cliente, o null */
    public static function ownAddress($idCustomer, $idAddress)
    {
        $a = new Address((int) $idAddress);

        return Validate::isLoadedObject($a) && !$a->deleted && (int) $a->id_customer === (int) $idCustomer ? $a : null;
    }

    protected static function addressRows($idCustomer)
    {
        return Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('address') . '
            WHERE id_customer = ' . (int) $idCustomer . ' AND deleted = 0 ORDER BY date_upd DESC, id_address DESC') ?: [];
    }

    /**
     * Direcciones de facturación del cliente para elegir en el TPV. La provisional de la tienda (TPV)
     * solo aparece si no tiene ninguna real.
     */
    public static function addresses($idCustomer, $selected = 0)
    {
        if (self::isDefault($idCustomer)) {
            return [];
        }
        $rows = self::addressRows($idCustomer);
        $real = array_filter($rows, function ($r) {
            return $r['alias'] !== 'TPV';
        });
        if ($real) {
            $rows = $real;
        }
        $idLang = JtpTools::langId();
        $out = [];
        foreach ($rows as $r) {
            $a = new Address((int) $r['id_address']);
            $out[] = [
                'id_address' => (int) $r['id_address'],
                'alias' => $r['alias'],
                'is_shop' => $r['alias'] === 'TPV',
                'name' => trim($r['firstname'] . ' ' . ($r['lastname'] === '-' ? '' : $r['lastname'])),
                'company' => (string) $r['company'],
                'nif' => self::nif($r['vat_number']) ?: self::nif($r['dni']),
                'address1' => trim($r['address1'] . ' ' . $r['address2']),
                'postcode' => $r['postcode'],
                'city' => $r['city'],
                'state' => (int) $r['id_state'] ? (string) State::getNameById((int) $r['id_state']) : '',
                'country' => Country::getNameById($idLang, (int) $r['id_country']),
                'phone' => (string) ($r['phone_mobile'] ?: $r['phone']),
                'missing' => self::billingMissing($a),
                'selected' => (int) $r['id_address'] === (int) $selected,
            ];
        }

        return $out;
    }

    /** Teléfonos del formulario: fijo (phone) y móvil (phone_mobile) */
    public static function phonesIn(array $d)
    {
        $p = [
            'phone' => trim((string) (isset($d['phone']) ? $d['phone'] : '')),
            'phone_mobile' => trim((string) (isset($d['phone_mobile']) ? $d['phone_mobile'] : '')),
        ];
        foreach ($p as $k => $v) {
            if ($v !== '' && !Validate::isPhoneNumber($v)) {
                throw new JtpException(($k === 'phone' ? 'Teléfono' : 'Móvil') . ' no válido');
            }
        }

        return $p;
    }

    protected static function phonesOf($phone)
    {
        if (is_array($phone)) {
            return [
                'phone' => trim((string) (isset($phone['phone']) ? $phone['phone'] : '')),
                'phone_mobile' => trim((string) (isset($phone['phone_mobile']) ? $phone['phone_mobile'] : '')),
            ];
        }

        return ['phone' => trim((string) $phone), 'phone_mobile' => ''];
    }

    protected static function hasPhone($phone)
    {
        $p = self::phonesOf($phone);

        return $p['phone'] !== '' || $p['phone_mobile'] !== '';
    }

    /**
     * Campos de teléfono de cada país (formato de dirección de Internacional > Ubicaciones > Países)
     * y si son obligatorios (Clientes > Direcciones > campos obligatorios).
     *
     * @return array ['fields' => ['phone', 'phone_mobile'], 'required' => [...]]
     */
    public static function phoneFieldsFor($idCountry)
    {
        static $required = null;
        if ($required === null) {
            $required = [];
            try {
                $req = (new Address())->getFieldsRequiredDatabase();
                foreach (is_array($req) ? $req : [] as $r) {
                    $required[] = is_array($r) ? $r['field_name'] : (string) $r;
                }
            } catch (Exception $e) {
            }
        }
        $fields = [];
        try {
            $format = AddressFormat::getAddressCountryFormat((int) $idCountry);
            foreach (['phone', 'phone_mobile'] as $f) {
                if (preg_match('/\b' . $f . '\b/', (string) $format)) {
                    $fields[] = $f;
                }
            }
        } catch (Exception $e) {
        }
        // Si el formato del país no tiene teléfonos, se piden los dos
        if (!$fields) {
            $fields = ['phone', 'phone_mobile'];
        }

        return ['fields' => $fields, 'required' => array_values(array_intersect(['phone', 'phone_mobile'], $required))];
    }

    /**
     * Qué le falta a una dirección para una factura completa ([] si está completa).
     */
    public static function billingMissing($a)
    {
        if (!$a || !Validate::isLoadedObject($a) || $a->alias === 'TPV') {
            return ['dirección'];
        }
        $out = [];
        if (!self::nif($a->vat_number) && !self::nif($a->dni)) {
            $out[] = 'NIF';
        }
        foreach (['address1' => 'dirección', 'postcode' => 'código postal', 'city' => 'ciudad'] as $k => $label) {
            if (trim((string) $a->$k) === '') {
                $out[] = $label;
            }
        }
        if (!(int) $a->id_state && Country::containsStates((int) $a->id_country)) {
            $out[] = 'provincia';
        }

        return $out;
    }
}
