<?php

class user_controller {
    
    function create() {
        
        // Make sure they are not currently logged in
        if (!Auth::getUser()->isGuest()) {
            throw new Exception('You are already logged in.');
        }
        
        // TODO: Add human verification here
        
        // Get the request info
        $firstName = Input::get('first_name');
        $lastName = Input::get('last_name');
        $email = Input::get('email');
        $password = Input::get('password');
        
        // Create the user
        $user = User::create($firstName, $lastName, $email, $password);
        
        // Log in as the user
        Auth::setUser($user, $password);
        
        // Return the new info
        View::renderJson($user->getContext(Auth::getUser()));
        
    }
    
    function fetch() {
        View::renderJson(Auth::getUser()->getContext(Auth::getUser()));
    }
    
    function login() {
        
        // Make sure they are not currently logged in
        if (!Auth::getUser()->isGuest()) {
            throw new Exception('You are already logged in.');
        }
        
        // TODO: Add brute forcing prevention here
        
        // Get the credentials from the request
        $email = Input::get('email');
        $password = Input::get('password');
         
        // Get the user from the email address if it exists
        $user = User::fromEmail($email);
        if ($user->isGuest() || !$user->isPassword($password)) {
            throw new Exception('Invalid login details.');
        }
        
        // It passed all of the checks so let's set the user
        Auth::setUser($user, $password);
        
        // Pass back the info for the user
        View::renderJson(Auth::getUser()->getContext(Auth::getUser()));
        
    }
    
    function logout() {
        Auth::logOut();
        View::renderJson(User::guest());
    }
    
    function recover() {
        
        // Get the email address from the request
        $email = Input::get('email');
        
        // Try to get the user from the email address
        $user = User::fromEmail($email);
        
        // If it's a guest just quietly allow it
        // but if it's not send the password to the email
        if (!$user->isGuest()) {
            $user->createTempPassword();
        }
        
        // Render the json with success no matter what
        View::renderJson();
        
    }
    
    function verify_resend() {
        Auth::checkLoggedIn();
        Auth::getUser()->sendVerificationEmail();
    }
    
    function verify() {
        
        // Get the code from the request
        $userid = Input::get('userid');
        $code = Input::get('code');
        
        $user = User::fromId($userid);
        
        // Do the verification
        if ($user->isGuest() || !$user->verifyEmail($code)) {
            echo 'Your email address could not be verified.';
            exit;
        }
        
        // Output the results
        echo 'Your email address is now verified.';
        
        // Tell the user to refetch the data
        $user->emit('user_refetch');
        exit;
        
    }

}
