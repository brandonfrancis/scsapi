<?php

Routes::set('user/create', 'user#create');
Routes::set('user/fetch', 'user#fetch');
Routes::set('user/login', 'user#login');
Routes::set('user/logout', 'user#logout');
Routes::set('user/verify', 'user#verify');
Routes::set('user/recover', 'user#recover');

/**
 * Class for handling and manipulating users.
 */
class User {

    /**
     * The amount of time a temp password is valid for.
     */
    const TEMP_PASSWORD_EXPIRE_TIME = 10800;

    /**
     * The currently loaded row for the user.
     * @var array 
     */
    private $row = null;

    /**
     * Gets a user from their userid.
     * @param string $id The user's id.
     * @return User
     */
    public static function fromId($id) {
        $user = new User();
        if (is_numeric($id)) {
            $user->row = self::getRowById($id);
        }
        return $user;
    }
    
    /**
     * Gets a user from their email.
     * @param string $email The user's email address.
     * @return User
     */
    public static function fromEmail($email) {
        $user = new User();
        if (Utils::isValidEmail($email)) {
            $user->row = self::getRowByEmail($email);
        } 
        return $user;
    }
    
    /**
     * Gets a user from their database row.
     * @param array $row The user's database row.
     * @return User
     */
    public static function fromRow($row) {
        $user = new User();
        if (is_array($row)) {
            $user->row = $row;
        }
        return $user;
    }
    
    /**
     * Gets a new guest user.
     * @return User
     */
    public static function guest() {
        return new User();
    }
    
    /**
     * Creates a new User object given a userid or a user row.
     * @param mixed $idOrRow The userid or the row of the user from the database.
     */
    private function __construct() {
        $this->row = null;
    }

    /**
     * Creates a new user and returns it.
     * @param string $firstName The first name of the user.
     * @param string $lastName The last name of the user.
     * @param string $email The email address of the user.
     * @param string $password The plaintext password for the user.
     * @return User
     * @throws Exception
     */
    public static function create($firstName, $lastName, $email, $password) {

        // First check the email address
        $email = strtolower($email);
        if (!Utils::isValidEmail($email)) {
            throw new Exception('Unable to create new user: invalid email address given.');
        }

        // Create some variables for the user
        $createdAt = time();
        $salt = Utils::generateRandomPassword();
        $saltCookie = Utils::generateRandomPassword();
        $emailToken = Utils::generateRandomId();
        $password = self::transformPassword($password, $salt);

        // Create the query
        $query = Database::connection()->prepare(
                'INSERT INTO user (first_name, last_name, email, email_token, salt, salt_cookie, password,'
                . ' created_at, created_from, last_visit_at, last_visit_from, current_visit_at, current_visit_from)'
                . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $query->bindValue(1, $firstName, PDO::PARAM_STR);
        $query->bindValue(2, $lastName, PDO::PARAM_STR);
        $query->bindValue(3, $email, PDO::PARAM_STR);
        $query->bindValue(4, $emailToken, PDO::PARAM_STR);
        $query->bindValue(5, $salt, PDO::PARAM_STR);
        $query->bindValue(6, $saltCookie, PDO::PARAM_STR);
        $query->bindValue(7, $password, PDO::PARAM_STR);
        $query->bindValue(8, $createdAt, PDO::PARAM_INT);
        $query->bindValue(9, Session::getIpAddress(), PDO::PARAM_STR);
        $query->bindValue(10, $createdAt, PDO::PARAM_INT);
        $query->bindValue(11, Session::getIpAddress(), PDO::PARAM_STR);
        $query->bindValue(12, $createdAt, PDO::PARAM_INT);
        $query->bindValue(13, Session::getIpAddress(), PDO::PARAM_STR);

        // Execute the query
        if (!$query->execute()) {
            throw new Exception('Unable to create new user: database insert failed.');
        }

        // Get the id of the new user
        $userid = Database::connection()->lastInsertId();

        // Get the user
        $user = User::fromId($userid);
        
        // Send out the verification email
        $user->sendVerificationEmail();
        
        // Return the user
        return $user;
        
    }

    /**
     * Transforms a plaintext password into it's hash using a salt.
     * DO NOT CHANGE THIS. IT WILL BREAK ALL PASSWORDS.
     * @param type $password The password to transform.
     * @param type $salt The salt to use.
     * @return string
     */
    private static function transformPassword($password, $salt) {
        return sha1(sha1($password) . $salt);
    }
    
    /**
     * Returns an array of values that the templating system can access.
     * The currently signed in user automatically gets put into the
     * template context for every template so that's why this is necessary.
     * @return array
     */
    public function getContext() {
        return array(
            'is_guest' => $this->isGuest(),
            'is_admin' => $this->isAdmin(),
            'userid' => $this->getUserId(),
            'email' => $this->getEmail(),
            'first_name' => $this->getFirstName(),
            'last_name' => $this->getLastName(),
            'full_name' => $this->getFullName(),
            'email_verified' => $this->isEmailVerified()
        );
    }

    /**
     * Gets the row for the user given a userid.
     * @param int $id The userid of the user.
     * @return array
     */
    private static function getRowById($id) {
        if ($id == 0) {
            return null;
        }
        $query = Database::connection()->prepare('SELECT * FROM user WHERE userid = ?');
        $query->bindValue(1, $id, PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            return null;
        }
        return $query->fetch();
    }
    
    /**
     * Gets the for for the user given an email address.
     * @param string $email The email address for the user.
     * @return array
     */
    private static function getRowByEmail($email) {
        if (!Utils::isValidEmail($email)) {
            return null;
        }
        $query = Database::connection()->prepare('SELECT * FROM user WHERE email LIKE ?');
        $query->bindValue(1, strtolower($email), PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            return null;
        }
        return $query->fetch();
    }

    /**
     * Decides whether a given password is valid for this user or not.
     * @param string $password The password to check.
     * @return boolean
     */
    public function isPassword($password) {

        // If this is a guest it's automatically wrong
        if ($this->isGuest()) {
            return false;
        }

        // Transform the given password
        $transformed = self::transformPassword($password, $this->getSalt());

        // Check it against the user's main password
        if ($transformed == $this->row['password']) {
            return true;
        }

        // See if a temp password was recently issued
        $passwordTempTime = intval($this->row['password_temp_time']);
        if ($passwordTempTime + self::TEMP_PASSWORD_EXPIRE_TIME < time()) {
            return false;
        }

        // Check the temp password
        if ($transformed == $this->row['password_temp']) {
            return true;
        }

        // It's simply not valid
        return false;
    }

    /**
     * Decides whether or not the given cookie password is valid for this user.
     * Cookie passwords are hashed again with the cookie salt to ensure greater
     * security since cookies are saved on computers.
     * @param string $cookiePassword The cookie password.
     */
    public function isCookiePasword($cookiePassword) {

        // If this is a guest then it's immediately wrong
        if ($this->isGuest()) {
            return false;
        }

        // If they match then it's correct
        if ($this->getCookiePassword() == $cookiePassword) {
            return true;
        }

        // See if a temp password was recently issued
        $passwordTempTime = intval($this->row['password_temp_time']);
        if ($passwordTempTime + self::TEMP_PASSWORD_EXPIRE_TIME < time()) {
            return false;
        }

        // Check the temp password
        $transformedTempPassword = self::transformPassword($this->row['password_temp'], APP_COOKIE_SALT);
        if ($transformedTempPassword == $cookiePassword) {
            return true;
        }

        // It's simply not valid
        return false;
    }

    /**
     * Returns the version of the password that can be stored in a cookie.
     * It is a transformed version of the password hash using the cookie salt.
     * The cookie password needs to rely on both the global cookie salt as well
     * as the individual user's cookie salt.
     */
    public function getCookiePassword() {
        return self::transformPassword($this->row['password'], 
                $this->getCookieSalt() . APP_COOKIE_SALT);
    }

    /**
     * Returns whether or not this user is a guest.
     * @return boolean
     */
    public function isGuest() {
        if ($this->row == null) {
            return true;
        }
        return !(isset($this->row['userid']) && $this->row['userid'] != 0);
    }

    /**
     * Returns whether or not this user is an admin.
     * @return boolean
     */
    public function isAdmin() {
        if ($this->isGuest()) {
            return false;
        }
        return boolval($this->row['is_admin']);
    }
    
    /**
     * Returns the user's first name.
     * @return string
     */
    public function getFirstName() {
        if ($this->isGuest()) {
            return 'Guest';
        }
        return $this->row['first_name'];
    }

    /**
     * Returns the user's last name.
     * @return string
     */
    public function getLastName() {
        if ($this->isGuest()) {
            return 'Guest';
        }
        return $this->row['last_name'];
    }

    /**
     * Returns the user's full name.
     * @return string
     */
    public function getFullName() {
        if ($this->isGuest()) {
            return 'Guest';
        }
        return $this->getFirstName() . ' ' . $this->getLastName();
    }

    /**
     * Returns the user's email address.
     * @return string
     */
    public function getEmail() {
        if ($this->isGuest()) {
            return '';
        }
        return $this->row['email'];
    }

    /**
     * Returns whether or not the user's email has been verified.
     */
    public function isEmailVerified() {
        if ($this->isGuest()) {
            return false;
        }
        return boolval($this->row['email_verified']);
    }

    /**
     * Returns the userid for this user. 0 if a guest.
     * @return int
     */
    public function getUserId() {
        if ($this->isGuest()) {
            return 0;
        }
        return $this->row['userid'];
    }
    
    
    /**
     * Returns the user's salt.
     * @return string
     */
    private function getSalt() {
        if ($this->isGuest()) {
            return Utils::generateRandomId();
        }
        return $this->row['salt'];
    }
    
    /**
     * Returns the user's salt for cookies.
     * This is used for cookie passwords and changing
     * it should log this user out of all places.
     * @return string
     */
    private function getCookieSalt() {
        if ($this->isGuest()) {
            return Utils::generateRandomId();
        }
        return $this->row['salt_cookie'];
    }

    /**
     * Returns the time that this user was created.
     * @return int
     */
    public function getCreatedTime() {
        if ($this->isGuest()) {
            return 0;
        }
        return $this->row['created_at'];
    }

    /**
     * Returns the IP address that this user was created with.
     * @return string
     */
    public function getCreatedIP() {
        if ($this->isGuest()) {
            return Session::getIpAddress();
        }
        return $this->row['created_from'];
    }

    /**
     * Returns the time that this user just logged in at.
     * @return int
     */
    public function getCurrentVisitTime() {
        if ($this->isGuest()) {
            return 0;
        }
        return $this->row['current_visit_at'];
    }

    /**
     * Returns the IP address that this user just logged in with.
     * @return string
     */
    public function getCurrentVisitIP() {
        if ($this->isGuest()) {
            return Session::getIpAddress();
        }
        return $this->row['current_visit_from'];
    }

    /**
     * Returns the time that this user last visited.
     * @return int
     */
    public function getLastVisitTime() {
        if ($this->isGuest()) {
            return 0;
        }
        return $this->row['last_visit_at'];
    }

    /**
     * Returns the IP address that this user last logged in with.
     * @return string
     */
    public function getLastVisitIP() {
        if ($this->isGuest()) {
            return Session::getIpAddress();
        }
        return $this->row['last_visit_from'];
    }

    /**
     * Updates the visit info such as the current visit time and
     * the last visit time.
     * @return type
     */
    public function updateVisitInfo() {

        // Make sure this user isn't a guest
        if ($this->isGuest()) {
            return;
        }
        
        // Update the local info
        $this->row['last_visit_at'] = $this->row['current_visit_at'];
        $this->row['last_visit_from'] = $this->row['current_visit_from'];
        $this->row['current_visit_at'] = time();
        $this->row['current_visit_from'] = Session::getIpAddress();
        
        // Update on the database
        $query = Database::connection()->prepare('UPDATE user SET last_visit_at = ?,'
                . ' last_visit_from = ?, current_visit_at = ?, current_visit_from = ? WHERE userid = ?');
        $query->bindValue(1, $this->getLastVisitTime(), PDO::PARAM_INT);
        $query->bindValue(2, $this->getLastVisitIP(), PDO::PARAM_STR);
        $query->bindValue(3, $this->getCurrentVisitTime(), PDO::PARAM_INT);
        $query->bindValue(4, $this->getCurrentVisitIP(), PDO::PARAM_STR);
        $query->bindValue(5, $this->getUserId(), PDO::PARAM_INT);
        
        // This query isn't really important so we wont complain
        // if it doesnt execute properly
        $query->execute();
        
    }
    
    /**
     * Changes the user's current password and sends them an email letting them
     * know that it was changed.
     * @param string $newPassword The new password.
     * @throws Exception
     */
    public function changePassword($newPassword) {

        // Make sure we're not working with a guest account
        if ($this->isGuest()) {
            return;
        }

        // Transform the password
        $transformedPassword = self::transformPassword($newPassword, $this->getSalt());

        // Update the database
        // Making sure to invalidate the temporary password if it exists
        $query = Database::connection()->prepare('UPDATE user SET password = ?, password_temp_time = ? WHERE userid = ?');
        $query->bindValue(1, $transformedPassword, PDO::PARAM_STR);
        $query->bindValue(2, 0, PDO::PARAM_INT);
        $query->bindValue(3, $this->getUserId(), PDO::PARAM_INT);
        if (!$query->execute()) {
            throw new Exception('Unable to change password.');
        }

        // Update the local row
        $this->row['password'] = $transformedPassword;

        // Send an email letting them know
        $this->sendEmail('Your password has been changed', 'We are emailing you to let you know that your password has just been changed.');
    }

    /**
     * Creates a new temp password for this user and sends it to them.
     * @throws Exception
     */
    public function createTempPassword() {

        // Make sure we're not working with a guest account
        if ($this->isGuest()) {
            return;
        }

        // Create the new temp password
        $tempPassword = Utils::generateRandomId();
        $transformedPassword = self::transformPassword($tempPassword, $this->getSalt());
        $tempTime = time();

        // Update the database
        $query = Database::connection()->prepare('UPDATE user SET password_temp = ?, password_temp_time = ? WHERE userid = ?');
        $query->bindValue(1, $transformedPassword, PDO::PARAM_STR);
        $query->bindValue(2, $tempTime, PDO::PARAM_INT);
        $query->bindValue(3, $this->getUserId(), PDO::PARAM_INT);
        if (!$query->execute()) {
            throw new Exception('Unable to change temporary password.');
        }

        // Update the local row
        $this->row['password_temp'] = $transformedPassword;
        $this->row['password_temp_time'] = $tempTime;

        // Send the new temp password to the user
        $this->sendEmail('Your requested temporary password', 'Your temp password is ' . $tempPassword .
                ' and will only be valid for a short amount of time. You can use this temporary password to sign in and change your actual password.');
    }

    /**
     * Changes the user's email address and makes them verify it.
     * @param string $newEmail The new email address for the user.
     * @throws Exception
     */
    public function changeEmail($newEmail) {

        // Make sure the user isn't a guest
        if ($this->isGuest()) {
            return;
        }

        // Make sure the new email address is actually valid
        if (!Utils::isValidEmail($newEmail)) {
            throw new Exception('Invalid email address given.');
        }

        // Put the email in lower case, just to keep things the same
        $newEmail = strtolower($newEmail);

        // Update the email address in the database
        $query = Database::connection()->prepare('UPDATE user SET email = ? WHERE userid = ?');
        $query->bindValue(1, $newEmail, PDO::PARAM_STR);
        $query->bindValue(2, $this->getUserId(), PDO::PARAM_INT);
        if (!$query->execute()) {
            throw new Exception('Unable to update the email address.');
        }

        // Update the local row value
        $this->row['email'] = $newEmail;

        // Send out a new verification email to verify this email address
        if (!$this->sendVerificationEmail()) {
            throw new Exception('Unable to send verification email.');
        }
    }

    /**
     * Checks the given verification code and makes sure it matches
     * before it goes ahead and verifies the user's email address.
     * @param string $givenCode The code to check.
     * @return boolean Whether or not verification succeeded.
     */
    public function verifyEmail($givenCode) {

        // Make sure the email is not already verified
        if ($this->isEmailVerified()) {
            return false;
        }

        // Make sure the codes match
        if (strtolower($givenCode) != strtolower($this->row['email_token'])) {
            return false;
        }

        // Update the database
        $query = Database::connection()->prepare('UPDATE user SET email_verified = ? WHERE userid = ?');
        $query->bindValue(1, true, PDO::PARAM_BOOL);
        $query->bindValue(2, $this->getUserId(), PDO::PARAM_INT);
        if (!$query->execute()) {
            return false;
        }

        // Update the local row
        $this->row['email_verified'] = true;
        return true;
    }

    /**
     * Gets a new email verification code for this user and unverified
     * their currently set email address.
     * @return string
     * @throws Exception
     */
    private function getNewEmailVerificationCode() {

        // Make sure this is not a guest account
        if ($this->isGuest()) {
            throw new Exception('Guests cannot be sent verification codes.');
        }

        // Generate the new code
        $newCode = Utils::generateRandomId();

        // Update in the database
        $query = Database::connection()->prepare('UPDATE user SET email_verified = ?, email_token = ? WHERE userid = ?');
        $query->bindValue(1, false, PDO::PARAM_STR);
        $query->bindValue(2, $newCode, PDO::PARAM_BOOL);
        $query->bindValue(3, $this->getUserId(), PDO::PARAM_INT);
        if (!$query->execute()) {
            throw new Exception('Unable to update the verification code.');
        }

        // Update the local row values
        $this->row['email_verified'] = false;
        $this->row['email_token'] = $newCode;

        // Return the new code
        return $newCode;
    }

    /**
     * Sends the verification email to this user if their email addresss
     * has not already been verified.
     * @return boolean
     */
    public function sendVerificationEmail() {

        // Force the user to get a new verification code
        $newCode = $this->getNewEmailVerificationCode();

        // Now send it to them
        $url = APP_ABSOLUTE_URL . Routes::getControllerRelativeUrl('user#verify_email',
                array('userid' => $this->getUserId(), 'code' => $newCode));
        return $this->sendEmail('Please verify your email address',
                'You can verify your email address by clicking <a href="' . $url . '">here</a>');
    }

    /**
     * Sends an email to this user.
     * @param string $subject The subject of the email.
     * @param string $message The content of the email being sent.
     * @return boolean
     */
    public function sendEmail($subject, $message) {

        // Make sure we're not trying to send an email to a guest
        if ($this->isGuest()) {
            return;
        }

        // Make sure the email address is valid before we try sending anything
        if (!Utils::isValidEmail($this->getEmail())) {
            return false;
        }

        // Create the headers for the email
        $headers = array();
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "Content-type: text/html; charset=UTF-8";
        $headers[] = "From: " . APP_NAME . " <" . APP_EMAIL . ">";
        $headers[] = "Reply-To: " . APP_NAME . " <" . APP_EMAIL . ">";

        // Create the message to send using the email base tempalte
        $compiledMessage = View::compileView('email_base', array('subject' => $subject,
                    'message' => $message,
                    'app_name' => APP_NAME,
                    'user' => $this->row
        ));

        // Send the email
        return mail($this->getEmail(), $subject, $compiledMessage, implode("\r\n", $headers));
    }
    
    /**
     * Notifies this user.
     * @param string $message The message to notify them with.
     * @param string $controller The controller for the link, optional
     * @param string $replacements The replacements for the controller link, optional
     * @param string $imageUrl The url for the image for this notification, optional
     * @return Notification
     */
    public function notify($message, $controller = '', $replacements = array(), $imageUrl = '') {
        $notification = Notification::create($this, $message, $controller, $replacements, $imageUrl);
        Push::getPushServer(Auth::getUser())->emit($this->getUserId(), 'notification');
        return $notification;
    }

}
