<?php
/**
 * josratpvpresta - procesa la cola de addons.
 * URL (ver Configuración > Cola): index.php?fc=module&module=josratpvpresta&controller=cron&token=XXXX
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'josratpvpresta/classes/JtpFrontController.php';

class JosratpvprestaCronModuleFrontController extends JtpFrontController
{
    protected $result = [];

    protected function loadSession()
    {
    }

    public function postProcess()
    {
        $token = (string) Tools::getValue('token');
        if ($token === '' || !hash_equals((string) Configuration::getGlobalValue('JTP_CRON_TOKEN'), $token)) {
            header('HTTP/1.1 403 Forbidden');
            $this->result = ['ok' => false, 'error' => 'Token no válido'];

            return;
        }
        JtpTools::$posRequest = false;
        $this->result = ['ok' => true, 'stats' => JtpQueue::process((int) Tools::getValue('limit', 50))];
    }

    public function initContent()
    {
    }

    public function display()
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->result);

        return true;
    }
}
