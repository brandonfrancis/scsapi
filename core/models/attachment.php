<?php

// Routes for attachments are created by the specific functions
// using them to enforce which can be downloaded or viewed

/**
 * Class for handling attachments to users.
 */
class Attachment {
    
    const ATTACHMENT_TYPE_BINARY = 0;
    const ATTACHMENT_TYPE_IMAGE = 1;
    
    /**
     * Gets a attachment given its unique id.
     * @param int $attachmentid The attachment id.
     * @return \Attachment
     */
    public static function fromId($attachmentid) {
        $query = Database::connection()->prepare('SELECT * FROM attachment WHERE attachmentid = ?');
        $query->bindValue(1, $attachmentid, PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            throw new Exception('Attachment does not exist.');
        }
        return self::fromRow($query->fetch());
    }
    
    /**
     * Gets an attachment given its database row.
     * @param array $row
     * @return \Attachment
     */
    public static function fromRow($row) {
        $attachment = new Attachment();
        $attachment->row = $row;
        ObjCache::set(OBJCACHE_TYPE_ATTACHMENT, $attachment->getAttachmentId(), $attachment);
        return $attachment;
    }
    
    /**
     * Handles uploads to the system.
     * @return Attachment[]
     * @throws Exceptions
     */
    public static function handleUpload() {

        // Make sure the user isn't a guest
        if (Auth::getUser()->isGuest()) {
            throw new Exception('Guests cannot upload attachments.');
        }

        // Create the array to return
        $attachments = array();

        if (is_array($_FILES['uploaded_attachments']['tmp_name'])) {
            
            // If we're handling multiple images in one post
            for ($i = 0; $i < count($_FILES['uploaded_attachments']['tmp_name']); $i++) {
                $error = $_FILES['uploaded_attachments']['error'][$i];
                if ($error == UPLOAD_ERR_OK) {
                    $tmp_name = $_FILES['uploaded_attachments']['tmp_name'][$i];
                    $name = $_FILES['uploaded_attachments']['name'];
                    $attachments[] = self::create(Auth::getUser(), $tmp_name, $name);
                }
            }
            
        } else {
            
            // Handle only a single upload
            $error = $_FILES['uploaded_attachments']['error'];
            if ($error == UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['uploaded_attachments']['tmp_name'];
                $name = $_FILES['uploaded_attachments']['name'];
                $attachments[] = self::create(Auth::getUser(), $tmp_name, $name);
            }
            
        }
        
        // Return the array that was built
        return $attachments;
        
    }
    

    public static function create(User $user, $uploaded_filename, $name) {
        
        // Create a new row in the database for the image
        $query = Database::connection()->prepare('INSERT INTO attachment (size, name, created_by, created_at) VALUES (?, ?, ?, ?)');
        $query->bindValue(1, filesize($uploaded_filename), PDO::PARAM_INT);
        $query->bindValue(2, $name, PDO::PARAM_INT);
        $query->bindValue(3, $user->getUserId(), PDO::PARAM_INT);
        $query->bindValue(4, time(), PDO::PARAM_INT);
        if (!$query->execute()) {
            throw new Exception('Unable to create attachment.');
        }

        // Get the image that we just created
        $attachment = Attachment::fromId(Database::connection()->lastInsertId());

        if (is_uploaded_file($uploaded_filename)) {
            if (!move_uploaded_file($uploaded_filename, self::getStoragePath($attachment->getAttachmentId()))) {
                $attachment->delete();
                throw new Exception('Unable to move attachment into place.');
            }
        } else {
            if (!rename($uploaded_filename, self::getStoragePath($attachment->getAttachmentId()))) {
                $attachment->delete();
                throw new Exception('Unable to move attachment into place.');
            }
        }

        //$imagick = new Imagick();
        //$imagick->readimage(self::getStoragePath($image->getId()));
        //$imagick->setimagetype('png');
        //$imagick->writeimage();
        
        return $attachment;
        
    }
    
     /**
     * Returns the path for a specific attachment id.
     * @param int $attachmentid
     */
    public static function getStoragePath($attachmentid) {
        return APP_STORAGE_PATH . '/attachments/' . $attachmentid . '.dat';
    }
    
    /**
     * Creates a new Notification object.
     */
    private function __construct() {
        $this->row = null;
    }
    
    /**
     * Gets the id of this attachment.
     * @return int
     */
    public function getAttachmentId() {
        return intval($this->row['attachmentid']);
    }
    
    /**
     * Gets the creation time for this attachment.
     * @return int
     */
    public function getCreationTime() {
        return intval($this->row['created_at']);
    }
    
    /**
     * Gets the user id of the owner of this attachment.
     * @return int
     */
    public function getOwnerUserId() {
        return intval($this->row['created_by']);
    }

    /**
     * Gets the name of this attachment.
     */
    public function getName() {
        return $this->row['name'];
    }
    
    /**
     * Gets the size of this attachment.
     * @return int
     */
    public function getSize() {
        return intval($this->row['size']);
    }
    
    /**
     * Gets the type of file this 
     * @return int
     */
    public function getAttachmentType() {
        if (getimagesize(self::getStoragePath($this->getAttachmentId())) === FALSE) {
            return self::ATTACHMENT_TYPE_BINARY;
        }
        return self::ATTACHMENT_TYPE_IMAGE;
    }
    
    /**
     * Gets the context for this attachment.
     * @param User $user
     * @return array
     */
    public function getContext() {
        return array(
            'attachmentid' => $this->getAttachmentId(),
            'created_by' => $this->getOwnerUserId(),
            'created_at' => $this->getCreationTime(),
            'size' => $this->getSize(),
            'name' => $this->getName()
        );
    }
    
    /**
     * Deletes this attachment from the system.
     * @throws Exception
     */
    public function delete() {
        
        // Delete the image from the database
        $query = Database::connection()->prepare('DELETE FROM attachment WHERE attachmentid = ?');
        $query->bindValue(1, $this->getAttachmentId(), PDO::PARAM_INT);
        if (!$query->execute()) {
            throw new Exception('Unable to delete attachment.');
        }
        
        // Delete the image from the disk
        $filename = self::getStoragePath($this->getAttachmentId());
        if (file_exists($filename)) {
            unlink($filename);
        }
        
        // Invalidate the cache version
        ObjCache::invalidate(OBJCACHE_TYPE_ATTACHMENT, $this->getAttachmentId());
        
    }
    
}