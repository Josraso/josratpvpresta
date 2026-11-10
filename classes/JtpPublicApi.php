<?php
/**
 * josratpvpresta - API pública estable para addons.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Funciones que la base garantiza a los addons (no cambian entre versiones menores).
 */
class JtpPublicApi
{
    public function version()
    {
        return Josratpvpresta::VERSION;
    }

    public function sale($idSale)
    {
        return JtpSaleService::getSale($idSale);
    }

    public function saleByOrder($idOrder)
    {
        return JtpSaleService::getByOrder($idOrder);
    }

    public function saleReturn($idReturn)
    {
        return JtpSaleService::getReturn($idReturn);
    }

    public function ticket($idSale)
    {
        return JtpTicket::sale($idSale);
    }

    public function register($idRegister)
    {
        return JtpRegisters::get($idRegister);
    }

    public function queue($addon, $action, array $payload = [], $refType = '', $refId = 0)
    {
        return JtpQueue::push($addon, $action, $payload, $refType, $refId);
    }

    public function queueStatus($addon, $refType, $refId)
    {
        return JtpQueue::findByRef($addon, $refType, $refId);
    }

    public function nextNumber($series)
    {
        return JtpCounter::next($series);
    }

    public function paymentMethods()
    {
        return JtpRegisters::paymentMethods(false);
    }

    public function isDefaultCustomer($idCustomer)
    {
        return JtpCustomerService::isDefault($idCustomer);
    }
}
