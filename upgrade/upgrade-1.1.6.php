<?php
/**
 * 1.1.6: margen superior del ticket y pitido al añadir productos.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_6($module)
{
    if (Configuration::get('JTP_TICKET_TOP_MM') === false) {
        Configuration::updateValue('JTP_TICKET_TOP_MM', 4);
    }
    if (Configuration::get('JTP_BEEP') === false) {
        Configuration::updateValue('JTP_BEEP', 1);
    }

    return true;
}
