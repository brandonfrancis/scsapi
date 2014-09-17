<?php

/**
 * Contains methods for handling routes.
 */
class Routes {
    
    /**
     * Session key for storing the last rendered path.
     */
    const LAST_RENDERED_PATH_KEY = 'route_lastrendered';
    
    const IDENT_PREG_START = "#{";
    const IDENT_PREF_END = "}#si";
    const IDENT_PREG = "#{([a-zA-Z0-9|_|-]+)}#si";
    
    /**
     * @var Route
     */
    private static $routes = array();
    
    /**
     * Variables found in the current path.
     * @var type 
     */
    private static $currentPathVariables = array();

    /**
     * Determines the current route and runs it.
     */
    public static function run() {

        // Pull the current path out of the get arguments directly
        $length = strlen(APP_RELATIVE_URL) == 0 ? 0 : strlen(APP_RELATIVE_URL) + 1;
        $path = substr(urldecode(parse_url(filter_input(INPUT_SERVER, 'REQUEST_URI'), PHP_URL_PATH)), $length);

        try {

            // Get the appropriate route for the path
            $route = Routes::get($path);
            
            // If our route is null, we should 404
            if ($route == null) {
                header('Status: 404 Not Found', true, 404);
                View::renderView('404');
                return;
            }
            
            // See whther or not we have to check the token
            if ($route->isTokenNeeded()) {
                Auth::checkToken();
            }

            // Get the current path variables
            self::$currentPathVariables = self::pullVariables($path, $route);

            // Run the route
            $route->run();

            // See if the last rendered path variable needs to be set
            if (View::hasRenderedView()) {
                Session::set(self::LAST_RENDERED_PATH_KEY, $path);
            }
            
        } catch (Exception $ex) {

            View::renderJson($ex->getMessage(), false);
            exit;
            
        }
        
    }

    /**
     * Gets the path for a controller. May contain variables.
     * @param string $controller The controller to get the path for.
     * @return string
     */
    public static function getPath($controller) {
        $controller = strtolower($controller);
        if (isset(self::$routes[$controller])) {
            return self::$routes[$controller]->getPath();
        }
        return '';
    }

    /**
     * Gets the variables for the current path.
     * @return array
     */
    public static function getPathVariables() {
        return self::$currentPathVariables;
    }
    
    /**
     * Given a path and replacements, it will replace the identifiers in the
     * path with the replacements given.
     * If replacements is a single length array that has an element that is
     * an array, it will do replacements based on key value pairs.
     * @return string
     */
    public static function doPathReplacement($path, $replacements = array()) {
        
        // Do this to allow key pairing when calling from php
        if (count($replacements) > 0 && !array_key_exists(0, $replacements)) {
            $replacements = array($replacements);
        }
        
        // Get the count of the replacements
        $replacementsCount = count($replacements);
        
        if ($replacementsCount == 0) {
            
            // Nothing to replace, just return the relative url
            return self::getRelativeUrl($path);
            
        } else if ($replacementsCount == 1 && is_array($replacements[0])) {
            
            // We're going to do key pairing to replace the identifiers in the path
            $patterns = array();
            $values = array();
            foreach ($replacements[0] as $key => $val) {
                $patterns[] = self::IDENT_PREG_START . preg_quote($key) . self::IDENT_PREF_END;
                $values[] = $val;
            }
            $path = Routes::getRelativeUrl(preg_replace($patterns, $values, $path));
            $cleanedPath = preg_replace(self::IDENT_PREG, '', $path);
            return $cleanedPath;
            
        } else {
            
            // Replace based on position in the array and not keys
            $patterns = array_fill(0, $replacementsCount, self::IDENT_PREG);
            $path = preg_replace($patterns, $replacements, $path, 1);
            $cleanedPath = preg_replace(self::IDENT_PREG, '', $path);
            return self::getRelativeUrl($cleanedPath);
            
        }
    }

    /**
     * Gets a HTML relative url given a path in the application.
     * @param string $path The path to get the relative url for.
     */
    private static function getRelativeUrl($path) {
        return APP_RELATIVE_URL . '/' . APP_PATH_FEEDER . $path;
    }
    
    /**
     * Gets the relative url to a controller.
     * @param string $controller The controller to get the url for.
     * @param array $replacements The values to replace in the url.
     * @return string
     */
    public static function getControllerRelativeUrl($controller, $replacements = array()) {
        return self::doPathReplacement(self::getPath($controller), $replacements);
    }
    
    /**
     * Redirects the visitor to a controller and stops execution.
     * @param string $controller The controller to redirect to.
     * @param array $replacements The replacements for the controller path.
     */
    public static function redirectToController($controller, $replacements = array()) {
        $path = self::getControllerRelativeUrl($controller, $replacements);
        self::redirectToPath($path);
    }
    
    /**
     * Redirects the user back to their last rendered path.
     */
    public static function redirectToLastPath() {
        self::redirectToPath(self::getRelativeUrl(self::getLastRenderedPath()));
    }
    
    /**
     * Redirects to the given path and stops execution.
     * @param string $path The path to redirect to.
     */
    private static function redirectToPath($path) {
        if (headers_sent()) {
            return;
        }
        Session::close();
        header('Status: 303 See Other');
        header('Location: ' . $path, true, 303);
        exit();
    }
    
    /**
     * Gets the path for the last page that was rendered to the screen.
     */
    private static function getLastRenderedPath() {
        return Session::get(self::LAST_RENDERED_PATH_KEY, '');
    }
    
    /**
     * Adds a route to the current route list.
     * @param string $path The path for this route.
     * @param string $controller The controller the route runs.
     * @param boolean $tokenNeeded Whether or not a token is necessary.
     */
    public static function set($path, $controller, $tokenNeeded = true) {
        $path = self::fixPath($path);
        $controller = strtolower($controller);
        $keyToUse = $controller;
        $i = 1;
        while (key_exists($keyToUse, self::$routes)) {
            $keyToUse = $controller . '_' . $i;
            $i++;
        }
        $route = new Route($path, $controller);
        $route->setTokenNeeded($tokenNeeded);
        self::$routes[$keyToUse] = $route;
    }

    /**
     * Take a given path and make sure it conforms to the necessary format.
     * 
     * This will make sure the path:
     *      - Has no trailing slash
     *      - Does not start with a slash
     *      - Uses / as a delimiter
     *      - Does not include double delimiters (//)
     * 
     * @param string $path The path to fix.
     * @return string
     */
    public static function fixPath($path) {
        $path = str_replace('\\', '/', $path);
        if (strlen($path) > 0 && $path[0] == '/') {
            $path = substr($path, 1);
        }
        if (strlen($path) > 0 && $path[strlen($path) - 1] == '/') {
            $path = substr($path, 0, strlen($path) - 1);
        }
        return $path;
    }

    /**
     * Returns a route that matches the given path.
     * @param string $path
     * @return Route
     */
    private static function get($path) {
        $path = self::fixPath($path);
        $bestMatch = null;
        $bestMatchVarCount = -1;
        foreach (self::$routes as $route) {
            
            // Check if it's a perfect match, if so use it
            if ($route->getPath() == $path) {
                return $route;
            }
            
            // Check if it's a regex match
            if (!self::isMatch($path, $route)) {
                continue;
            }
            
            // See if it's the best match (least general)
            if ($route->getPathVariableCount() > $bestMatchVarCount || $bestMatchVarCount == -1) {
                $bestMatch = $route;
                $bestMatchVarCount = $route->getPathVariableCount();
            }
            
        }
        return $bestMatch;
    }
    
    /**
     * Returns whether or not a given path is a match to a route path.
     * @param string $pathToMatch The path to test against the route path.
     * @param Route $route The route that will be tested against.
     * @return boolean
     */
    private static function isMatch($pathToMatch, Route $route) {
        return preg_match($route->getRegexPath(), $pathToMatch);
    }
    
    /**
     * Pull the variables out of a path given the actual path and the
     * path that is is representing.
     * @param string $pathToMatch The path that is being matched.
     * @param Route $route The route to use.
     * @return array
     */
    private static function pullVariables($pathToMatch, Route $route) {
        $rtn = array();
        $pathToMatch = self::fixPath($pathToMatch);
        
        $nameMatches = array();
        $valueMatches = array();
        
        preg_match_all(self::IDENT_PREG, $route->getPath(), $nameMatches, PREG_PATTERN_ORDER);
        preg_match_all($route->getRegexPath(), $pathToMatch, $valueMatches, PREG_PATTERN_ORDER);
        
        for ($i = 0; $i < count($nameMatches[1]); $i++) {
            $value = $valueMatches[$i + 1][0];
            $rtn = array_merge($rtn, array($nameMatches[1][$i] => $value));
        }
        
        return $rtn;
    }
    
}

/**
 * Represents a single route that can be taken.
 */
class Route {
    
    private $path;
    private $regexPath;
    private $controller;
    private $function;
    private $pathVarCount;
    private $tokenNeeded;
    
    /**
     * Gets the specified path for this route.
     * @return string
     */
    public function getPath() {
        return $this->path;
    }
    
    /**
     * Gets the regex version of the path.
     * @return string
     */
    public function getRegexPath() {
        return $this->regexPath;
    }
    
    /**
     * Gets the count of the variables in the path.
     * @return int
     */
    public function getPathVariableCount() {
        return $this->pathVarCount;
    }
    
    /**
     * Gets the full controller name along with function.
     * @return string
     */
    public function getFullController() {
        return $this->controller . '#' . $this->function;
    }
    
    /**
     * Sets whether or not a token is necessary for this route.
     * @param boolean $value The value to set.
     */
    public function setTokenNeeded($value) {
        $this->tokenNeeded = $value;
    }
    
    /**
     * Returns whether or not a token is necessary for this route.
     * @return boolean
     */
    public function isTokenNeeded() {
        return $this->tokenNeeded;
    }


    /**
     * Creates a new Route object.
     * @param type $path The path that the path represents.
     * @param type $controller The controller (method@controller) that this route calls.
     */
    public function __construct($path, $controller) {
        $controller = strtolower($controller);
        $processedControllerString = self::processControllerString($controller);
        $this->path = $path;
        $this->function = $processedControllerString[0];
        $this->controller = $processedControllerString[1];
        $this->regexPath = self::transformRoutePathToRegex($this->path, $this->pathVarCount);
        $this->tokenNeeded = true;
    }

    /**
     * Runs this route.
     */
    public function run() {

        // Get the path of the controller script to require
        $controllerPath = APP_CONTROLLERS_PATH . '/' . $this->controller . '_controller.php';
        if (!file_exists($controllerPath)) {
            $controllerPath = APP_CONTROLLERS_PATH_CORE . '/' . $this->controller . '_controller.php';
        }
        if (!file_exists($controllerPath)) {
            throw new Exception('Controller does not exist');
        }

        // Pull in the class
        require_once $controllerPath;

        // Make sure the controller class exists
        if (!class_exists($this->controller . '_controller')) {
            throw new Exception('Controller class does not exist.');
        }

        // Create a new instance of the class
        $controllerName = $this->controller . '_controller';
        $instance = new $controllerName();

        // Run the function and get the context
        $context = $instance->{ $this->function }();
        if ($context == null) {
            $context = array();
        }

        // See if a view exists for it, if so let's render it
        // If one doesn't exist, that's fine, it may just be a POST or GET endpoint
        if (View::viewExists($this->controller . '/' . $this->function)) {
            View::renderView($this->controller . '/' . $this->function, $context);
        }
        
    }

    /**
     * Processes a controller string into it's individual parts.
     * @param string $controller The controller string.
     * @return array
     */
    private static function processControllerString($controller) {
        $hashPos = strpos($controller, '#');
        if ($hashPos > -1) {
            return array(substr($controller, $hashPos + 1), substr($controller, 0, $hashPos));
        }
        return array('index', $controller);
    }
    
     /**
     * Transforms a route path into a regex representation that can be used to extract
     * values from it.
     * @param string $path The route path to transform.
     * @return string
     */
    private static function transformRoutePathToRegex($path, &$count) {
        return '#^' . preg_replace(Routes::IDENT_PREG, '([a-zA-Z0-9|_|-]+)', $path, -1, $count) . '$#si';
    }
    
    
}