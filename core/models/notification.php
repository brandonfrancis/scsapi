<?php

// Set the needed routes
Routes::set('notifications/get', 'notifications#get');
Routes::set('notifications/clear', 'notifications#clear');
Routes::set('notifications/push', 'notifications#get_push_ticket');

/**
 * Class for sending notifications to users.
 */
class Notification {
    
    /**
     * 180 days
     */
    const NOTIFICATION_EXPIRE = 15552000;
    
    /**
     * The maximum amount of notifications to show.
     */
    const NOTIFICAITON_MAX = 30;
    
    /**
     * The local database row.
     */
    private $row = null;
    
    /**
     * Gets a notification given it's unique id.
     * @param type $notificationid The notification id.
     * @return \Notification
     */
    public static function fromId($notificationid) {
        $query = Database::connection()->prepare('SELECT * FROM user_notification WHERE notificationid = ?');
        $query->bindValue(1, $notificationid, PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            throw new Exception('Notification does not exist.');
        }
        $notification = new Notification();
        $notification->row = $query->fetch();
        return $notification;
    }
    
    /**
     * Gets all of the notifications for a given user. Does not exceed the max amount of notifications.
     * @param User $user The user to get the notifications for.
     * @return \Notification
     */
    public static function forUser(User $user) {
        $query = Database::connection()->prepare('SELECT * FROM user_notification WHERE userid = ? ORDER BY created_at DESC LIMIT ?');
        $query->bindValue(1, $user->getUserId(), PDO::PARAM_INT);
        $query->bindValue(2, self::NOTIFICAITON_MAX, PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            return array();
        }
        $results = $query->fetchAll();
        $notifications = array();
        foreach ($results as $result) {
            $notification = new Notification();
            $notification->row = $result;
            $notifications[] = $notification;
        }
        return $notifications;
    }
    
    /**
     * Marks all notifications for a user as read.
     * @param User $user
     */
    public static function markAllRead(User $user) {
        $query = Database::connection()->prepare('UPDATE user_notification SET read_at = ? WHERE userid = ? AND read_at = 0');
        $query->bindValue(1, time(), PDO::PARAM_INT);
        $query->bindValue(2, $user->getUserId(), PDO::PARAM_INT);
        $query->execute();
    }
    
    /**
     * Purges out all of the old expired notifications, whether they've been read or not.
     */
    public static function purge() {
        $query = Database::connection()->prepare('DELETE FROM user_notification WHERE created_at < ?');
        $query->bindValue(1, time() - self::NOTIFICATION_EXPIRE, PDO::PARAM_INT);
        $query->execute();
    }
    
    /**
     * Creates a new Notification object.
     */
    private function __construct() {
        $this->row = null;
    }
    
    /**
     * Creates a new notification for a user.
     * @param User $user The user to notify.
     * @param type $message The message to deliver.
     * @return Notificaton
     * @throws Exception
     */
    public static function create(User $user, $message, $controller = '', $replacements = array(), $imageurl = '') {
        
        // Make sure the user is not a guest
        if ($user->isGuest()) {
            throw new Exception('Notifications cannot be created for guests.');
        }
        
        $link = '';
        if (!empty($controller)) {
            $link = Routes::getControllerRelativeUrl($controller, $replacements);
        }
        
        // Add the notification to the database
        $query = Database::connection()->prepare('INSERT INTO user_notification (userid, created_at, message, link, image_url) VALUES (?, ?, ?, ?, ?)');
        $query->bindValue(1, $user->getUserId(), PDO::PARAM_INT);
        $query->bindValue(2, time(), PDO::PARAM_INT);
        $query->bindValue(3, $message, PDO::PARAM_STR);
        $query->bindValue(4, $link, PDO::PARAM_STR);
        $query->bindValue(5, $imageurl, PDO::PARAM_STR);
        if (!$query->execute()) {
            throw new Exception('Notification could not be created.');
        }
        
        // Let's send an email right away
        return Notification::fromId(Database::connection()->lastInsertId());
        
    }
    
    /**
     * Sends an email to the user telling them they have a new notification.
     */
    public function sendEmail() {
        
        // Make sure we can send the email
        $user = User::fromId($this->getUserId());
        if ($user->isGuest() || $this->hasBeenEmailed()) {
            return;
        }
        
        // Send the email
        if (!empty($this->getLink())) {
            $user->sendEmail('New notification!', 'You have received a new notification!<br />'
                    . '"' . $this->getMessage() . '"<br /> Click <a href="' . APP_ABSOLUTE_URL . $this->getLink() . '">here</a> for more info.');
        } else {
            $user->sendEmail('New notification!', 'You have received a new notification!<br />'
                    . '"' . $this->getMessage() . '"<br /> Click <a href="' . APP_ABSOLUTE_URL . APP_RELATIVE_URL . '">here</a> for more info.');
        }
        
        // Update the database
        $query = Database::connection()->prepare('UPDATE user_notification SET emailed_at = ? WHERE notificationid = ?');
        $query->bindValue(1, time(), PDO::PARAM_INT);
        $query->bindValue(2, $this->getId(), PDO::PARAM_INT);
        $query->execute();
        
        // Update the local info
        $this->row['emailed_at'] = time();
        
    }
    
    /**
     * Gets the context for the view.
     */
    public function getContext() {
        return array(
            'id' => $this->getId(),
            'date' => $this->getCreatedTime(),
            'has_read' => $this->hasBeenRead(),
            'link' => $this->getLink(),
            'message' => $this->getMessage(),
            'image_url' => $this->getImageUrl()
        );
    }
    
    /**
     * Gets the id of this notification.
     */
    public function getId() {
        return $this->row['notificationid'];
    }
    
    /**
     * Gets whether or not this notification has been emailed to the user.
     * @return boolean
     */
    public function hasBeenEmailed() {
        return intval($this->row['emailed_at']) > 0;
    }
    
    /**
     * Returns whether or not this notification has been read.
     * @return boolean
     */
    public function hasBeenRead() {
        return intval($this->row['read_at']) > 0;
    }

    /**
     * Gets the time that this notification was created.
     * @return int
     */
    public function getCreatedTime() {
        return intval($this->row['created_at']);
    }
    
    /**
     * Gets the message associated with this notification.
     * @return string
     */
    public function getMessage() {
        return $this->row['message'];
    }
    
    /**
     * Returns the userid for the user this notification is for.
     * @return int
     */
    public function getUserId() {
        return $this->row['userid'];
    }
    
    /**
     * Gets the image url, if there is one, for this notification.
     * @return int
     */
    public function getImageUrl() {
        return $this->row['image_url'];
    }
    
    /**
     * Returns the link for this notification.
     */
    public function getLink() {
        return $this->row['link'];
    }
    
}