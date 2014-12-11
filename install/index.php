<?php

// Load up the framework
require_once '../core/autoload.php';


// Check to see if a connection to the database could be established
try {
    
    // Get the connection
    $connection = Database::connection();
    
} catch (Exception $ex) {

    
    
}

View::renderView('install');