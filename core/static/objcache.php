<?php

/**
 * Core defines.
 */
define('OBJCACHE_TYPE_USER', 'user');
define('OBJCACHE_TYPE_NOTIFICATION', 'notification');

/**
 * Used to cache objects to avoid repeated database queries.
 */
class ObjCache {

    /**
     * The actual cache array.
     * @var array
     */
    private static $cache = array();

    /**
     * Used to ensure the main level cache type exists.
     * @param object $type The type.
     */
    private static function ensureTypeExists($type) {
        if (!key_exists($type, self::$cache)) {
            self::$cache[$type] = array();
        }
    }

    /**
     * Sets an object into the cache.
     * @param object $type The type of cache.
     * @param object $key The key to use.
     * @param object $obj The object to set.
     */
    public static function set($type, $key, $obj) {
        self::ensureTypeExists($type);
        self::$cache[$type][$key] = $obj;
    }

    /**
     * Gets an object from the cache.
     * @param object $type The type of cache.
     * @param object $key The key to look for.
     * @return object|null
     */
    public static function get($type, $key) {
        self::ensureTypeExists($type);
        if (!key_exists($key, self::$cache[$type])) {
            return null;
        }
        return self::$cache[$type][$key];
    }
    
    /**
     * Invalidates a cache entry.
     * @param object $type The type of cache to use.
     * @param object $key The key to use.
     */
    public static function invalidate($type, $key) {
        self::set($type, $key, null);
    }

}
