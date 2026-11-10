<?php
/**
 * josratpvpresta - cajas (registros) y métodos de pago.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpRegisters
{
    public static function get($idRegister)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_register') . ' WHERE id_register = ' . (int) $idRegister);

        return $row ?: null;
    }

    public static function forShop($idShop = null, $onlyActive = true)
    {
        $idShop = $idShop ?: JtpTools::shopId();

        return Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('jtp_register') . '
            WHERE id_shop = ' . (int) $idShop . ($onlyActive ? ' AND active = 1' : '') . ' ORDER BY name') ?: [];
    }

    /**
     * Métodos de pago: los de la base (tabla) + los que aporten addons.
     * Cada método: code, name, is_cash, allow_change, source (base|módulo), js (bool: el addon gestiona el cobro en JS)
     */
    public static function paymentMethods($onlyActive = true)
    {
        $idShop = JtpTools::shopId();
        $rows = Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('jtp_payment_method') . '
            WHERE id_shop IN (0, ' . (int) $idShop . ')' . ($onlyActive ? ' AND active = 1' : '') . '
            ORDER BY position ASC, id_payment_method ASC') ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[$r['code']] = [
                'code' => $r['code'],
                'name' => $r['name'],
                'is_cash' => (bool) $r['is_cash'],
                'allow_change' => (bool) ($r['allow_change'] || $r['is_cash']),
                'source' => 'base',
                'js' => false,
            ];
        }
        foreach (JtpTools::collect('actionJtpPaymentMethods') as $module => $methods) {
            if (!JtpAddons::isCompatible($module)) {
                continue;
            }
            foreach ($methods as $m) {
                if (empty($m['code']) || isset($out[$m['code']])) {
                    continue;
                }
                $out[$m['code']] = [
                    'code' => (string) $m['code'],
                    'name' => isset($m['name']) ? (string) $m['name'] : (string) $m['code'],
                    'is_cash' => !empty($m['is_cash']),
                    'allow_change' => !empty($m['allow_change']) || !empty($m['is_cash']),
                    'source' => $module,
                    'js' => !empty($m['js']),
                ];
            }
        }

        return $out;
    }
}
