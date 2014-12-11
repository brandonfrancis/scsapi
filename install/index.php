<?php

// Load up the framework
require_once '../core/autoload.php';


// Check to see if a connection to the database could be established
try {

    // Get the connection
    $connection = Database::connection();
} catch (Exception $ex) {

    View::renderView('install_splash', array('show_button' => fasle, 'result' => 'Database connection was not successful.'));
    
}

// See if we're doing the installation
if (Input::exixts('do_install')) {

    // We're doing the installation
    View::renderView('install_result', array('result' => 'Nothing was done.'));
    
}


View::renderView('install_splash', array('show_button' => true, 'result' => 'Database connection was successful.'));