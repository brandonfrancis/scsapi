<?php

/**
 * Handles the getting and setting of cookie variables.
 */
class Cookie {
    
    const COOKIE_EXPIRE_TIME = 8640000; // 100 days
    
    /**
     * Whether or not the cookie class has been initialized.
     * @var boolean 
     */
    private static $initialized = false;
    
    /**
     * The prefix that is being used to store cookie variables.
     * @var string
     */
    private static $prefix = null;

    /**
     * Initializes the cookie class.
     */
    private static function initialize() {
        if (self::$initialized) {
            return;
        }
        self::$prefix = crc32(APP_COOKIE_SALT) . '_';
    }
    
    /**
     * Gets a value from a cookie.
     * @param string $key The key of the variable.
     * @param string $default The default value to return.
     * @return string
     */
    public static function get($key, $default = '') {
        self::initialize();
        $value = filter_input(INPUT_COOKIE, self::$prefix . $key);
        if (isset($value)) {
            return $value;
        }
        return $default;
    }
    
    /**
     * Sets a value to a cookie.
     * @param string $key The key of the variable.
     * @param string $value The value of the variable.
     */
    public static function set($key, $value) {
        self::initialize();
        setcookie(self::$prefix . $key, $value, time() + self::COOKIE_EXPIRE_TIME, APP_RELATIVE_URL == '' ? '/' : APP_RELATIVE_URL);
    }

    /**
     * Deletes a cookie variable by setting it to nothing and expiring it.
     * @param string $key The key of the variable to delete.
     */
    public static function delete($key) {
        self::initialize();
        setcookie(self::$prefix . $key, '', time() - 3600, APP_RELATIVE_URL == '' ? '/' : APP_RELATIVE_URL);
    }
    
}
