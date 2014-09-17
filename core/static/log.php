<?php

/**
 * Allows notices and alerts to be logged and transfer from page to page
 * until they are shown to the user.
 */
class Log {

    const ALERTS_KEY = 'log_alerts';
    const NOTICES_KEY = 'log_notices';

    private static $initialized = false;
    private static $alerts = null;
    private static $notices = null;

    /**
     * Initializes the logging system.
     */
    private static function initialize() {
        if (self::$initialized) {
            return;
        }
        self::$alerts = Session::get(self::ALERTS_KEY, array());
        self::$notices = Session::get(self::NOTICES_KEY, array());
        self::$initialized = true;
    }

    /**
     * Adds an message to the alerts.
     * @param string $alertMessage The message for the alert.
     */
    public static function alert($alertMessage) {
        self::initialize();
        self::$alerts[] = $alertMessage;
        Session::set(self::ALERTS_KEY, self::$alerts);
    }

    /**
     * Gets the current array of alerts.
     * @return array
     */
    public static function getAlerts() {
        self::initialize();
        return self::$alerts;
    }

    /**
     * Adds an message to the notices.
     * @param string $noticeMessage The message for the notice.
     */
    public static function notice($noticeMessage) {
        self::initialize();
        self::$notices[] = $noticeMessage;
        Session::set(self::NOTICES_KEY, self::$notices);
    }

    /**
     * Gets the current array of notices.
     * @return array
     */
    public static function getNotices() {
        self::initialize();
        return self::$notices;
    }

    /**
     * Removes all notices and alerts.
     */
    public static function clearNoticesAndAlerts() {
        self::$alerts = array();
        self::$notices = array();
        Session::set(self::ALERTS_KEY, self::$alerts);
        Session::set(self::NOTICES_KEY, self::$notices);
    }

}
