<?php
/**
 * josratpvpresta - excepción de negocio: su mensaje se muestra tal cual al usuario del TPV.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpException extends Exception
{
    /** @var array datos extra para el cliente (p.ej. ['needs_variant' => true]) */
    public $data = [];

    public function __construct($message, array $data = [], $code = 0)
    {
        parent::__construct($message, $code);
        $this->data = $data;
    }
}
