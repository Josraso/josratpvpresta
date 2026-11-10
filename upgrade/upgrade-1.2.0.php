<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.2.0: dirección del cliente por defecto elegible y servidor de actualizaciones.
 */
function upgrade_module_1_2_0($module)
{
    if (Configuration::get('JTP_DEFAULT_ADDRESS') === false) {
        Configuration::updateValue('JTP_DEFAULT_ADDRESS', 0);
    }

    return $module->getUpdater()->registerHooks();
}
