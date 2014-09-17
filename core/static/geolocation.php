<?php

/**
 * Class for handling geolocation.
 */
class Geolocation {

    /**
     * The host used to backtrace IP addresses.
     */
    const IP_BACKTRACE_HOST = 'http://www.geoplugin.net/php.gp?ip=%s&base_currency=USD';
    
    /**
     * The host used to get zip codes from latitude and longitude.
     */
    const ZIP_HOST = 'http://www.geoplugin.net/extras/postalcode.gp?lat=%s&long=%s';
  
    /**
     * Will return location data given a user's ip address.
     * @param string $ip The ip address to backtrace.
     * @return array
     */
    public static function lookupIp($ip = null) {

        // Log whether the ip was null
        $wasNull = false;
        
        // Let's see if we've done this recently for this session
        if ($ip == null && Session::exists('backtrace_ip')) {
            return Session::get('backtrace_ip');
        }
        
        // Choose the ip to use
        if ($ip == null) {
            $wasNull = true;
            $ip = Session::getIpAddress();
        }

        // Format the host string for the api
        $host = sprintf(self::IP_BACKTRACE_HOST, $ip);

        // Get the response from the api
        $response = Utils::curl_get_contents($host);
        $data = unserialize($response);

        // Return the info in an array
        $result = array(
            'ip' => $ip,
            'city' => $data['geoplugin_city'],
            'state' => $data['geoplugin_region'],
            'state_full' => $data['geoplugin_regionName'],
            'area_code' => $data['geoplugin_areaCode'],
            'dma' => $data['geoplugin_dmaCode'],
            'country_code' => $data['geoplugin_countryCode'],
            'country_name' => $data['geoplugin_countryName'],
            'continent_code' => $data['geoplugin_continentCode'],
            'latitude' => $data['geoplugin_latitude'],
            'longitude' => $data['geoplugin_longitude'],
            'currency_code' => $data['geoplugin_currencyCode'],
            'currency_symbol' => $data['geoplugin_currencySymbol']
        );
        
        // Now let's get the zip code
        $latLongLookup = self::lookupLatLong($result['latitude'], $result['longitude']);
        $result['zip'] = $latLongLookup['zip'];
        
        // Save this for future reference, if this was the current user's ip
        if ($wasNull) {
            Session::set('backtrace_ip', $result);
        }
        
        // Now let's return the result
        return $result;
        
    }
    
    /**
     * Gets locaton data based on a latitude and longitude.
     * @param float $latitude The latitude.
     * @param float $longitude The longitude.
     */
    public static function lookupLatLong($latitude, $longitude) {
        
        $host = sprintf(self::ZIP_HOST, $latitude, $longitude);
        $response = Utils::curl_get_contents($host);
        $data = unserialize($response);
        return array(
            'city' => $data['geoplugin_place'],
            'country' => $data['geoplugin_countryCode'],
            'zip' => $data['geoplugin_postCode'],
            'latitude' => $data['geoplugin_latitude'],
            'longitude' => $data['geoplugin_longitude'],
            'distance' => $data['geoplugin_distanceMiles'],
            'distance_km' => $data['geoplugin_distanceKilometers']
        );
        
    }
  
}
