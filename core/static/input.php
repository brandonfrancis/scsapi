<?php

/**
 * Class dealing with getting input and making sure it's safe to use.
 */
class Input {
    
    /**
     * Whether or not the input system is initialized.
     * @var boolean
     */
    private static $initialized = false;
    
    /*
     * Instance variables for storing the variables
     */
    private static $getVariables = null;
    private static $postVariables = null;
    private static $runtimeVariables = null;
    
    /**
     * Initializes the input system.
     */
    private static function initialize() {
        if (self::$initialized) {
            return;
        }
        self::$getVariables = filter_input_array(INPUT_GET);
        self::$postVariables = filter_input_array(INPUT_POST);
        self::$runtimeVariables = array();
        self::sanitizeInputs();
        self::$initialized = true;
    }
    
    /**
     * Gets a value from the input.
     * It first checks the runtime variables, then path variables,
     * then post variables, and finally post variables.
     * @param string $key The key of the variable to get.
     * @param mixed $default The default value to give back if it doesn't exist.
     * @return mixed
     */
    public static function get($key, $default = null) {
        self::initialize();
        if (isset(self::$runtimeVariables[$key])) {
            return self::$runtimeVariables[$key];
        } else if (isset(Routes::getPathVariables()[$key])) {
            return Routes::getPathVariables()[$key];
        } else if (isset(self::$postVariables[$key])) {
            return self::$postVariables[$key];
        } else if (isset(self::$getVariables[$key])) {
            return self::$getVariables[$key];
        }
        if ($default === null) {
            throw new Exception('The required input "' . $key  .'" does not exist');
        }
        return $default;
    }
    
    /**
     * Gets a value from the input and casts to a boolean.
     * @param string $key The key of the variable to get.
     * @param mixed $default The default value to pass back.
     */
    public static function getBoolean($key, $default = false) {
        $value = strtolower(self::get($key, $default));
        return $value == 'true' || $value == 'on' || $value == '1' ||
                $value == 'enabled';
    }
    
    /**
     * Sets a value to the runtime variables, which will always be
     * first to be gotten using the get method.
     * @param string $key The key of the variable to get.
     * @param mixed $value The value to set the variable to.
     */
    public static function set($key, $value) {
        self::initialize();
        self::$runtimeVariables[$key] = self::sanitize($value);
    }
    
    /**
     * Determines whether or not a key exists in the input.
     * @param string $key The key to look for.
     * @return boolean
     */
    public static function exists($key) {
        self::initialize();
        if (isset(self::$runtimeVariables[$key])) {
            return true;
        } else if (isset(Routes::getPathVariables()[$key])) {
            return true;
        } else if (isset(self::$postVariables[$key])) {
            return true;
        } else if (isset(self::$getVariables[$key])) {
            return true;
        }
        return false;
    }
    
    /**
     * Sanitizes the inputs already set.
     */
    private static function sanitizeInputs() {
        foreach (self::$getVariables as $key => $val) {
            self::$getVariables[$key] = self::sanitize($val);
        }
        foreach (self::$postVariables as $key => $val) {
            self::$postVariables[$key] = self::sanitize($val);
        }
        foreach (self::$runtimeVariables as $key => $val) {
            self::$runtimeVariables[$key] = self::sanitize($val);
        }
    }
    
    /**
     * Sanatizes an input string.
     * @param type $str The string to sanitize.
     */
    private static function sanitize($str) {
        return trim($str);
    }
    
}