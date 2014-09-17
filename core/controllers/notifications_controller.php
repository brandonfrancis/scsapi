<?php

class notifications_controller {
    
    function get() {
        // Make sure the user is signed in 
        if (Auth::getUser()->isGuest()) {
            throw new Exception('You must sign in to get your notifications.');
        }
        
        // Get the notifications
        $notifications = Notification::forUser(Auth::getUser());
        $contexts = array();
        foreach ($notifications as $val) {
            $contexts[] = $val->getContext();
        }
        View::renderJson($contexts);
        
    }

    function clear() {
        // Make sure the user is signed in
        if (Auth::getUser()->isGuest()) {
            throw new Exception('You must sign in to clear your notifications.');
        }
        
        // Clear the notifications
        Notification::markAllRead(Auth::getUser());
        
        // Return the new list of notifications
        $this->get();
    }
    
    function get_push_ticket() {
        // Make sure the user is signed in
        if (Auth::getUser()->isGuest()) {
            throw new Exception('You must sign in to connect to the push server.');
        }
        
        // Return the ticket
        View::renderJson(Push::getTicket());
    }
    
}
