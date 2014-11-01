<?php

/**
 * Class that handles the rendering of views.
 */
class View {
    
    /**
     * @var Twig_Environment
     */
    private static $twig = null;
    
    /**
     * Whether or not the templating system is initialized.
     * @var boolean
     */
    private static $initialized = false;
    
    /**
     * A flag that shows whether the page has already been rendered to the visitor.
     * @var boolean
     */
    private static $hasRenderedView = false;
    
    /**
     * Initializes the templating system.
     */
    private static function initialize() {
        
        // Make sure it's not already initialized
        if (self::$initialized) {
            return;
        }
        
        // Load up and register Twig
        require_once APP_VENDOR_PATH . '/Twig/Autoloader.php';
        Twig_Autoloader::register();
        
        // Greate the environment
        $loader = new Twig_Loader_Filesystem(APP_VIEWS_PATH);
        $loader->addPath(APP_VIEWS_PATH . '/shared');
        $loader->addPath(APP_VIEWS_PATH_CORE);
        $loader->addPath(APP_VIEWS_PATH_CORE . '/shared');
        $twig = new Twig_Environment($loader, array('auto_reload' => true));

        // Add in the custom twig functions
        // The getPath function turns controller endpoints into relative
        // urls that can be used in the interface.
        $getPathFunction = new Twig_SimpleFunction("get_path", function () {
            $args = func_get_args();
            if (count($args) == 0) {
                return;
            }
            $controller = $args[0];
            array_shift($args);
            return Routes::getControllerRelativeUrl($controller, $args);
        });
        $twig->addFunction($getPathFunction);
        
        // Used to make images square with no distortion
        $avatarFunction = new Twig_SimpleFunction("avatar", function () {
            $args = func_get_args();
            $addon = '';
            if (count($args) == 2 && $args[1] == true) {
                $addon = '-small';
            } else if (count($args) > 1) {
                return;
            }
            if (empty($args[0])) {
                $url = Routes::getControllerRelativeUrl('image#noavatar');
            } else {
                $url = Routes::getControllerRelativeUrl('image#viewthumb', array($args[0]));
            }
            echo '<div class="avatar-wrapper"><div class="avatar' . $addon . '" style="background-image: url(\'' . $url . '\');"></div></div>';
        });
        $twig->addFunction($avatarFunction);

        // Used to make images square with no distortion
        $friendlyUrlFunction = new Twig_SimpleFunction("seo_friendly", function () {
            $args = func_get_args();
            if (count($args) != 1) {
                return;
            }
            return Utils::makeSeoFriendly($args[0]);
        });
        $twig->addFunction($friendlyUrlFunction);

        // The public function allows the interface to get public resources
        $publicResourceFunction = new Twig_SimpleFunction("public_path", function ($path) {
            echo APP_RELATIVE_URL . '/public/' . Routes::fixPath($path);
        });
        $twig->addFunction($publicResourceFunction);

        // Set the reference
        self::$twig = $twig;
        self::$initialized = true;
    }
    
    /**
     * Whether or not the page has been rendered to the visitor.
     * @return boolean
     */
    public static function hasRenderedView() {
        return self::$hasRenderedView;
    }
    
    /**
     * Determines whether or not a view exists given a name.
     * @param string $name The name of the view to check for.
     * @return boolean
     */
    public static function viewExists($name) {
        if (file_exists(APP_VIEWS_PATH . '/' . $name . '.html.twig') ||
                file_exists(APP_VIEWS_PATH_CORE . '/' . $name . '.html.twig')) {
            return true;
        }
        return false;
    }

    /**
     * Compiles a view given a view name and context. Does not print to the screen.
     * 
     * The context for the page will be user the "page" key.
     * So for example a "user" array passed as the context will
     * be available as {{ page.user }}
     * 
     * @param string $name The name of the view.
     * @param mixed $context The context to use in compilation.
     */
    public static function compileView($name, $context = null) {
        self::initialize();
        if ($context == null) {
            $context = array();
        }
        $context = array_merge(
                array(
                    '_token' => Session::getToken(),
                    'app' => array('alerts' => Log::getAlerts(), 'notices' => Log::getNotices()),
                    'user' => Auth::getUser()->getContext(Auth::getUser())
                ), array('page' => $context));
        return self::$twig->render($name . '.html.twig', $context);
    }

    /**
     * Renders a view given a view name and context. Prints to the screen.
     * @param string $name The name of the view.
     * @param mixed $context The context to use in compilation.
     */
    public static function renderView($name, $context = null) {
        $compiled = self::compileView($name, $context);
        Log::clearNoticesAndAlerts();
        self::$hasRenderedView = true;
        echo $compiled;
    }
    
    /**
     * Renders json and outputs it to the screen. Stops execution.
     * @param mixed $data
     */
    public static function renderJson($data = null, $success = true) {
        if (!headers_sent()) {
            if (!$success) {
                //header('Status: 404 Not Found', true, 404);
            }
            header('Content-Type: text/json');
        }
        echo json_encode(array('success' => boolval($success), 'data' => $data));
        exit;
    }
    
    /**
     * Renders an image to the client.
     * @param string $filename The path to the image to display.
     */
    public static function renderImage($filename) {
        $imginfo = getimagesize($filename);
        header('Content-type: ' . $imginfo['mime']);
        readfile($filename);
        exit;
    }

    /**
     * Renders a file to the user and allows them to download it.
     * @param string $filename The filename that points to the file.
     * @param string $name The optional name for the download to download as in the user's browser.
     * @throws Exception
     */
    public static function renderFile($filename, $name) {
        
        // Make sure the file exists
        if (!file_exists($filename)) {
            throw new Exception('File does not exist.');
        }

        // Set up the headers
        $size = filesize($filename);
        header("Pragma: public");
        header("Expires: 0");
        header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        header("Cache-Control: public");
        header("Content-Description: File Transfer");
        header("Content-Type: File");
        header("Content-Disposition: attachment; filename=\"" . $name . "\"");
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . $size);

        // Start the transfer
        $file = fopen($filename, "rb");
        if ($file) {
            while (!feof($file)) {
                print(fread($file, 1024 * 8));
                flush();
                if (connection_status() != 0) {
                    fclose($file);
                    die();
                }
            }
            fclose($file);
        }
        
        // Stop processing anything else
        exit;
    }

}
