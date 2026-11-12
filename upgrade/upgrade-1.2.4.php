<?php
/**
 * josratpvpresta 1.2.4: licencia decidida desde el panel del servidor de actualizaciones.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_4($module)
{
    return $module->getUpdater()->registerHooks();
}
