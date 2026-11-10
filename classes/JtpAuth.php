<?php
/**
 * josratpvpresta - autenticación del TPV.
 *
 *  - Dispositivo: se autoriza abriendo el TPV desde el back office (enlace firmado de 5 min).
 *    Queda una cookie firmada de larga duración (jtp_dev) y un registro en jtp_device.
 *  - Sesión de empleado: cookie firmada (jtp_ses) con empleado, caja, caducidad y token CSRF.
 *  - En un dispositivo autorizado se puede cambiar de empleado con PIN (con bloqueo tras 5 fallos).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JtpAuth
{
    const COOKIE_DEVICE = 'jtp_dev';
    const COOKIE_SESSION = 'jtp_ses';
    const MAX_FAILS = 5;
    const LOCK_MINUTES = 5;

    protected static function secret()
    {
        return (string) Configuration::getGlobalValue('JTP_SECRET');
    }

    public static function sign(array $data)
    {
        $payload = rtrim(strtr(base64_encode(JtpTools::jsonEncode($data)), '+/', '-_'), '=');

        return $payload . '.' . hash_hmac('sha256', $payload, self::secret());
    }

    public static function verify($token)
    {
        $token = (string) $token;
        $pos = strrpos($token, '.');
        if ($pos === false) {
            return null;
        }
        $payload = substr($token, 0, $pos);
        $sig = substr($token, $pos + 1);
        if (!hash_equals(hash_hmac('sha256', $payload, self::secret()), $sig)) {
            return null;
        }
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        if (!is_array($data) || (isset($data['x']) && (int) $data['x'] < time())) {
            return null;
        }

        return $data;
    }

    protected static function setCookie($name, $value, $expire)
    {
        $secure = Tools::usingSecureMode();
        $path = __PS_BASE_URI__;
        if (PHP_VERSION_ID >= 70300) {
            setcookie($name, $value, [
                'expires' => $expire,
                'path' => $path,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie($name, $value, $expire, $path . '; samesite=Lax', '', $secure, true);
        }
        $_COOKIE[$name] = $value;
    }

    /**
     * Enlace firmado que genera el back office para abrir el TPV.
     */
    public static function backOfficeLink($idEmployee, $idRegister = 0)
    {
        $token = self::sign([
            'k' => 'bo',
            'e' => (int) $idEmployee,
            'r' => (int) $idRegister,
            'x' => time() + 300,
            'n' => bin2hex(random_bytes(6)),
        ]);

        return Context::getContext()->link->getModuleLink('josratpvpresta', 'pos', ['auth' => $token]);
    }

    /**
     * Procesa el enlace del back office: autoriza el dispositivo e inicia sesión.
     */
    public static function consumeBackOfficeToken($token)
    {
        $data = self::verify($token);
        if (!$data || !isset($data['k']) || $data['k'] !== 'bo') {
            return false;
        }
        $employee = new Employee((int) $data['e']);
        if (!Validate::isLoadedObject($employee) || !$employee->active) {
            return false;
        }
        $device = self::currentDevice();
        if (!$device) {
            $idDevice = bin2hex(random_bytes(16));
            Db::getInstance()->insert('jtp_device', [
                'id_device' => pSQL($idDevice),
                'name' => pSQL(Tools::substr((string) Tools::getRemoteAddr() . ' ' . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''), 0, 128)),
                'id_shop' => JtpTools::shopId(),
                'id_employee_auth' => (int) $employee->id,
                'date_add' => JtpTools::now(),
                'last_seen' => JtpTools::now(),
            ]);
            $expire = time() + 86400 * 365;
            self::setCookie(self::COOKIE_DEVICE, self::sign(['k' => 'dev', 'd' => $idDevice, 'x' => $expire]), $expire);
        }
        self::startSession((int) $employee->id, (int) $data['r']);

        return true;
    }

    /**
     * @return array|null fila de jtp_device si la cookie es válida y no está revocado
     */
    public static function currentDevice()
    {
        if (empty($_COOKIE[self::COOKIE_DEVICE])) {
            return null;
        }
        $data = self::verify($_COOKIE[self::COOKIE_DEVICE]);
        if (!$data || !isset($data['d']) || $data['k'] !== 'dev') {
            return null;
        }
        $row = Db::getInstance()->getRow('SELECT * FROM ' . JtpTools::t('jtp_device') . ' WHERE id_device = \'' . pSQL($data['d']) . '\'');
        if (!$row || (int) $row['revoked']) {
            return null;
        }

        return $row;
    }

    public static function startSession($idEmployee, $idRegister = 0)
    {
        $hours = max(1, (int) JtpTools::cfg('JTP_SESSION_HOURS', 14));
        $expire = time() + 3600 * $hours;
        $data = [
            'k' => 'ses',
            'e' => (int) $idEmployee,
            'r' => (int) $idRegister,
            'c' => bin2hex(random_bytes(16)),
            'x' => $expire,
        ];
        self::setCookie(self::COOKIE_SESSION, self::sign($data), $expire);

        return $data;
    }

    public static function setRegister($idRegister)
    {
        $s = self::session();
        if (!$s) {
            return false;
        }
        $s['r'] = (int) $idRegister;
        self::setCookie(self::COOKIE_SESSION, self::sign($s), (int) $s['x']);

        return true;
    }

    /**
     * @return array|null ['e' => id_employee, 'r' => id_register, 'c' => csrf, 'x' => expire]
     */
    public static function session()
    {
        if (empty($_COOKIE[self::COOKIE_SESSION])) {
            return null;
        }
        $data = self::verify($_COOKIE[self::COOKIE_SESSION]);
        if (!$data || $data['k'] !== 'ses') {
            return null;
        }
        // La sesión solo vale en un dispositivo autorizado
        if (!self::currentDevice()) {
            return null;
        }

        return $data;
    }

    public static function logout()
    {
        self::setCookie(self::COOKIE_SESSION, '', time() - 3600);
    }

    /**
     * Login con PIN en un dispositivo ya autorizado.
     *
     * @return array|string sesión o mensaje de error
     */
    public static function loginPin($idEmployee, $pin)
    {
        $device = self::currentDevice();
        if (!$device) {
            return 'Dispositivo no autorizado. Abre el TPV desde el back office.';
        }
        $db = Db::getInstance();
        if ($device['locked_until'] && strtotime($device['locked_until']) > time()) {
            return 'Demasiados intentos. Espera unos minutos.';
        }
        $emp = JtpEmployees::get((int) $idEmployee);
        $employee = new Employee((int) $idEmployee);
        $ok = $emp && $emp['enabled'] && $emp['pin'] !== '' && Validate::isLoadedObject($employee)
            && $employee->active && password_verify((string) $pin, $emp['pin']);
        if (!$ok) {
            $fails = (int) $device['fails'] + 1;
            $upd = ['fails' => $fails];
            if ($fails >= self::MAX_FAILS) {
                $upd['fails'] = 0;
                $upd['locked_until'] = date('Y-m-d H:i:s', time() + 60 * self::LOCK_MINUTES);
            }
            $db->update('jtp_device', $upd, 'id_device = \'' . pSQL($device['id_device']) . '\'');

            return 'PIN incorrecto';
        }
        $db->update('jtp_device', ['fails' => 0, 'last_seen' => JtpTools::now()], 'id_device = \'' . pSQL($device['id_device']) . '\'');
        $old = self::session();

        return self::startSession((int) $idEmployee, $old ? (int) $old['r'] : 0);
    }
}
