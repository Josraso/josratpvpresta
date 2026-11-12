<?php
/**
 * josratpvpresta 1.2.5: aviso de licencia: el cartel con el botón sale desde la primera vez.
 *
 * @author josra
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_5($module)
{
    return $module->getUpdater()->registerHooks();
}
