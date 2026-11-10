<?php
/**
 * josratpvpresta - estados de pedido del TPV.
 *
 * El TPV tiene dos estados propios: "Venta TPV" (ventas) y "Devuelto en TPV" (devoluciones totales). Aquí:
 *  - se comprueba si existen y si tienen las opciones correctas,
 *  - se regeneran (se crean si faltan, se recuperan si están borrados, se corrigen sus opciones),
 *  - y se decide qué estado usa el TPV: el propio o el que se elija en la configuración (General).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpStates
{
    /** Estados propios: clave de configuración con su id, clave del estado elegido, nombre, color y opciones */
    public static function definitions()
    {
        $base = [
            'send_email' => 0, 'invoice' => 0, 'pdf_invoice' => 0, 'pdf_delivery' => 0, 'delivery' => 0,
            'hidden' => 0, 'unremovable' => 1, 'deleted' => 0,
        ];

        return [
            'sale' => [
                'own' => 'JTP_OS_SALE',
                'use' => 'JTP_USE_OS_SALE',
                'name' => 'Venta TPV',
                'color' => '#2E7D32',
                'label' => 'Ventas',
                'flags' => $base + ['paid' => 1, 'logable' => 1, 'shipped' => 1],
            ],
            'returned' => [
                'own' => 'JTP_OS_RETURNED',
                'use' => 'JTP_USE_OS_RETURNED',
                'name' => 'Devuelto en TPV',
                'color' => '#C62828',
                'label' => 'Devoluciones totales',
                'flags' => $base + ['paid' => 1, 'logable' => 0, 'shipped' => 1],
            ],
        ];
    }

    /** Nombres legibles de las opciones de un estado */
    protected static function flagNames()
    {
        return [
            'paid' => 'pagado', 'logable' => 'cuenta en estadísticas', 'shipped' => 'enviado', 'send_email' => 'envía correo',
            'invoice' => 'genera factura', 'pdf_invoice' => 'adjunta factura', 'pdf_delivery' => 'adjunta albarán',
            'delivery' => 'entregado', 'hidden' => 'oculto', 'unremovable' => 'no se puede borrar', 'deleted' => 'borrado',
        ];
    }

    /**
     * Id del estado propio del TPV: solo cuenta un estado creado por el módulo (module_name josratpvpresta).
     * Si el id guardado en la configuración es de otro estado (p. ej. tras una migración), se ignora y se busca
     * el del TPV por su nombre; si no hay ninguno, devuelve 0 (y regenerate() lo crea de nuevo).
     */
    public static function ownId($kind)
    {
        $def = self::definitions()[$kind];
        $db = Db::getInstance();
        $id = (int) Configuration::get($def['own']);
        if ($id && $db->getValue('SELECT id_order_state FROM `' . _DB_PREFIX_ . 'order_state` WHERE id_order_state = ' . $id
                . ' AND module_name = \'josratpvpresta\'')) {
            return $id;
        }
        // El otro estado propio no puede ser este
        $other = $kind === 'sale' ? 'returned' : 'sale';
        $otherId = (int) Configuration::get(self::definitions()[$other]['own']);
        $where = 'os.module_name = \'josratpvpresta\'' . ($otherId ? ' AND os.id_order_state <> ' . $otherId : '');
        // por su nombre en cualquier idioma
        $found = (int) $db->getValue('SELECT os.id_order_state FROM `' . _DB_PREFIX_ . 'order_state` os
            INNER JOIN `' . _DB_PREFIX_ . 'order_state_lang` osl ON osl.id_order_state = os.id_order_state
            WHERE ' . $where . ' AND osl.name = \'' . pSQL($def['name']) . '\'
            ORDER BY os.deleted ASC, os.id_order_state DESC');
        if (!$found) {
            // renombrado: el del módulo con sus opciones (ventas cuentan en estadísticas, devoluciones no)
            $found = (int) $db->getValue('SELECT os.id_order_state FROM `' . _DB_PREFIX_ . 'order_state` os
                WHERE ' . $where . ' AND os.logable = ' . (int) $def['flags']['logable'] . '
                ORDER BY os.deleted ASC, os.id_order_state DESC');
        }
        if ($found) {
            Configuration::updateGlobalValue($def['own'], $found);
        }

        return $found;
    }

    /**
     * Situación del estado propio.
     *
     * @return array ['id', 'name', 'color', 'status' => ok|missing|deleted|changed, 'issues' => [...]]
     */
    public static function status($kind)
    {
        $def = self::definitions()[$kind];
        $saved = (int) Configuration::get($def['own']);
        $id = self::ownId($kind);
        $out = ['id' => $id, 'name' => $def['name'], 'color' => $def['color'], 'status' => 'ok', 'issues' => []];
        if (!$id && $saved) {
            $out['issues'][] = 'el id guardado (' . $saved . ') es de un estado que no es del TPV: no se usa ni se toca';
        }
        $state = $id ? new OrderState($id, (int) Configuration::get('PS_LANG_DEFAULT')) : null;
        if (!$state || !Validate::isLoadedObject($state)) {
            $out['status'] = 'missing';

            return $out;
        }
        $out['name'] = is_array($state->name) ? reset($state->name) : (string) $state->name;
        $out['color'] = $state->color;
        if ((int) $state->deleted) {
            $out['status'] = 'deleted';
        }
        $names = self::flagNames();
        foreach ($def['flags'] as $flag => $value) {
            if ($flag !== 'deleted' && (int) $state->{$flag} !== (int) $value) {
                $out['issues'][] = ($value ? 'debería estar ' : 'no debería estar ') . '«' . $names[$flag] . '»';
            }
        }
        if ($state->module_name !== 'josratpvpresta') {
            $out['issues'][] = 'no está asignado al módulo';
        }
        if ($out['issues'] && $out['status'] === 'ok') {
            $out['status'] = 'changed';
        }

        return $out;
    }

    /**
     * Crea el estado propio si falta, lo recupera si está borrado y le devuelve las opciones correctas.
     * Los pedidos que ya tienen ese estado no se tocan.
     *
     * @return int id del estado
     */
    public static function regenerate($kind)
    {
        $def = self::definitions()[$kind];
        // Solo se modifica un estado creado por el TPV; si no hay ninguno se crea uno nuevo
        $id = self::ownId($kind);
        $state = $id ? new OrderState($id) : null;
        if (!$state || !Validate::isLoadedObject($state) || $state->module_name !== 'josratpvpresta') {
            $state = new OrderState();
        }
        $names = [];
        foreach (Language::getLanguages(false) as $l) {
            $current = isset($state->name[(int) $l['id_lang']]) ? trim((string) $state->name[(int) $l['id_lang']]) : '';
            $names[(int) $l['id_lang']] = $current !== '' ? $current : $def['name'];
        }
        $state->name = $names;
        if (!$state->color) {
            $state->color = $def['color'];
        }
        $state->module_name = 'josratpvpresta';
        $state->template = [];
        foreach (Language::getLanguages(false) as $l) {
            $state->template[(int) $l['id_lang']] = '';
        }
        foreach ($def['flags'] as $flag => $value) {
            $state->{$flag} = $value;
        }
        $ok = $state->id ? $state->update() : $state->add();
        if (!$ok || !$state->id) {
            throw new Exception('No se pudo guardar el estado «' . $def['name'] . '».');
        }
        Configuration::updateGlobalValue($def['own'], (int) $state->id);

        return (int) $state->id;
    }

    /** Regenera los estados propios que lo necesiten; devuelve los que se han arreglado */
    public static function repairAll($force = false)
    {
        $fixed = [];
        foreach (array_keys(self::definitions()) as $kind) {
            $st = self::status($kind);
            if ($force || $st['status'] !== 'ok') {
                self::regenerate($kind);
                $fixed[] = self::definitions()[$kind]['name'];
            }
        }

        return $fixed;
    }

    /**
     * Estado que usa el TPV: el elegido en la configuración o, si no hay o ya no existe, el propio.
     * Si el propio falta o está borrado se regenera aquí mismo (una venta nunca falla por el estado).
     */
    public static function get($kind)
    {
        $def = self::definitions()[$kind];
        $chosen = (int) Configuration::get($def['use']);
        if ($chosen) {
            $state = new OrderState($chosen);
            if (Validate::isLoadedObject($state) && !(int) $state->deleted) {
                return $chosen;
            }
            PrestaShopLogger::addLog('josratpvpresta: el estado elegido para «' . $def['label'] . '» (' . $chosen . ') ya no existe; se usa el del TPV.', 2);
        }
        $st = self::status($kind);
        if ($st['status'] === 'missing' || $st['status'] === 'deleted') {
            try {
                $id = self::regenerate($kind);
                PrestaShopLogger::addLog('josratpvpresta: el estado «' . $def['name'] . '» no existía o estaba borrado y se ha regenerado (id ' . $id . ').', 2);

                return $id;
            } catch (Exception $e) {
                PrestaShopLogger::addLog('josratpvpresta: ' . $e->getMessage(), 3);
            }
        }

        return (int) self::ownId($kind);
    }

    public static function sale()
    {
        return self::get('sale');
    }

    public static function returned()
    {
        return self::get('returned');
    }

    /** Estados de PrestaShop para los selectores (0 = el propio del TPV) */
    public static function options($kind)
    {
        $def = self::definitions()[$kind];
        $own = (int) self::ownId($kind);
        $out = [['id' => 0, 'name' => 'Estado del TPV («' . $def['name'] . '»), recomendado']];
        foreach (OrderState::getOrderStates((int) Context::getContext()->language->id) as $s) {
            if ((int) $s['id_order_state'] === $own || !empty($s['deleted'])) {
                continue;
            }
            $out[] = ['id' => (int) $s['id_order_state'], 'name' => $s['name'] . ' (id ' . (int) $s['id_order_state'] . ')'];
        }

        return $out;
    }

    /** Avisos de un estado de PrestaShop elegido en lugar del propio */
    public static function warnings($idState)
    {
        $state = new OrderState((int) $idState);
        if (!Validate::isLoadedObject($state)) {
            return [];
        }
        $w = [];
        if ((int) $state->send_email) {
            $w[] = 'envía un correo al cliente cuando el pedido cambia a ese estado (al crear la venta el TPV bloquea los correos, pero no en cambios posteriores)';
        }
        if ((int) $state->invoice || (int) $state->pdf_invoice) {
            $w[] = 'genera factura de PrestaShop';
        }
        if ((int) $state->delivery || (int) $state->pdf_delivery) {
            $w[] = 'genera albarán de entrega';
        }
        if (!(int) $state->paid) {
            $w[] = 'no cuenta como pagado';
        }
        if ((int) $state->shipped) {
            $w[] = 'es un estado de envío: en algunas configuraciones de stock avanzado PrestaShop vuelve a mover stock';
        }

        return $w;
    }
}
