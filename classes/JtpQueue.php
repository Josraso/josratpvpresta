<?php
/**
 * josratpvpresta - cola de trabajos para addons (envíos a sistemas externos, etc.)
 *
 * Un addon encola:   JtpQueue::push('miaddon', 'enviar_albaran', ['id_sale' => 5], 'sale', 5);
 * y procesa en el hook actionJtpQueueProcess:
 *   public function hookActionJtpQueueProcess($params) {
 *       $job = $params['job'];            // fila de la cola (payload ya decodificado)
 *       ...
 *       $params['result']['ok'] = true;   // o false + ['error' => 'mensaje']
 *   }
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpQueue
{
    const PENDING = 'pending';
    const DONE = 'done';
    const ERROR = 'error';
    const RUNNING = 'running';

    public static function push($addon, $action, array $payload = [], $refType = '', $refId = 0, $delaySeconds = 0)
    {
        $now = JtpTools::now();
        Db::getInstance()->insert('jtp_queue', [
            'id_shop' => JtpTools::shopId(),
            'addon' => pSQL($addon),
            'action' => pSQL($action),
            'ref_type' => pSQL($refType),
            'ref_id' => (int) $refId,
            'payload' => pSQL(JtpTools::jsonEncode($payload), true),
            'status' => self::PENDING,
            'attempts' => 0,
            'next_try' => date('Y-m-d H:i:s', time() + (int) $delaySeconds),
            'date_add' => $now,
            'date_upd' => $now,
        ]);

        return (int) Db::getInstance()->Insert_ID();
    }

    public static function get($idJob)
    {
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_queue') . ' WHERE id_job = ' . (int) $idJob);
        if ($row) {
            $row['payload'] = JtpTools::jsonDecode($row['payload']);
            $row['result'] = JtpTools::jsonDecode($row['result']);
        }

        return $row ?: null;
    }

    /**
     * Estado del último trabajo de un addon para una referencia (p.ej. una venta).
     */
    public static function findByRef($addon, $refType, $refId)
    {
        return Db::getInstance()->executeS('SELECT * FROM ' . JtpTools::t('jtp_queue') . '
            WHERE addon = \'' . pSQL($addon) . '\' AND ref_type = \'' . pSQL($refType) . '\' AND ref_id = ' . (int) $refId . '
            ORDER BY id_job DESC') ?: [];
    }

    public static function retry($idJob)
    {
        return Db::getInstance()->update('jtp_queue', [
            'status' => self::PENDING,
            'next_try' => JtpTools::now(),
            'date_upd' => JtpTools::now(),
        ], 'id_job = ' . (int) $idJob);
    }

    public static function delete($idJob)
    {
        return Db::getInstance()->delete('jtp_queue', 'id_job = ' . (int) $idJob);
    }

    /**
     * Procesa trabajos pendientes. Devuelve ['processed' => n, 'ok' => n, 'failed' => n]
     */
    public static function process($limit = 20, $onlyId = 0)
    {
        $db = Db::getInstance();
        $max = (int) JtpTools::cfg('JTP_QUEUE_MAX_ATTEMPTS', 10);
        // Trabajos que se quedaron "en curso" (proceso cortado por un error fatal o un timeout): se reintentan
        $db->execute('UPDATE ' . JtpTools::t('jtp_queue') . ' SET status = \'' . self::ERROR . '\', attempts = attempts + 1,
            last_error = \'Interrumpido (el proceso se cortó)\', next_try = \'' . pSQL(JtpTools::now()) . '\'
            WHERE status = \'' . self::RUNNING . '\' AND date_upd < \'' . pSQL(date('Y-m-d H:i:s', time() - 1800)) . '\'');
        $where = $onlyId ? 'id_job = ' . (int) $onlyId
            : 'status IN (\'' . self::PENDING . '\', \'' . self::ERROR . '\') AND attempts < ' . $max . ' AND next_try <= \'' . pSQL(JtpTools::now()) . '\'';
        $jobs = $db->executeS('SELECT id_job FROM ' . JtpTools::t('jtp_queue') . ' WHERE ' . $where . ' ORDER BY id_job ASC LIMIT ' . (int) $limit) ?: [];
        $stats = ['processed' => 0, 'ok' => 0, 'failed' => 0];
        // Límite de tiempo por pasada: el cron suele llamarse por URL y el servidor corta a los 30-60 s
        $t0 = microtime(true);
        $budget = (int) JtpTools::cfg('JTP_QUEUE_SECONDS', 25);
        foreach ($jobs as $j) {
            if (!$onlyId && microtime(true) - $t0 > $budget) {
                break;
            }
            // Bloqueo optimista: solo un proceso toma el trabajo
            $taken = $db->execute('UPDATE ' . JtpTools::t('jtp_queue') . ' SET status = \'' . self::RUNNING . '\', date_upd = \'' . pSQL(JtpTools::now()) . '\'
                WHERE id_job = ' . (int) $j['id_job'] . ' AND status <> \'' . self::RUNNING . '\'');
            if (!$taken || !$db->Affected_Rows()) {
                continue;
            }
            $job = self::get($j['id_job']);
            $result = ['ok' => false, 'error' => 'Ningún addon procesó este trabajo (' . $job['addon'] . ')', 'data' => []];
            $idModule = (int) Module::getModuleIdByName($job['addon']);
            try {
                if ($idModule && Module::isEnabled($job['addon'])) {
                    Hook::exec('actionJtpQueueProcess', ['job' => $job, 'result' => &$result], $idModule);
                }
            } catch (Throwable $e) {
                // Throwable: también errores de tipo de PHP 7+, para que un fallo no corte el cron entero
                $result = ['ok' => false, 'error' => $e->getMessage(), 'data' => []];
            }
            $attempts = (int) $job['attempts'] + 1;
            ++$stats['processed'];
            if (!empty($result['ok'])) {
                ++$stats['ok'];
                $db->update('jtp_queue', [
                    'status' => self::DONE,
                    'attempts' => $attempts,
                    'result' => pSQL(JtpTools::jsonEncode(isset($result['data']) ? $result['data'] : []), true),
                    'last_error' => '',
                    'date_upd' => JtpTools::now(),
                ], 'id_job = ' . (int) $job['id_job']);
            } else {
                ++$stats['failed'];
                // Reintento con espera creciente: 1, 2, 4, 8... minutos (máx 6 h)
                $wait = min(21600, 60 * pow(2, $attempts - 1));
                $db->update('jtp_queue', [
                    'status' => self::ERROR,
                    'attempts' => $attempts,
                    'last_error' => pSQL(isset($result['error']) ? (string) $result['error'] : 'Error', true),
                    'next_try' => date('Y-m-d H:i:s', time() + (int) $wait),
                    'date_upd' => JtpTools::now(),
                ], 'id_job = ' . (int) $job['id_job']);
            }
        }
        // Liberar trabajos atascados en "running" más de 15 minutos
        $db->execute('UPDATE ' . JtpTools::t('jtp_queue') . ' SET status = \'' . self::ERROR . '\', last_error = \'Tiempo agotado\'
            WHERE status = \'' . self::RUNNING . '\' AND date_upd < \'' . pSQL(date('Y-m-d H:i:s', time() - 900)) . '\'');

        return $stats;
    }
}
