<?php
/**
 * josratpvpresta - registro de addons.
 *
 * Un addon es un módulo de PrestaShop independiente que se engancha al hook
 * actionJtpAddonRegister y devuelve su ficha:
 *   ['name' => 'Mi addon', 'version' => '1.0.0', 'requires' => '1.0.0', 'description' => '...']
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpAddons
{
    protected static $cache = null;

    /**
     * @return array lista de addons con estado de compatibilidad
     */
    public static function all()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $list = [];
        foreach (JtpTools::collect('actionJtpAddonRegister', ['base_version' => Josratpvpresta::VERSION]) as $module => $info) {
            $requires = isset($info['requires']) ? (string) $info['requires'] : '1.0.0';
            $list[$module] = [
                'module' => $module,
                'name' => isset($info['name']) ? (string) $info['name'] : $module,
                'version' => isset($info['version']) ? (string) $info['version'] : '?',
                'requires' => $requires,
                'description' => isset($info['description']) ? (string) $info['description'] : '',
                'compatible' => version_compare(Josratpvpresta::VERSION, $requires, '>='),
            ];
        }
        self::$cache = $list;

        return $list;
    }

    /**
     * Addons que hay en disco (carpetas modules/josratpvpresta_*), instalados o no, activos o no.
     * Para los activos se usa su ficha (actionJtpAddonRegister); para el resto, los datos del módulo.
     *
     * @return array nombre => ['module', 'name', 'description', 'version', 'db_version', 'requires',
     *                         'installed', 'enabled', 'compatible', 'upgrade', 'dependencies', 'missing']
     */
    public static function disk()
    {
        $registered = self::all();
        $out = [];
        foreach (glob(_PS_MODULE_DIR_ . 'josratpvpresta_*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (!preg_match('/^josratpvpresta_[a-z0-9_]+$/', $name) || !is_file($dir . '/' . $name . '.php')) {
                continue;
            }
            $row = [
                'module' => $name, 'name' => $name, 'description' => '', 'version' => '?', 'db_version' => '',
                'requires' => '', 'installed' => false, 'enabled' => false, 'compatible' => true,
                'upgrade' => false, 'dependencies' => [], 'missing' => [], 'broken' => '',
            ];
            $row['db_version'] = (string) Db::getInstance()->getValue('SELECT version FROM `' . _DB_PREFIX_ . "module` WHERE name = '" . pSQL($name) . "'");
            $row['installed'] = $row['db_version'] !== '';
            $row['enabled'] = $row['installed'] && Module::isEnabled($name);
            try {
                $m = Module::getInstanceByName($name);
                if ($m) {
                    // "TPV · Taller" -> "Taller" (como en la ficha que da el addon cuando está activo)
                    $row['name'] = (string) preg_replace('/^TPV\s*·\s*/u', '', (string) $m->displayName);
                    $row['description'] = (string) $m->description;
                    $row['version'] = (string) $m->version;
                    $row['dependencies'] = array_values((array) $m->dependencies);
                    $const = get_class($m) . '::REQUIRES';
                    if (defined($const)) {
                        $row['requires'] = (string) constant($const);
                    }
                } else {
                    $row['broken'] = 'No se puede cargar el módulo';
                }
            } catch (Throwable $e) {
                $row['broken'] = $e->getMessage();
            }
            if (isset($registered[$name])) {
                // Ficha del propio addon (activo)
                $r = $registered[$name];
                $row['name'] = $r['name'];
                $row['description'] = $r['description'] ?: $row['description'];
                $row['requires'] = $r['requires'];
            }
            if ($row['requires'] !== '') {
                $row['compatible'] = version_compare(Josratpvpresta::VERSION, $row['requires'], '>=');
            }
            foreach ($row['dependencies'] as $dep) {
                if (!Module::isInstalled($dep) || !Module::isEnabled($dep)) {
                    $row['missing'][] = $dep;
                }
            }
            $row['upgrade'] = $row['installed'] && $row['version'] !== '?' && version_compare($row['version'], $row['db_version'], '>');
            $out[$name] = $row;
        }
        ksort($out);

        return $out;
    }

    /**
     * Instalar, activar, desactivar o actualizar un addon desde la pestaña Addons de la base.
     * Usa el gestor de módulos de PrestaShop (como en Módulos > Gestor de módulos) y, si no está, la API clásica.
     *
     * @return string '' si fue bien, o el error
     */
    public static function action($name, $action)
    {
        $disk = self::disk();
        if (!isset($disk[$name])) {
            return 'Addon no encontrado en la carpeta de módulos.';
        }
        $a = $disk[$name];
        if ($a['broken'] !== '') {
            return 'El módulo no se puede cargar: ' . $a['broken'];
        }
        if (in_array($action, ['install', 'enable']) && $a['missing']) {
            return 'Antes hay que instalar y activar: ' . implode(', ', $a['missing']) . '.';
        }
        try {
            if ($action === 'upgrade') {
                $m = Module::getInstanceByName($name);
                if (Module::initUpgradeModule($m)) {
                    $r = $m->runUpgradeModule();
                    if (!empty($r['version_fail'])) {
                        return 'Falló la actualización a ' . $r['version_fail'] . ' (el módulo se ha desactivado). Revisa el registro de PrestaShop.';
                    }
                }
                // Sin scripts de actualización pendientes: la versión del disco pasa a ser la instalada
                Module::upgradeModuleVersion($name, $m->version);

                return '';
            }
            $manager = null;
            if (class_exists('PrestaShop\\PrestaShop\\Core\\Addon\\Module\\ModuleManagerBuilder')) {
                try {
                    $manager = \PrestaShop\PrestaShop\Core\Addon\Module\ModuleManagerBuilder::getInstance()->build();
                } catch (Throwable $e) {
                    $manager = null;
                }
            }
            if ($manager && in_array($action, ['install', 'enable', 'disable'])) {
                $ok = $manager->{$action}($name);
                if (!$ok) {
                    $err = method_exists($manager, 'getError') ? (string) $manager->getError($name) : '';

                    return $err !== '' ? $err : 'PrestaShop no pudo completar la acción.';
                }

                return '';
            }
            $m = Module::getInstanceByName($name);
            switch ($action) {
                case 'install':
                    $ok = $m->install();
                    break;
                case 'enable':
                    $ok = $m->enable();
                    break;
                case 'disable':
                    $m->disable();
                    $ok = true;
                    break;
                default:
                    return 'Acción no válida.';
            }
            if (!$ok) {
                $errors = method_exists($m, 'getErrors') ? (array) $m->getErrors() : [];

                return $errors ? implode(' ', $errors) : 'PrestaShop no pudo completar la acción.';
            }
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return '';
    }

    public static function isCompatible($module)
    {
        $all = self::all();

        return isset($all[$module]) && $all[$module]['compatible'];
    }

    /**
     * JS y CSS que los addons quieren cargar en la pantalla de venta.
     */
    public static function posAssets()
    {
        $js = [];
        $css = [];
        foreach (JtpTools::collect('actionJtpPosAssets') as $module => $assets) {
            if (!self::isCompatible($module)) {
                continue;
            }
            foreach (isset($assets['js']) ? (array) $assets['js'] : [] as $u) {
                $js[] = (string) $u;
            }
            foreach (isset($assets['css']) ? (array) $assets['css'] : [] as $u) {
                $css[] = (string) $u;
            }
        }

        return ['js' => $js, 'css' => $css];
    }
}
