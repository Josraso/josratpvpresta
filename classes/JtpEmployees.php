<?php
/**
 * josratpvpresta - empleados del TPV y permisos.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpEmployees
{
    public static function permissionKeys()
    {
        return [
            'perm_discount' => 'Aplicar descuentos',
            'perm_price' => 'Cambiar precios',
            'perm_return' => 'Hacer devoluciones',
            'perm_cash_move' => 'Entradas/salidas de caja',
            'perm_close' => 'Abrir y cerrar caja',
            'perm_no_stock' => 'Vender sin stock',
            'perm_all_sales' => 'Ver ventas de otros empleados',
            'perm_favorites' => 'Gestionar favoritos',
        ];
    }

    public static function get($idEmployee)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_employee') . ' WHERE id_employee = ' . (int) $idEmployee);

        return $row ?: null;
    }

    /**
     * Permisos efectivos. El superadministrador lo tiene todo.
     */
    public static function permissions($idEmployee)
    {
        $employee = new Employee((int) $idEmployee);
        $perms = [];
        $row = self::get($idEmployee);
        $isAdmin = Validate::isLoadedObject($employee) && (int) $employee->id_profile === (int) _PS_ADMIN_PROFILE_;
        foreach (array_keys(self::permissionKeys()) as $k) {
            $short = substr($k, 5);
            $perms[$short] = $isAdmin ? true : ($row ? (bool) $row[$k] : false);
        }
        // Sin fila configurada: permisos básicos para poder vender
        if (!$row && !$isAdmin) {
            $perms['discount'] = true;
            $perms['return'] = true;
            $perms['cash_move'] = true;
            $perms['close'] = true;
        }

        return $perms;
    }

    public static function can($idEmployee, $perm)
    {
        $p = self::permissions($idEmployee);

        return !empty($p[$perm]);
    }

    /**
     * Empleados que pueden entrar con PIN.
     */
    public static function pinList()
    {
        return Db::getInstance()->executeS('SELECT e.id_employee, e.firstname, e.lastname
            FROM ' . JtpTools::t('employee') . ' e
            INNER JOIN ' . JtpTools::t('jtp_employee') . ' j ON j.id_employee = e.id_employee
            WHERE e.active = 1 AND j.enabled = 1 AND j.pin <> \'\'
            ORDER BY e.firstname, e.lastname') ?: [];
    }

    public static function save($idEmployee, array $data, $pin = null)
    {
        $row = ['id_employee' => (int) $idEmployee, 'enabled' => (int) !empty($data['enabled'])];
        foreach (array_keys(self::permissionKeys()) as $k) {
            $row[$k] = (int) !empty($data[$k]);
        }
        $existing = self::get($idEmployee);
        if ($pin !== null && $pin !== '') {
            $row['pin'] = pSQL(password_hash((string) $pin, PASSWORD_BCRYPT));
        } elseif (!$existing) {
            $row['pin'] = '';
        }
        if ($existing) {
            return Db::getInstance()->update('jtp_employee', $row, 'id_employee = ' . (int) $idEmployee);
        }

        return Db::getInstance()->insert('jtp_employee', $row);
    }

    public static function name($idEmployee)
    {
        $e = new Employee((int) $idEmployee);

        return Validate::isLoadedObject($e) ? trim($e->firstname . ' ' . $e->lastname) : '';
    }
}
