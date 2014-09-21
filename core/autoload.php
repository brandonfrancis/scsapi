<?php

// Display errors
ini_set('display_errors', '1');

// Make sure we only allow origins from specific sources
$origin = filter_input(INPUT_SERVER, 'HTTP_ORIGIN');
if ($origin == 'http://localhost' || $origin == 'https://localhost' ||
        $origin == 'http://scs.game-tuts.com' || $origin == 'https://scd.game-tuts.com') {
    header('Access-Control-Allow-Origin: ' . $origin);
} else if ($origin == '') {
    header('Access-Control-Allow-Origin: http://localhost');
}
header('Access-Control-Allow-Credentials: true');

// Do some cloudflare fixes if necessary
if (!empty(filter_input(INPUT_SERVER, 'HTTP_CF_CONNECTING_IP'))) {
    // Let's do things to fix cloudflare issues
    $_SERVER['REMOTE_ADDR'] = filter_input(INPUT_SERVER, 'HTTP_CF_CONNECTING_IP');
}

/**
 * Key used for development purposes.
 */
define('DEVELOPMENT_KEY', 'an291398123b891392bjaoiaiwbbebu82pa0haNAI02');

/**
 * The name of this website. This is for branding.
 */
define('APP_NAME', 'Social Coursesite');

/**
 * The default email address to send mail as.
 * Needs to be a valid email address.
 */
define('APP_EMAIL', 'noreply@localhost');

/**
 * The full url of the website. This is mainly used
 * for sending links in emails.
 * Do not include a trailing slash.
 */
define('APP_ABSOLUTE_URL', 'https://scsapi.game-tuts.com');

/**
 * The relative url of the website.
 *  e.g. If the site is under a directory /forums
 *  this value should be /forums
 *  SHOULD have an initial slash.
 *  Do not include a trailing slash.
 */
define('APP_RELATIVE_URL', '');

/**
 * The way to feed paths to the application.
 * Should be nothing if rewriting is being used to pass the path.
 */
define('APP_PATH_FEEDER', '');

/*---------------------------------------------------------------
 * 
 * FRAMEWORK CRITICAL CONFIGURATION BELOW
 * Do not change these values unless there is good reason to.
 * 
 * --------------------------------------------------------------
 */

/**
 * This is the master salt. Changing this should render all salt
 * related non-database values invalid.
 */
define('APP_SALT', '1oj9###1938((*a9282dawrgh810828aljdnb38@#1173b4p95');

/**
 * Changing this will render all sessions invalid.
 */
define('APP_SESSION_SALT', APP_SALT . 'j9we9j@#!)@!J(adb(Aanuds2n!N$N!oo9adn');

/**
 * Changing this will render all cookies invalid.
 */
define('APP_COOKIE_SALT', APP_SALT . '9jd90aw9enu3893na2afgj2#$@49uak!293928h1940A))A(3');

/*
 * Relative system paths to specific directories.
 * None of these should include a trailing slash.
 */
define('APP_ROOT_PATH', dirname(__DIR__));
define('APP_CORE_PATH', __DIR__);
define('APP_STORAGE_PATH', APP_ROOT_PATH . '/storage');
define('APP_STORAGE_SPECIAL_PATH', APP_STORAGE_PATH . '/special');
define('APP_CACHE_PATH', APP_STORAGE_PATH . '/cache');
define('APP_CACHE_SESSION_PATH', APP_CACHE_PATH . '/session');
define('APP_CACHE_TEMPLATE_PATH', APP_CACHE_PATH . '/template');
define('APP_IMAGE_STORAGE_PATH', APP_STORAGE_PATH . '/images');
define('APP_VENDOR_PATH', APP_CORE_PATH . '/vendor');
define('APP_APP_PATH', APP_ROOT_PATH . '/app');
define('APP_CONTROLLERS_PATH', APP_APP_PATH . '/controllers');
define('APP_CONTROLLERS_PATH_CORE', APP_CORE_PATH . '/controllers');
define('APP_VIEWS_PATH', APP_APP_PATH . '/views');
define('APP_VIEWS_PATH_CORE', APP_CORE_PATH . '/views');

/*
 * Pull in all of the backend static files
 */
foreach (glob(__DIR__ . '/static/*.php') as $file) {
    require_once $file;
}

/**
 * Now pull in all of the backend objects
 * 
 * Backend objects are classes that are required by the
 * backend static files. They should also always be pulled
 * in after the backend static files.
 */
foreach (glob(__DIR__ . '/models/*.php') as $file) {
    require_once $file;
}

/**
 * Now it's time to load in all of the custom, non framework
 * related statics and models.
 */
foreach (glob(APP_APP_PATH . '/static/*.php') as $file) {
    require_once $file;
}
foreach (glob(APP_APP_PATH . '/models/*.php') as $file) {
    require_once $file;
}

/**
 * Now load any custom routes
 */
require_once APP_APP_PATH . '/routes.php';
