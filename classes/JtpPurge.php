<?php
/**
 * josratpvpresta - limpieza de los datos de prueba del TPV y de sus addons (pestaña Mantenimiento).
 *
 * Solo se borran datos de los módulos (ventas del TPV, cierres, cola, envíos a FacturaScripts, puntos, taller...).
 * No se toca nada de PrestaShop (pedidos, clientes, productos, stock) ni la configuración de los módulos
 * (cajas, métodos de pago, empleados, dispositivos, formatos, tipos...).
 *
 * Cada addon dice qué datos tiene con el hook actionJtpPurgeList, devolviendo elementos:
 *   ['key' => 'mi_addon_datos', 'module' => 'josratpvpresta_x', 'group' => 'Nombre del addon', 'label' => 'Qué es',
 *    'tables' => ['tabla1', 'tabla2'] (sin prefijo; se cuenta la primera)]
 * y, si tiene que hacer algo antes de borrar, con actionJtpPurgeBefore (['keys' => [...]]).
 *
 * Se borra con DELETE (no TRUNCATE): los ids no vuelven a empezar y nada antiguo se confunde con lo nuevo.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpPurge
{
    /** Elementos del TPV */
    protected static function own()
    {
        return [
            ['key' => 'tpv_sales', 'module' => 'josratpvpresta', 'group' => 'TPV', 'label' => 'Ventas y tickets del TPV, con sus cobros',
                'tables' => ['jtp_sale', 'jtp_sale_payment']],
            ['key' => 'tpv_returns', 'module' => 'josratpvpresta', 'group' => 'TPV', 'label' => 'Devoluciones del TPV',
                'tables' => ['jtp_return', 'jtp_return_line']],
            ['key' => 'tpv_sessions', 'module' => 'josratpvpresta', 'group' => 'TPV', 'label' => 'Aperturas y cierres de caja, con sus movimientos de efectivo',
                'tables' => ['jtp_session', 'jtp_cash_movement']],
            ['key' => 'tpv_carts', 'module' => 'josratpvpresta', 'group' => 'TPV', 'label' => 'Tickets en curso y aparcados (los carritos de PrestaShop no se tocan)',
                'tables' => ['jtp_cart', 'jtp_cart_line']],
            ['key' => 'tpv_queue', 'module' => 'josratpvpresta', 'group' => 'TPV', 'label' => 'Cola de trabajos (impresiones, envíos, correos)',
                'tables' => ['jtp_queue']],
        ];
    }

    /** @return array [key => elemento + count] */
    public static function items()
    {
        $list = self::own();
        $extra = Hook::exec('actionJtpPurgeList', [], null, true);
        foreach (is_array($extra) ? $extra : [] as $module => $items) {
            foreach ((array) $items as $it) {
                if (is_array($it) && !empty($it['key']) && !empty($it['tables'])) {
                    $list[] = $it + ['module' => (string) $module, 'group' => (string) $module, 'label' => $it['key']];
                }
            }
        }
        $out = [];
        foreach ($list as $it) {
            $tables = array_values(array_filter((array) $it['tables'], [__CLASS__, 'tableExists']));
            if (!$tables) {
                continue;
            }
            $it['tables'] = $tables;
            $it['count'] = (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM ' . self::t($tables[0]));
            $out[$it['key']] = $it;
        }

        return $out;
    }

    // ------------------------------------------------------------------ contadores

    /**
     * Contadores de numeración, con los pedidos de PrestaShop que aún usan referencias de esa serie
     * (si quedan, no se reinicia: el primer número nuevo coincidiría con uno de prueba).
     */
    public static function counters()
    {
        $db = Db::getInstance();
        $out = [];
        foreach ($db->executeS('SELECT `series`, `year`, `last_number` FROM ' . self::t('jtp_counter') . ' ORDER BY `series`, `year`') ?: [] as $c) {
            $prefix = $c['series'] . '-' . $c['year'] . '-';
            $like = '\'' . pSQL(str_replace(['%', '_'], ['\\%', '\\_'], $prefix)) . '%\'';
            $used = (int) $db->getValue('SELECT COUNT(*) FROM ' . self::t('orders') . ' WHERE reference LIKE ' . $like);
            // Documentos ya enviados a FacturaScripts con esa numeración (FS no se limpia desde aquí)
            $fs = self::tableExists('jtpfs_doc') ? (int) $db->getValue('SELECT COUNT(*) FROM ' . self::t('jtpfs_doc') . ' WHERE status = \'sent\' AND number LIKE ' . $like) : 0;
            $out[] = $c + ['key' => $c['series'] . '|' . $c['year'], 'orders' => $used, 'fs' => $fs];
        }

        return $out;
    }

    // ------------------------------------------------------------------ copia

    /** Copia SQL (INSERT) de las tablas de los elementos elegidos */
    public static function dump(array $keys)
    {
        $db = Db::getInstance();
        $items = self::items();
        $sql = '-- josratpvpresta: copia antes de limpiar los datos de prueba' . "\n"
            . '-- ' . date('Y-m-d H:i:s') . ' · ' . Tools::getShopDomain() . "\n"
            . '-- Para restaurar: ejecuta este archivo en la base de datos (prefijo ' . _DB_PREFIX_ . ')' . "\n\n";
        $tables = [];
        foreach ($keys as $k) {
            if (isset($items[$k])) {
                $tables = array_merge($tables, $items[$k]['tables']);
            }
        }
        if (in_array('counters', $keys, true)) {
            $tables[] = 'jtp_counter';
        }
        foreach (array_unique($tables) as $t) {
            $sql .= '-- ' . _DB_PREFIX_ . $t . "\n";
            $rows = $db->executeS('SELECT * FROM ' . self::t($t), true, false) ?: [];
            foreach (array_chunk($rows, 200) as $chunk) {
                $cols = '`' . implode('`, `', array_keys($chunk[0])) . '`';
                $vals = [];
                foreach ($chunk as $r) {
                    $vals[] = '(' . implode(', ', array_map(function ($v) {
                        return $v === null ? 'NULL' : '\'' . pSQL((string) $v, true) . '\'';
                    }, array_values($r))) . ')';
                }
                $sql .= 'INSERT IGNORE INTO `' . _DB_PREFIX_ . $t . '` (' . $cols . ') VALUES' . "\n" . implode(",\n", $vals) . ";\n";
            }
            $sql .= "\n";
        }

        return $sql;
    }

    // ------------------------------------------------------------------ borrar

    /**
     * Borra los elementos elegidos ('counters' = reiniciar los contadores que se puedan).
     *
     * @return array ['deleted' => [label => filas], 'counters' => [reiniciados], 'kept' => [series que no se reinician], 'backup' => ruta]
     */
    public static function purge(array $keys, $idEmployee)
    {
        $db = Db::getInstance();
        $items = self::items();
        $keys = array_values(array_filter($keys, function ($k) use ($items) {
            return isset($items[$k]) || $k === 'counters';
        }));
        if (!$keys) {
            throw new JtpException('Marca qué quieres borrar.');
        }
        // Contadores: se decide antes de borrar nada (mira los envíos a FacturaScripts)
        $counters = in_array('counters', $keys, true) ? self::counters() : [];
        // Copia de seguridad en el servidor antes de borrar
        $backup = self::saveBackup(self::dump($keys));
        Hook::exec('actionJtpPurgeBefore', ['keys' => $keys]);
        $res = ['deleted' => [], 'counters' => [], 'kept' => [], 'backup' => $backup];
        foreach ($keys as $k) {
            if ($k === 'counters' || !isset($items[$k])) {
                continue;
            }
            // Se informa de los registros de la tabla principal (los que salen en la lista)
            $n = (int) $items[$k]['count'];
            foreach ($items[$k]['tables'] as $t) {
                $db->execute('DELETE FROM ' . self::t($t));
            }
            $res['deleted'][$items[$k]['group'] . ': ' . $items[$k]['label']] = $n;
        }
        // Sin tickets en curso: cada caja empezará uno nuevo
        if (in_array('tpv_carts', $keys, true)) {
            $db->execute('UPDATE ' . self::t('jtp_register') . ' SET id_cart_active = 0');
        }
        foreach ($counters as $c) {
            if ($c['orders'] > 0 || $c['fs'] > 0) {
                $why = [];
                if ($c['orders']) {
                    $why[] = $c['orders'] . ' pedidos en PrestaShop';
                }
                if ($c['fs']) {
                    $why[] = $c['fs'] . ' documentos en FacturaScripts';
                }
                $res['kept'][] = $c['series'] . ' ' . $c['year'] . ' (' . implode(' y ', $why) . ' con esa numeración)';
                continue;
            }
            $db->execute('DELETE FROM ' . self::t('jtp_counter') . ' WHERE `series` = \'' . pSQL($c['series']) . '\' AND `year` = ' . (int) $c['year']);
            $res['counters'][] = $c['series'] . ' ' . $c['year'];
        }
        $emp = new Employee((int) $idEmployee);
        PrestaShopLogger::addLog('josratpvpresta: limpieza de datos de prueba por ' . trim($emp->firstname . ' ' . $emp->lastname) . ' (' . implode(', ', $keys) . '). Copia: ' . $backup, 2, null, 'Employee', (int) $idEmployee, true);

        return $res;
    }

    /** Guarda la copia en var/ con un nombre imposible de adivinar; devuelve la ruta relativa a la tienda */
    protected static function saveBackup($sql)
    {
        $dir = _PS_ROOT_DIR_ . '/var/josratpvpresta-copias';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
            @file_put_contents($dir . '/index.php', "<?php\nheader('HTTP/1.0 403 Forbidden');\nexit;\n");
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        $name = 'limpieza-' . date('Ymd-His') . '-' . Tools::passwdGen(24) . '.sql';
        if (@file_put_contents($dir . '/' . $name, $sql) === false) {
            throw new JtpException('No se pudo guardar la copia de seguridad en ' . $dir . ': no se ha borrado nada. Revisa los permisos de esa carpeta.');
        }

        return 'var/josratpvpresta-copias/' . $name;
    }

    // ------------------------------------------------------------------ ayudas

    public static function t($table)
    {
        return '`' . _DB_PREFIX_ . $table . '`';
    }

    public static function tableExists($table)
    {
        return (bool) Db::getInstance()->executeS('SHOW TABLES LIKE \'' . pSQL(_DB_PREFIX_ . $table) . '\'');
    }
}
