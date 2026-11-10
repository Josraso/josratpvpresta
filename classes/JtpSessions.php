<?php
/**
 * josratpvpresta - sesiones de caja: apertura, movimientos, arqueo y cierre.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpSessions
{
    public static function openFor($idRegister)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_session') . '
            WHERE id_register = ' . (int) $idRegister . ' AND status = \'open\' ORDER BY id_session DESC');

        return $row ?: null;
    }

    public static function get($idSession)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_session') . ' WHERE id_session = ' . (int) $idSession);

        return $row ?: null;
    }

    public static function open($idRegister, $idEmployee, $amount)
    {
        if (self::openFor($idRegister)) {
            throw new JtpException('La caja ya está abierta');
        }
        $register = JtpRegisters::get($idRegister);
        Db::getInstance()->insert('jtp_session', [
            'id_register' => (int) $idRegister,
            'id_shop' => (int) $register['id_shop'],
            'id_employee_open' => (int) $idEmployee,
            'opening_amount' => (float) $amount,
            'status' => 'open',
            'date_open' => JtpTools::now(),
        ]);
        $id = (int) Db::getInstance()->Insert_ID();
        self::addMovement($id, $idEmployee, 'open', (float) $amount, 'Apertura de caja');
        $session = self::get($id);
        Hook::exec('actionJtpSessionOpened', ['session' => $session, 'register' => $register]);

        return $session;
    }

    public static function addMovement($idSession, $idEmployee, $type, $amount, $reason = '', $idSale = 0, $idReturn = 0)
    {
        Db::getInstance()->insert('jtp_cash_movement', [
            'id_session' => (int) $idSession,
            'id_employee' => (int) $idEmployee,
            'type' => pSQL($type),
            'amount' => (float) $amount,
            'reason' => pSQL(Tools::substr((string) $reason, 0, 255)),
            'id_sale' => (int) $idSale,
            'id_return' => (int) $idReturn,
            'date_add' => JtpTools::now(),
        ]);
        $id = (int) Db::getInstance()->Insert_ID();
        if (in_array($type, ['in', 'out'])) {
            Hook::exec('actionJtpCashMovement', [
                'id_movement' => $id, 'id_session' => (int) $idSession, 'type' => $type,
                'amount' => (float) $amount, 'reason' => $reason,
            ]);
        }

        return $id;
    }

    /**
     * Resumen de la sesión: ventas por método, devoluciones, movimientos y efectivo esperado.
     */
    public static function summary($idSession)
    {
        $db = Db::getInstance();
        $session = self::get($idSession);
        if (!$session) {
            return null;
        }
        $sales = $db->getRow('SELECT COUNT(*) n, IFNULL(SUM(total),0) total FROM ' . JtpTools::t('jtp_sale') . ' WHERE id_session = ' . (int) $idSession);
        $byMethod = $db->executeS('SELECT p.code, p.name, SUM(p.amount) amount, COUNT(DISTINCT p.id_sale) n
            FROM ' . JtpTools::t('jtp_sale_payment') . ' p
            INNER JOIN ' . JtpTools::t('jtp_sale') . ' s ON s.id_sale = p.id_sale
            WHERE s.id_session = ' . (int) $idSession . ' GROUP BY p.code, p.name ORDER BY amount DESC') ?: [];
        $returns = $db->getRow('SELECT COUNT(*) n, IFNULL(SUM(total),0) total FROM ' . JtpTools::t('jtp_return') . ' WHERE id_session = ' . (int) $idSession);
        $returnsByMethod = $db->executeS('SELECT refund_code code, refund_name name, SUM(total) amount, COUNT(*) n
            FROM ' . JtpTools::t('jtp_return') . ' WHERE id_session = ' . (int) $idSession . ' GROUP BY refund_code, refund_name') ?: [];
        $moves = $db->executeS('SELECT type, SUM(amount) amount, COUNT(*) n FROM ' . JtpTools::t('jtp_cash_movement') . '
            WHERE id_session = ' . (int) $idSession . ' GROUP BY type') ?: [];
        $m = ['open' => 0, 'sale' => 0, 'refund' => 0, 'in' => 0, 'out' => 0];
        foreach ($moves as $row) {
            $m[$row['type']] = (float) $row['amount'];
        }
        // Todos los movimientos se guardan con signo: salidas y reembolsos en negativo
        $expected = $m['open'] + $m['sale'] + $m['refund'] + $m['in'] + $m['out'];
        $movementList = $db->executeS('SELECT m.*, CONCAT(e.firstname, \' \', e.lastname) employee
            FROM ' . JtpTools::t('jtp_cash_movement') . ' m
            LEFT JOIN ' . JtpTools::t('employee') . ' e ON e.id_employee = m.id_employee
            WHERE m.id_session = ' . (int) $idSession . ' AND m.type IN (\'in\', \'out\') ORDER BY m.id_movement') ?: [];
        $register = JtpRegisters::get($session['id_register']);

        return [
            'session' => $session,
            'register' => $register ? $register['name'] : '',
            'employee_open' => JtpEmployees::name($session['id_employee_open']),
            'employee_close' => $session['id_employee_close'] ? JtpEmployees::name($session['id_employee_close']) : '',
            'sales_count' => (int) $sales['n'],
            'sales_total' => JtpTools::round($sales['total']),
            'by_method' => array_map(function ($r) {
                return ['code' => $r['code'], 'name' => $r['name'], 'amount' => JtpTools::round($r['amount']), 'n' => (int) $r['n']];
            }, $byMethod),
            'returns_count' => (int) $returns['n'],
            'returns_total' => JtpTools::round($returns['total']),
            'returns_by_method' => array_map(function ($r) {
                return ['code' => $r['code'], 'name' => $r['name'], 'amount' => JtpTools::round($r['amount']), 'n' => (int) $r['n']];
            }, $returnsByMethod),
            'cash' => [
                'opening' => JtpTools::round($m['open']),
                'sales' => JtpTools::round($m['sale']),
                'refunds' => JtpTools::round($m['refund']),
                'in' => JtpTools::round($m['in']),
                'out' => JtpTools::round($m['out']),
                'expected' => JtpTools::round($expected),
            ],
            'movements' => $movementList,
        ];
    }

    public static function close($idSession, $idEmployee, $counted, $note = '')
    {
        $session = self::get($idSession);
        if (!$session || $session['status'] !== 'open') {
            throw new JtpException('La caja no está abierta');
        }
        $summary = self::summary($idSession);
        $expected = $summary['cash']['expected'];
        Db::getInstance()->update('jtp_session', [
            'id_employee_close' => (int) $idEmployee,
            'expected_cash' => (float) $expected,
            'counted_cash' => (float) $counted,
            'difference' => (float) JtpTools::round((float) $counted - $expected),
            'status' => 'closed',
            'note' => pSQL((string) $note),
            'date_close' => JtpTools::now(),
        ], 'id_session = ' . (int) $idSession);
        $summary = self::summary($idSession);
        Hook::exec('actionJtpSessionClosed', ['session' => $summary['session'], 'summary' => $summary]);

        return $summary;
    }
}
