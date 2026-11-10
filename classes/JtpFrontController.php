<?php
/**
 * josratpvpresta - base de los controladores del TPV (pantalla y API).
 * No usa el tema de la tienda, ignora el modo mantenimiento y las restricciones de país.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;
    public $ssl = true;
    public $display_column_left = false;
    public $display_column_right = false;

    /** @var Josratpvpresta */
    public $module;

    /** @var array|null sesión de empleado */
    protected $jtpSession;

    /** @var array|null caja seleccionada */
    protected $jtpRegister;

    public function init()
    {
        JtpTools::$posRequest = true;
        // Venta en tienda: el IVA es siempre el del país de la tienda. El carrito del TPV lleva como entrega la
        // dirección de la tienda (y como facturación la del cliente) y, solo en las peticiones del TPV,
        // PrestaShop calcula los impuestos con la de entrega (no cambia la configuración de la web).
        Configuration::set('PS_TAX_ADDRESS_TYPE', 'id_address_delivery');
        parent::init();
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: SAMEORIGIN');
        $this->loadSession();
    }

    protected function loadSession()
    {
        $this->jtpSession = JtpAuth::session();
        $this->jtpRegister = null;
        if ($this->jtpSession) {
            $employee = new Employee((int) $this->jtpSession['e']);
            if (!Validate::isLoadedObject($employee) || !$employee->active) {
                $this->jtpSession = null;

                return;
            }
            $this->context->employee = $employee;
            if ((int) $this->jtpSession['r']) {
                $reg = JtpRegisters::get((int) $this->jtpSession['r']);
                if ($reg && (int) $reg['active'] && (int) $reg['id_shop'] === JtpTools::shopId()) {
                    $this->jtpRegister = $reg;
                }
            }
            if (!$this->jtpRegister) {
                $regs = JtpRegisters::forShop();
                if (count($regs) === 1) {
                    $this->jtpRegister = $regs[0];
                    JtpAuth::setRegister((int) $regs[0]['id_register']);
                }
            }
        }
    }

    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    public function setMedia()
    {
        // Sin CSS/JS del tema: la pantalla del TPV carga los suyos
    }
}
