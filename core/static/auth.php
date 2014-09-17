<?php

/**
 * Contains methods for user authentication.
 */
class Auth {

    /**
     * A stored instance of the currently logged in user.
     * @var User
     */
    private static $user = null;

    /**
     * Make sure the CSRF token exists and is valid.
     * This should be checked on most forms and get methods that manipulate state.
     */
    public static function checkToken() {

        // Check to see if the token is what it needs to be
        if (Input::exists('_token') && Input::get('_token') == Session::getToken()) {
            return;
        }

        // Check to see if it's the secret development token
        if (Input::exists('_token') && Input::get('_token') === DEVELOPMENT_KEY) {
            return;
        }

        // Throw a token mismatch error
        throw new Exception('Token mismatch.');
    }

    /**
     * Gets the currently logged in user.
     * @return User
     */
    public static function getUser() {

        // If we already got the user, just return it
        if (self::$user != null) {
            return self::$user;
        }

        // Get the user
        $userid = Cookie::get('userid', 0);
        $password = Cookie::get('sid', '0');
        $user = User::fromId($userid);

        // Make sure the password is valid
        if (!$user->isCookiePasword($password)) {

            // Delete the cookies, they're obviously bad
            Cookie::delete('userid');
            Cookie::delete('sid');

            // Create a new guest user
            $user = User::guest();
        }

        // Set the user and return
        self::$user = $user;
        return $user;
    }

    /**
     * Sets the currently logged in user.
     * @param User $user The user to set.
     * @param string $password The password of the user, just to be sure.
     */
    public static function setUser(User $user, $password) {

        // Let's first issue a new session token to null out any old forms
        Session::issueToken();

        // Make sure the user isn't a guest and the password works
        if ($user == null || $user->isGuest() || !$user->isPassword($password)) {

            // Delete the cookies
            Cookie::delete('userid');
            Cookie::delete('sid');

            // Set the user to a guest
            self::$user = User::guest();
            return;
        }

        // Make sure this isn't already the signed in user
        if (self::$user != null &&
                (self::$user->getUserId() == $user->getUserId())) {
            return;
        }

        // Set the cookies
        Cookie::set('userid', $user->getUserId());
        Cookie::set('sid', $user->getCookiePassword());

        // Update the user's visit times
        $user->updateVisitInfo();

        // Let's now set the local version
        self::$user = $user;
    }

    /**
     * Logs the current user out.
     */
    public static function logOut() {
        self::setUser(User::guest(), '');
    }

}
