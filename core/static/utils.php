<?php

/**
 * Random useful utilities will go in this class.
 */
class Utils {
    
    /**
     * Generates a random string 
     * @param int $length
     * @return string
     */
    public static function generateRandomId() {
        return md5(crypt(openssl_random_pseudo_bytes(100)));
    }
    
    /**
     * Generates a random password hash.
     * @param int $length
     * @return string
     */
    public static function generateRandomPassword() {
        return sha1(crypt(openssl_random_pseudo_bytes(100)));
    }
    
    /**
     * Returns whether or not a string is a valid email address or not.
     * @param string $email The email address to check.
     * @return boolean
     */
    public static function isValidEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    /**
     * Removes all whitespace in a string and returns the result.
     * @param string $string The string to remove the whitespace from.
     * @return string
     */
    public static function removeWhitespace($string) {
        return preg_replace('/\s+/', '', $string);
    }

    /**
     * Makes a string into a search engine friendly string to put in a url.
     * @param string $string The string to make seo friendly.
     * @return string
     */
    public static function makeSeoFriendly($string) {
        $string = str_replace(array('[\', \']'), '', $string);
        $string = preg_replace('/\[.*\]/U', '', $string);
        $string = preg_replace('/&(amp;)?#?[a-z0-9]+;/i', '-', $string);
        $string = htmlentities($string, ENT_COMPAT, 'utf-8');
        $string = preg_replace('/&([a-z])(acute|uml|circ|grave|ring|cedil|slash|tilde|caron|lig|quot|rsquo);/i', '\\1', $string);
        $string = preg_replace(array('/[^a-z0-9]/i', '/[-]+/'), '-', $string);
        return strtolower(trim($string, '-'));
    }

    /**
     * An alternative to file_get_contents using curl which should be much faster.
     * If curl is not found it'll just use file_get_contents.
     * @param string $url The url to get.
     * @return string
     */
    public static function curl_get_contents($url) {
        if (!function_exists('curl_init')) {
            return file_get_contents($url);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }

}
