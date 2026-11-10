<?php
/**
 * josratpvpresta - pantalla de venta (aplicación a pantalla completa).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once _PS_MODULE_DIR_ . 'josratpvpresta/classes/JtpFrontController.php';

class JosratpvprestaPosModuleFrontController extends JtpFrontController
{
    protected $html = '';

    public function postProcess()
    {
        $auth = Tools::getValue('auth');
        if ($auth) {
            if (!JtpAuth::consumeBackOfficeToken($auth)) {
                $this->html = $this->renderMessage('Enlace caducado o no válido. Vuelve a abrir el TPV desde el back office (Módulos > TPV > Configurar).');

                return;
            }
            Tools::redirect($this->context->link->getModuleLink('josratpvpresta', 'pos'));
        }
        if (Tools::getValue('logout')) {
            JtpAuth::logout();
            Tools::redirect($this->context->link->getModuleLink('josratpvpresta', 'pos'));
        }
    }

    public function initContent()
    {
        if ($this->html !== '') {
            return;
        }
        $device = JtpAuth::currentDevice();
        if (!$device) {
            $this->html = $this->renderMessage('Este dispositivo no está autorizado. Abre el TPV desde el back office: Módulos > TPV tienda física > Configurar > "Abrir TPV".');

            return;
        }
        $module = $this->module;
        $base = $module->getPathUri();
        $v = Josratpvpresta::VERSION;
        $assets = JtpAddons::posAssets();
        $session = $this->jtpSession;
        $config = [
            'version' => $v,
            'api' => $this->context->link->getModuleLink('josratpvpresta', 'api'),
            'posUrl' => $this->context->link->getModuleLink('josratpvpresta', 'pos'),
            'csrf' => $session ? $session['c'] : '',
            'logged' => (bool) $session,
            'shopName' => Configuration::get('PS_SHOP_NAME'),
            'currency' => [
                'iso' => $this->context->currency->iso_code,
                'sign' => $this->context->currency->sign,
            ],
            'locale' => str_replace('_', '-', $this->context->language->locale ?: $this->context->language->language_code),
        ];
        $this->context->smarty->assign([
            'jtp_config_json' => JtpTools::jsonEncode($config),
            'jtp_css' => [$base . 'views/css/pos.css?v=' . $v],
            'jtp_js' => [
                $base . 'views/js/lib/qrcode.js?v=' . $v,
                $base . 'views/js/lib/qz-tray.js?v=' . $v,
                $base . 'views/js/pos-core.js?v=' . $v,
                $base . 'views/js/pos-print.js?v=' . $v,
                $base . 'views/js/pos-ui.js?v=' . $v,
            ],
            'jtp_addon_css' => $assets['css'],
            'jtp_addon_js' => $assets['js'],
            'jtp_shop_name' => Configuration::get('PS_SHOP_NAME'),
            'jtp_favicon' => $base . 'logo.png',
        ]);
        $this->html = $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'josratpvpresta/views/templates/front/pos.tpl');
    }

    protected function renderMessage($msg)
    {
        $this->context->smarty->assign(['jtp_message' => $msg, 'jtp_shop_name' => Configuration::get('PS_SHOP_NAME')]);

        return $this->context->smarty->fetch(_PS_MODULE_DIR_ . 'josratpvpresta/views/templates/front/message.tpl');
    }

    public function display()
    {
        header('Content-Type: text/html; charset=utf-8');
        echo $this->html;

        return true;
    }
}
