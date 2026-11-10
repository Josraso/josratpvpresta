<?php
/**
 * josratpvpresta - utilidades comunes y estado de la petición del TPV.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpTools
{
    /** true mientras se atiende una petición del TPV (carrito, cobro...) */
    public static $posRequest = false;

    /** Datos de la venta en curso mientras se ejecuta validateOrder() */
    public static $checkout = null;

    /** Bloquea emails de PrestaShop mientras se valida un pedido del TPV */
    public static $blockMails = false;

    public static function now()
    {
        return date('Y-m-d H:i:s');
    }

    public static function round($amount, $precision = 2)
    {
        return (float) Tools::ps_round((float) $amount, $precision);
    }

    public static function t($table)
    {
        return '`' . _DB_PREFIX_ . $table . '`';
    }

    public static function cfg($key, $default = null)
    {
        $v = Configuration::get($key);

        return $v === false ? $default : $v;
    }

    public static function cfgBool($key)
    {
        return (bool) Configuration::get($key);
    }

    /** Referencia de los pedidos del TPV: ticket (T1-2026-000123), id (nº de pedido) o none (la de PrestaShop u otro módulo) */
    public static function posRefMode()
    {
        $m = (string) Configuration::get('JTP_REF_MODE');
        if (in_array($m, ['ticket', 'id', 'none'], true)) {
            return $m;
        }

        return self::cfgBool('JTP_TICKET_AS_REFERENCE') ? 'ticket' : 'none';
    }

    /** Referencia de los pedidos web: series (W-2026-000123), id (nº de pedido) o none (la de PrestaShop u otro módulo) */
    public static function webRefMode()
    {
        $m = (string) Configuration::get('JTP_WEB_REF_MODE');
        if (in_array($m, ['series', 'id', 'none'], true)) {
            return $m;
        }

        return self::cfgBool('JTP_WEB_NUMERIC_REF') ? 'series' : 'none';
    }

    /**
     * Cambia la referencia de todos los pedidos de un carrito (PrestaShop les da la misma) y de sus pagos.
     */
    public static function setOrderReference(Order $order, $reference)
    {
        $db = Db::getInstance();
        $old = (string) $order->reference;
        $db->update('orders', ['reference' => pSQL($reference)], 'id_cart = ' . (int) $order->id_cart);
        if ($old !== '') {
            $db->update('order_payment', ['order_reference' => pSQL($reference)], 'order_reference = \'' . pSQL($old) . '\'');
        }
        $order->reference = $reference;
    }

    /** Id del primer pedido del carrito (si el carrito se divide en varios pedidos, todos llevan la misma referencia) */
    public static function cartFirstOrderId(Order $order)
    {
        $id = (int) Db::getInstance()->getValue('SELECT MIN(id_order) FROM `' . _DB_PREFIX_ . 'orders` WHERE id_cart = ' . (int) $order->id_cart);

        return $id ?: (int) $order->id;
    }

    public static function shopId()
    {
        return (int) Context::getContext()->shop->id;
    }

    public static function langId()
    {
        return (int) Context::getContext()->language->id;
    }

    /**
     * Número formateado según el formato decidido: SERIE-AÑO-000123
     */
    public static function formatNumber($series, $year, $number)
    {
        return sprintf('%s-%d-%06d', $series, (int) $year, (int) $number);
    }

    public static function jsonDecode($s)
    {
        $d = json_decode((string) $s, true);

        return is_array($d) ? $d : [];
    }

    public static function jsonEncode($d)
    {
        return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Ejecuta un hook y devuelve solo las respuestas array de los módulos.
     *
     * @return array [module_name => array]
     */
    public static function collect($hook, array $params = [])
    {
        $res = Hook::exec($hook, $params, null, true);
        $out = [];
        if (is_array($res)) {
            foreach ($res as $module => $value) {
                if (is_array($value)) {
                    $out[$module] = $value;
                }
            }
        }

        return $out;
    }

    public static function productName($idProduct, $idAttr, $idLang = null)
    {
        $idLang = $idLang ?: self::langId();
        $name = Product::getProductName((int) $idProduct, (int) $idAttr ?: null, $idLang);

        return $name ?: ('#' . (int) $idProduct);
    }

    public static function shopLogoUrl()
    {
        $logo = Configuration::get('PS_LOGO_INVOICE') ?: Configuration::get('PS_LOGO');
        if (!$logo) {
            return '';
        }

        return Context::getContext()->link->getMediaLink(_PS_IMG_ . $logo);
    }

    /** Ecotasa (sin IVA) de un producto o combinación (la de la combinación manda si tiene) */
    public static function ecotax($idProduct, $idAttr = 0)
    {
        if (!Configuration::get('PS_USE_ECOTAX')) {
            return 0.0;
        }
        $db = Db::getInstance();
        if ((int) $idAttr) {
            $v = (float) $db->getValue('SELECT ecotax FROM ' . self::t('product_attribute_shop') . ' WHERE id_product_attribute = ' . (int) $idAttr . ' AND id_shop = ' . self::shopId());
            if ($v > 0) {
                return $v;
            }
        }

        return (float) $db->getValue('SELECT ecotax FROM ' . self::t('product_shop') . ' WHERE id_product = ' . (int) $idProduct . ' AND id_shop = ' . self::shopId());
    }

    /** % de impuestos de la ecotasa para una dirección (con recargo si el cliente lo lleva) */
    public static function ecotaxRate($idAddress)
    {
        static $cache = [];
        $k = (int) $idAddress;
        if (!isset($cache[$k])) {
            $cache[$k] = (float) Tax::getProductEcotaxRate($k ?: null);
        }

        return $cache[$k];
    }

    /**
     * Nombre de la ecotasa de un producto para tickets y documentos ("Ecovalor NFU turismo"...).
     * Lo da un addon (hook actionJtpEcotaxLabel); si no, "Ecotasa".
     */
    public static function ecotaxLabel($idProduct, $idAttr = 0, $idOrderDetail = 0)
    {
        // El addon devuelve ['label' => 'Ecovalor NFU turismo']
        foreach (self::collect('actionJtpEcotaxLabel', ['id_product' => (int) $idProduct, 'id_product_attribute' => (int) $idAttr, 'id_order_detail' => (int) $idOrderDetail]) as $r) {
            if (!empty($r['label']) && is_string($r['label'])) {
                return trim($r['label']);
            }
        }

        return 'Ecotasa';
    }

    public static function money($amount)
    {
        $ctx = Context::getContext();
        if (method_exists('Tools', 'getContextLocale')) {
            try {
                return Tools::getContextLocale($ctx)->formatPrice((float) $amount, $ctx->currency->iso_code);
            } catch (Exception $e) {
            }
        }

        return number_format((float) $amount, 2, ',', '.') . ' €';
    }
}
