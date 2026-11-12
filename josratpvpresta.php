<?php
/**
 * josratpvpresta - TPV (punto de venta en tienda física) para PrestaShop 1.7.8, 8 y 9.
 *
 * Módulo base funcional por sí solo y ampliable con addons (módulos independientes
 * que se enganchan a los hooks actionJtp* / displayJtp*). Ver ADDONS.md.
 *
 * @author    Jose
 * @license   Propietaria
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/JosratpvprestaUpdater.php';

$jtpClasses = [
    'JtpException', 'JtpTools', 'JtpAddons', 'JtpCounter', 'JtpQueue', 'JtpAuth', 'JtpEmployees',
    'JtpRegisters', 'JtpSessions', 'JtpCatalog', 'JtpCustomerService', 'JtpCartService',
    'JtpSaleService', 'JtpTicket', 'JtpInstaller', 'JtpAdmin', 'JtpPublicApi', 'JtpFiscal', 'JtpPurge', 'JtpStates',
];
foreach ($jtpClasses as $jtpClass) {
    if (!class_exists($jtpClass, false)) {
        require_once __DIR__ . '/classes/' . $jtpClass . '.php';
    }
}

class Josratpvpresta extends PaymentModule
{
    const VERSION = '1.2.5';

    /** Lo usa PrestaShop para el transportista de módulo "Venta en tienda (TPV)" */
    public $id_carrier;

    public function __construct()
    {
        $this->name = 'josratpvpresta';
        $this->tab = 'payments_gateways';
        $this->version = self::VERSION;
        $this->author = 'Jose';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];
        $this->controllers = ['pos', 'api', 'cron'];
        $this->currencies = false;

        parent::__construct();

        $this->displayName = 'TPV tienda física (josratpvpresta)';
        $this->description = 'Punto de venta para tienda física integrado con PrestaShop: ventas, variantes, caja, tickets con QZ Tray, devoluciones y base para addons.';
        $this->confirmUninstall = '¿Desinstalar el TPV? Las ventas, cajas y tickets se conservan en la base de datos.';
        // Reparación de la base de datos si una actualización no llegó a aplicarse (una vez por versión)
        if ($this->id && Configuration::getGlobalValue('JTP_SCHEMA') !== self::VERSION) {
            try {
                if (JtpInstaller::ensureSchema()) {
                    Configuration::updateGlobalValue('JTP_SCHEMA', self::VERSION);
                }
            } catch (Exception $e) {
                // Se reintentará en la siguiente carga
            }
        }
    }

    // ================================================================== instalación

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        $installer = new JtpInstaller($this);
        if (!$installer->install()) {
            return false;
        }
        foreach (JtpInstaller::moduleHooks() as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return $this->getUpdater()->registerHooks();
    }

    public function uninstall()
    {
        // Aviso al servidor de actualizaciones (no impide desinstalar si no responde)
        $this->getUpdater()->notifyUninstall();

        $installer = new JtpInstaller($this);
        $installer->uninstall(false);
        Configuration::deleteByName('JOSRATPVPRESTA_UPD_TS');
        Configuration::deleteByName('JOSRATPVPRESTA_UPD_INFO');
        Configuration::deleteByName('JOSRATPVPRESTA_UPD_SU');
        Configuration::deleteByName('JOSRATPVPRESTA_UPD_LT');

        return parent::uninstall();
    }

    // ================================================================== actualizaciones

    public function getUpdater()
    {
        return new JosratpvprestaUpdater($this);
    }

    /** Aviso diario al servidor de actualizaciones (solo en el back-office) */
    public function hookActionAdminControllerSetMedia($params)
    {
        $this->getUpdater()->maybePing();
    }

    /** PS 8.1+: la versión nueva aparece en Gestor de módulos > Actualizaciones */
    public function hookActionListModules($params)
    {
        return $this->getUpdater()->getNativeModuleList();
    }

    public function addError($msg)
    {
        $this->_errors[] = $msg;
    }

    // ================================================================== configuración

    public function getContent()
    {
        $updater = $this->getUpdater();
        $output = $updater->processRequest();

        $admin = new JtpAdmin($this);
        $formularios = $admin->render();

        $info = $updater->getInfo();
        $panel = $updater->renderPanel();

        return !empty($info['update_available'])
            ? $output . $panel . $formularios
            : $output . $formularios . $panel;
    }

    // ================================================================== transportista TPV

    /**
     * Coste del transportista "Venta en tienda": 0 dentro del TPV y false (no disponible)
     * en la tienda online, así nunca se ofrece a los clientes web.
     */
    public function getOrderShippingCost($params, $shipping_cost)
    {
        return $this->isPosCart($params) ? 0 : false;
    }

    public function getOrderShippingCostExternal($params)
    {
        return $this->isPosCart($params) ? 0 : false;
    }

    protected function isPosCart($cart)
    {
        if (JtpTools::$posRequest) {
            return true;
        }
        if ($cart instanceof Cart && $cart->id) {
            return (bool) Db::getInstance()->getValue('SELECT id_cart FROM ' . JtpTools::t('jtp_cart') . ' WHERE id_cart = ' . (int) $cart->id);
        }

        return false;
    }

    // ================================================================== hooks de PrestaShop

    public function hookModuleRoutes()
    {
        $rule = trim((string) Configuration::get('JTP_ROUTE'), '/') ?: 'tpv';

        return [
            'module-josratpvpresta-pos' => [
                'controller' => 'pos',
                'rule' => $rule,
                'keywords' => [],
                'params' => ['fc' => 'module', 'module' => $this->name, 'controller' => 'pos'],
            ],
        ];
    }

    /**
     * Dentro de validateOrder(): registra la venta TPV con su número correlativo, o
     * pone referencia numérica a los pedidos web si está activado.
     */
    public function hookActionValidateOrder($params)
    {
        if (empty($params['order']) || !($params['order'] instanceof Order)) {
            return;
        }
        /** @var Order $order */
        $order = $params['order'];
        if ($order->module === $this->name && JtpTools::$checkout && (int) $order->id_cart === (int) JtpTools::$checkout['id_cart']) {
            $svc = new JtpSaleService($this);
            $svc->recordSale($order);

            return;
        }
        $webMode = $order->module !== $this->name ? JtpTools::webRefMode() : 'none';
        if ($webMode !== 'none') {
            static $done = [];
            $idCart = (int) $order->id_cart;
            if (!isset($done[$idCart])) {
                if ($webMode === 'series') {
                    $num = JtpCounter::next(JtpTools::cfg('JTP_WEB_SERIES', 'W'));
                    $done[$idCart] = $num['formatted'];
                } else {
                    $done[$idCart] = (string) JtpTools::cartFirstOrderId($order);
                }
                JtpTools::setOrderReference($order, $done[$idCart]);
            } else {
                $order->reference = $done[$idCart];
            }
        }
    }

    /**
     * Mientras se valida un pedido del TPV no salen emails de PrestaShop (confirmación, etc.).
     */
    public function hookActionEmailSendBefore($params)
    {
        if (JtpTools::$blockMails && (!isset($params['template']) || $params['template'] !== 'jtp_ticket')) {
            return false;
        }

        return true;
    }

    public function hookDisplayAdminOrderSide($params)
    {
        $idOrder = isset($params['id_order']) ? (int) $params['id_order'] : 0;
        if (!$idOrder) {
            return '';
        }
        $sale = JtpSaleService::getByOrder($idOrder);
        if (!$sale) {
            return '';
        }
        $register = JtpRegisters::get($sale['id_register']);
        $this->context->smarty->assign([
            'jtp_sale' => $sale,
            'jtp_register' => $register ? $register['name'] : '',
            'jtp_employee' => JtpEmployees::name($sale['id_employee']),
            'jtp_returns' => JtpSaleService::returnsOfSale($sale['id_sale']),
            'jtp_addons_html' => Hook::exec('displayJtpAdminSale', ['sale' => $sale]),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/order_side.tpl');
    }

    // ================================================================== utilidades públicas para addons

    /**
     * API estable para addons. Ejemplo:
     *   Module::getInstanceByName('josratpvpresta')->api()->sale(12);
     */
    public function api()
    {
        return new JtpPublicApi();
    }
}
