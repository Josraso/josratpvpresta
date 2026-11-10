<?php
/**
 * josratpvpresta - numeración propia SERIE-AÑO-NNNNNN con reinicio anual.
 *
 * Es atómico: usa INSERT ... ON DUPLICATE KEY UPDATE con LAST_INSERT_ID(),
 * por lo que dos cajas cobrando a la vez nunca obtienen el mismo número.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpCounter
{
    /**
     * @return array ['series' => , 'year' => , 'number' => , 'formatted' => ]
     */
    public static function next($series, $year = null)
    {
        $year = $year ? (int) $year : (int) date('Y');
        $series = Tools::substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $series), 0, 12);
        if ($series === '') {
            $series = 'T';
        }
        $db = Db::getInstance();
        $db->execute('INSERT INTO ' . JtpTools::t('jtp_counter') . ' (`series`, `year`, `last_number`)
            VALUES (\'' . pSQL($series) . '\', ' . $year . ', LAST_INSERT_ID(1))
            ON DUPLICATE KEY UPDATE `last_number` = LAST_INSERT_ID(`last_number` + 1)');
        $number = (int) $db->getValue('SELECT LAST_INSERT_ID()', false);

        return [
            'series' => $series,
            'year' => $year,
            'number' => $number,
            'formatted' => JtpTools::formatNumber($series, $year, $number),
        ];
    }

    public static function peek($series, $year = null)
    {
        $year = $year ? (int) $year : (int) date('Y');

        return (int) Db::getInstance()->getValue('SELECT `last_number` FROM ' . JtpTools::t('jtp_counter') . '
            WHERE `series` = \'' . pSQL($series) . '\' AND `year` = ' . $year, false);
    }
}
