<?php

/**
 * Class for handling session variables.
 */
class Session {
    
    /**
     * Whether or not the session system has been initialized.
     * @var boolean
     */
    private static $initialized = false;
    
    /**
     * The prefix that is used to store session variables.
     * @var string
     */
    private static $prefix = null;
    
    /**
     * Initializes the session system.
     */
    private static function initialize() {
        
        // Make sure it's not initialized already
        if (self::$initialized) {
            return;
        }
        
        // See if we were given a session id explicitly
        // If so we also need a matching token to allow it
        $setSid = false;
        if (Input::exists('_sid')) {
            session_id(Input::get('_sid'));
            $setSid = true;
        }
        
        // Start the default PHP session
        self::$prefix = crc32(APP_SALT) . '_';
        session_name('session');
        session_start();
        
        // Set the initialized flag
        self::$initialized = true;
        
        // Make sure the token is good before we allow
        // explicit session id setting
        if ($setSid) {
           Auth::checkToken();
        }
        
    }
    
    /**
     * Gets the id associated with this session.
     * @return string
     */
    public static function getSessionId() {
        self::initialize();
        return session_id();
    }
    
    /**
     * Returns the current IP address of the visitor.
     * @return string
     */
    public static function getIpAddress() {
        return filter_input(INPUT_SERVER, 'REMOTE_ADDR');
    }
    
    /**
     * Gets the token associated with this session.
     * Used to prevent CSRF attacks and to avoid old form submission.
     */
    public static function getToken() {
        self::initialize();
        $tokenKey = 'g_' . APP_SALT . 'token';
        if (!isset($_SESSION[$tokenKey]) || empty($_SESSION[$tokenKey])) {
            self::issueToken();
        }
        return $_SESSION[$tokenKey];
    }
    
     /**
     * Changes the current session token.
     */
    public static function issueToken() {
        self::initialize();
        $_SESSION['g_' . APP_SALT . 'token'] = Utils::generateRandomId();
    }
    
    /**
     * Determines whether or not the given key exists in the session.
     * @param string $key The key for the session variable.
     */
    public static function exists($key) {
        self::initialize();
        if (!isset($_SESSION[self::$prefix . $key])) {
            return false;
        }
        return true;
    }
    
    /**
     * Gets a specific session variable.
     * @param string $key The key for the session variable.
     * @param mixed $default The default value if the variable doesn't exist.
     * @return mixed
     */
    public static function get($key, $default = '') {
        self::initialize();
        if (!self::exists($key)) {
            return $default;
        }
        return $_SESSION[self::$prefix  . $key];
    }
    
    /**
     * Sets the value of a session variable.
     * @param string $key The key for the session variable.
     * @param mixed $value The new value to set.
     */
    public static function set($key, $value) {
        self::initialize();
        $_SESSION[self::$prefix  . $key] = $value;
    }
    
    /**
     * Deletes a variable from the session.
     * @param string $key The key of the variable to delete.
     */
    public static function delete($key) {
        self::initialize();
        if (self::exists($key)) {
            $_SESSION[APP_SALT . $key] = null;
            unset($_SESSION[self::$prefix  . $key]);
        }
    }
    
    /**
     * Destroys the current session.
     */
    public static function destroy() {
        self::initialize();
        session_destroy();
    }
    
    /**
     * Closes the session and forces a reinitilization next time it is called.
     */
    public static function close() {
        session_write_close();
        self::$initialized = false;
    }
    
}