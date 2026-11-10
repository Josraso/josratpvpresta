<?php
/**
 * 1.1.7: impresora A4 por caja.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_7($module)
{
    if (JtpInstaller::ensureSchema()) {
        Configuration::updateGlobalValue('JTP_SCHEMA', '1.1.7');
    }

    return true;
}
