<?php

/**
* Class that allows for communication to the database.
*/
class Database {
    
    /**
    * Configuration for the database connection.
    */
    private static $config = array(
        'driver' => 'mysql',
        'host' => 'localhost',
        'database_name' => 'socialcoursesite',
        'charset' => 'utf8',
        'username' => 'coursesiteuser',
        'password' => '23moa)@3jian@NN'
    );
    
    /**
     * The current connection to the database.
     * @var \PDO
     */
    private static $connection = null;
    
    /**
     * Initializes this database class.
     */
    private static function initialize() {
        if (self::$connection != null) {
            return;
        }
        self::$connection = self::getConnection();
    }
    
    /**
     * Gets a new connection to the database.
     * @return \PDO
     */
    private static function getConnection() {
        $driver = self::$config['driver'];
        $host = self::$config['host'];
        $database_name = self::$config['database_name'];
        $charset = self::$config['charset'];
        $pdo = new PDO("$driver:host=$host;dbname=$database_name;charset=$charset",
                self::$config['username'], self::$config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
    
    /**
     * Returns the connection to the database.
     * @return \PDO
     */
    public static function connection() {
        self::initialize();
        return self::$connection;
    }
    
}

