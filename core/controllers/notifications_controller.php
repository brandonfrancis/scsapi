<?php

class notifications_controller {
    
    function get() {
        Auth::checkLoggedIn();
        
        // Get the notifications
        $notifications = Notification::forUser(Auth::getUser());
        $contexts = array();
        foreach ($notifications as $val) {
            $contexts[] = $val->getContext();
        }
        View::renderJson($contexts);
        
    }

    function clear() {
        Auth::checkLoggedIn();
        
        // Clear the notifications
        Notification::markAllRead(Auth::getUser());
        
        // Return the new list of notifications
        $this->get();
    }
    
    function get_push_ticket() {
        Auth::checkLoggedIn();
        
        // Return the ticket
        View::renderJson(Push::getTicket());
    }
    
}
