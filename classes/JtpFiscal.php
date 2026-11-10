<?php
/**
 * josratpvpresta - tipo fiscal de una venta según lo que cobró PrestaShop y el país de su dirección de impuestos.
 *
 * Lo usan el addon de FacturaScripts (régimen y causa de exención del documento) y el de informes
 * (303, OSS / 369, 349, ventas por país), así los dos clasifican igual:
 *  - national: venta en el país de la tienda (o con IVA del país de la tienda).
 *  - intra:    empresa de otro país de la UE con NIF-IVA y todo al 0 % (entrega intracomunitaria, art. 25).
 *  - export:   fuera de la UE (o Canarias, Ceuta y Melilla desde la península) con todo al 0 % (art. 21).
 *  - oss:      cliente de otro país de la UE con IVA cobrado (IVA de su país: ventanilla única, modelo 369).
 *  - mixed:    empresa UE con NIF-IVA o venta fuera de la UE, con unas líneas al 0 % y otras con IVA (no debería pasar).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpFiscal
{
    /** Países del territorio IVA de la UE (Mónaco va con Francia) */
    const EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU',
        'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'MC'];

    const EXCEPTION_INTRA = 'ES_25';
    const EXCEPTION_EXPORT = 'ES_21';

    protected static $cache = [];

    public static function isEu($iso)
    {
        return in_array(Tools::strtoupper((string) $iso), self::EU, true);
    }

    /** ISO del país de la tienda */
    public static function originIso()
    {
        return Tools::strtoupper((string) Country::getIsoById((int) Configuration::get('PS_COUNTRY_DEFAULT')));
    }

    /** Canarias, Ceuta y Melilla (fuera del territorio IVA aunque sean España) */
    public static function isSpecialTerritory($iso, $postcode)
    {
        if (Tools::strtoupper((string) $iso) !== 'ES') {
            return false;
        }
        $cp = preg_replace('/\D/', '', (string) $postcode);

        return in_array(Tools::substr($cp, 0, 2), ['35', '38', '51', '52'], true);
    }

    /** Dirección con la que PrestaShop calculó los impuestos del pedido (Preferencias > Impuestos > Basado en) */
    public static function taxAddressId(array $order)
    {
        $type = (string) Configuration::get('PS_TAX_ADDRESS_TYPE');
        $id = $type === 'id_address_invoice' ? (int) $order['id_address_invoice'] : (int) $order['id_address_delivery'];

        return $id ?: (int) $order['id_address_invoice'];
    }

    /**
     * Tipo fiscal de un pedido.
     *
     * @return array ['class', 'iso', 'id_country', 'vat', 'postcode', 'zero' (hay importes al 0 %), 'taxed' (hay importes con IVA),
     *               'exception' (causa de exención de FS o null), 'operation' (operación de FS o ''), 'warning']
     */
    public static function profile($idOrder)
    {
        $idOrder = (int) $idOrder;
        if (isset(self::$cache[$idOrder])) {
            return self::$cache[$idOrder];
        }
        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $p = _DB_PREFIX_;
        $o = $db->getRow('SELECT id_order, id_address_invoice, id_address_delivery, total_shipping_tax_excl, total_shipping_tax_incl,
                total_wrapping_tax_excl, total_wrapping_tax_incl, total_paid_tax_excl, total_paid_tax_incl
            FROM `' . $p . 'orders` WHERE id_order = ' . $idOrder);
        $out = ['class' => 'national', 'iso' => self::originIso(), 'id_country' => 0, 'vat' => '', 'postcode' => '', 'zero' => false, 'taxed' => false,
            'exception' => null, 'operation' => '', 'warning' => ''];
        if (!$o) {
            return $out;
        }
        // Venta en tienda (TPV): el IVA es el del país de la tienda (dirección de entrega "TPV")
        $pos = (bool) $db->getValue('SELECT 1 FROM `' . $p . 'jtp_sale` WHERE id_order = ' . $idOrder);
        $taxAddress = $pos ? (int) $o['id_address_delivery'] : self::taxAddressId($o);
        $a = $db->getRow('SELECT a.id_country, a.vat_number, a.postcode, a.alias, c.iso_code FROM `' . $p . 'address` a
            LEFT JOIN `' . $p . 'country` c ON c.id_country = a.id_country WHERE a.id_address = ' . $taxAddress);
        if ($a && $a['alias'] !== 'TPV') {
            $out['iso'] = Tools::strtoupper((string) $a['iso_code']);
            $out['id_country'] = (int) $a['id_country'];
            $out['vat'] = trim((string) $a['vat_number']);
            $out['postcode'] = (string) $a['postcode'];
        }
        // ¿Qué se cobró con IVA y qué al 0 %? (productos, ecotasa, portes, envoltorio)
        foreach ($db->executeS('SELECT od.total_price_tax_excl te, od.ecotax, od.ecotax_tax_rate,
                (SELECT SUM(t.rate) FROM `' . $p . 'order_detail_tax` odt INNER JOIN `' . $p . 'tax` t ON t.id_tax = odt.id_tax
                 WHERE odt.id_order_detail = od.id_order_detail) rate, od.tax_rate
            FROM `' . $p . 'order_detail` od WHERE od.id_order = ' . $idOrder) ?: [] as $d) {
            if (abs((float) $d['te']) < 0.005) {
                continue;
            }
            $rate = $d['rate'] !== null ? (float) $d['rate'] : (float) $d['tax_rate'];
            if ($rate > 0.001) {
                $out['taxed'] = true;
            } else {
                $out['zero'] = true;
            }
            if ((float) $d['ecotax'] > 0) {
                if ((float) $d['ecotax_tax_rate'] > 0.001) {
                    $out['taxed'] = true;
                } else {
                    $out['zero'] = true;
                }
            }
        }
        foreach ([['total_shipping_tax_excl', 'total_shipping_tax_incl'], ['total_wrapping_tax_excl', 'total_wrapping_tax_incl']] as $pair) {
            if ((float) $o[$pair[0]] > 0.004) {
                if ((float) $o[$pair[1]] - (float) $o[$pair[0]] > 0.004) {
                    $out['taxed'] = true;
                } else {
                    $out['zero'] = true;
                }
            }
        }
        $allZero = $out['zero'] && !$out['taxed'];
        $origin = self::originIso();
        $iso = $out['iso'];
        if ($iso === $origin || $iso === '') {
            if ($allZero && self::isSpecialTerritory($iso, $out['postcode'])) {
                $out['class'] = 'export';
            }
        } elseif (self::isEu($iso) && self::isEu($origin)) {
            if ($out['vat'] !== '' && $out['zero']) {
                $out['class'] = $out['taxed'] ? 'mixed' : 'intra';
            } elseif ($out['taxed']) {
                $out['class'] = 'oss';
            } elseif ($allZero) {
                $out['warning'] = 'Pedido a ' . $iso . ' sin IVA y sin NIF-IVA en la dirección: revisa las reglas de impuestos de ese país.';
            }
        } else {
            if ($allZero) {
                $out['class'] = 'export';
            } elseif ($out['zero'] && $out['taxed']) {
                $out['class'] = 'mixed';
            }
        }
        if ($out['class'] === 'intra') {
            $out['exception'] = self::EXCEPTION_INTRA;
            $out['operation'] = 'intracomunitaria';
        } elseif ($out['class'] === 'export') {
            $out['exception'] = self::EXCEPTION_EXPORT;
            $out['operation'] = 'exportacion';
        } elseif ($out['class'] === 'mixed') {
            $out['warning'] = ($out['vat'] !== '' && self::isEu($iso) ? 'Pedido intracomunitario' : 'Pedido de exportación')
                . ' con líneas con IVA y otras al 0 %: va en régimen general para que cuadre con lo cobrado. Revisa la configuración (vatchecker: productos "siempre con IVA" o "aplicar también al transporte").';
        }

        return self::$cache[$idOrder] = $out;
    }

    public static function className($class)
    {
        $names = ['national' => 'Nacional', 'intra' => 'Intracomunitaria (exenta)', 'export' => 'Exportación (exenta)', 'oss' => 'OSS (IVA de otro país UE)', 'mixed' => 'Mixta (revisar)'];

        return isset($names[$class]) ? $names[$class] : $class;
    }
}
