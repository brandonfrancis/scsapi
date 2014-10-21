<?php

Routes::set('entry/get', 'entry#get');
Routes::set('entry/create', 'entry#create');
Routes::set('entry/delete', 'entry#delete');
Routes::set('entry/edit', 'entry#edit');

/**
 * Represents a course entry.
 */
class Entry {
    
    /**
     * The local database row.
     */
    private $row;
    
    /**
     * Gets a entry given its unique id.
     * @param int $entryid The entry id.
     * @return Entry
     */
    public static function fromId($entryid) {
        
        // See if theres a cache hit
        $cached = ObjCache::get(OBJCACHE_TYPE_ENTRY, $entryid);
        if ($cached != null) {
            return $cached;
        }
        
        // Do the database query
        $query = Database::connection()->prepare('SELECT * FROM entry WHERE entryid = ?');
        $query->bindValue(1, $entryid, PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            throw new Exception('Entry does not exist.');
        }
        
        // Cache it and return
        return self::fromRow($query->fetch());
    }
    
    /**
     * Gets a entry given its unique row.
     * @param array $row The database row to use.
     * @return Entry
     */
    public static function fromRow($row) {
        $entry = new Entry();
        $entry->row = $row;
        ObjCache::set(OBJCACHE_TYPE_COURSE, $entry->getEntryId(), $entry);
        return $entry;
    }
    
    /**
     * Gets all of the entries for a course.
     * @param Course $course The course to get the entries for.
     * @return Entry[]
     */
    public static function forCourse(Course $course) {
        $query = Database::connection()->prepare('SELECT * FROM entry WHERE courseid = ? ORDER BY display_at');
        $query->bindValue(1, $course->getCourseId(), PDO::PARAM_INT);
        $query->execute();
        $result = $query->fetchAll();
        $entries = array();
        foreach ($result as $row) {
            array_push($entries, self::fromRow($row));
        }
        return $entries;
    }
    
    /**
     * Creates a new entry.
     * @param User $creator The creator of the entry.
     * @param type $title The title of the entry.
     * @param type $description The entry description.
     * @return Entry
     * @throws Exception
     */
    public static function create(User $creator, Course $course, $title, $description) {
    
        // Insert it into the database
        $query = Database::connection()->prepare('INSERT INTO entry (courseid, created_at, created_by, display_at, title, description)'
                . ' VALUES (?, ?, ?, ?, ?, ?)');
        $query->bindValue(1, $course->getCourseId(), PDO::PARAM_INT);
        $query->bindValue(2, time(), PDO::PARAM_INT);
        $query->bindValue(3, $creator->getUserId(), PDO::PARAM_INT);
        $query->bindValue(4, time(), PDO::PARAM_INT);
        $query->bindValue(5, $title, PDO::PARAM_STR);
        $query->bindValue(6, $description, PDO::PARAM_STR);
        if (!$query->execute()) {
            throw new Exception('Entry could not be created in the database.');
        }
        
        // Get the course from the last insert id
        $entry = self::fromId(Database::connection()->lastInsertId());
        
        // Return the course
        return $entry;
        
    }
    
    /**
     * Gets called when this entry changes.
     */
    private function changed() {
        Sync::course(Course::fromId($this->getCourseId()));
    }
    
    /**
     * Constructs a new Entry object.
     */
    private function __construct() {
        $this->row = null;
        $this->users = null;
    }
    
    /**
     * Deletes this entry.
     */
    public function delete() {
        $query = Database::connection()->prepare('DELETE FROM entry WHERE entryid = ?');
        $query->bindValue(1, $this->getEntryId(), PDO::PARAM_INT);
        $query->execute();
        $this->changed();
        ObjCache::invalidate(OBJCACHE_TYPE_ENTRY, $this->getEntryId());
    }
    
    /**
     * Gets the context for this course.
     * @param User $user The user to get the context for.
     * @return array
     */
    public function getContext(User $user) {
        if (!$this->canView($user)) {
            return null;
        }
        $arry = array(
            'entryid' => $this->getEntryId(),
            'courseid' => $this->getCourseId(),
            'can_edit' => $this->canEdit($user),
            'is_due' => $this->hasDueTime(),
            'due_at' => $this->getDueTime(),
            'display_at' => $this->getDisplayTime(),
            'title' => $this->getTitle(),
            'description' => $this->getDescription(),
            'is_visible' => $this->isVisible()
        );
        if ($this->canView($user)) {
            
            // Add the questions with all of their answers
            $questions = Question::forEntry($this);
            $question_contexts = array();
            foreach ($questions as $question) {
                $context = $question->getContext($user);
                if ($context == null) { // checks to see if this user has access to this question
                    continue;
                }
                array_push($question_contexts, $context);
            }
            $arry['questions'] = $question_contexts;
            
        }
    }
    
    /**
     * Gets the id for this entry.
     * @return int
     */
    public function getEntryId() {
        return intval($this->row['entryid']);
    }
    
    /**
     * Gets the id for this course.
     * @return int
     */
    public function getCourseId() {
        return intval($this->row['courseid']);
    }
    
    /**
     * Gets the userid of the user that created this course.
     * @return int
     */
    public function getCreatorUserId() {
        return intval($this->row['created_by']);
    }
    
    /**
     * Returns whether or not this entry is set to be visible.
     * @return boolean
     */
    public function isVisible() {
        return boolval($this->row['visible']);
    }
        
    /**
     * Sets whether or not this entry should be visible.
     * @param boolean $value The value to set.
     */
    public function setVisible($value) {
        $value = boolval($value);
        if ($value == $this->isVisible()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE entry SET visible = ? WHERE entryid = ?');
        $query->bindValue(1, ($value ? 1 : 0), PDO::PARAM_BOOL);
        $query->bindValue(2, $this->getEntryId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['visible'] = $value;
        $this->changed();
    }
    
    /**
     * Determines whether or not a given user can view this entry.
     * @param User $user The user to check permissions for.
     * @return boolean
     */
    public function canView(User $user) {
        $course = Course::fromId($this->getCourseId());
        if ($course->canEdit($user)) {
            return true;
        }
        return ($course->canView($user) && $this->isVisible()) ||
                $user->getUserId() == $this->getCreatorUserId();
    }
    
    /**
     * Determines whether or not a given user can edit this entry.
     * @param User $user The user to check permissions for.
     * @return boolean
     */
    public function canEdit(User $user) {
        $course = Course::fromId($this->getCourseId());
        if ($course->canEdit($user)) {
            return true;
        }
        return $user->getUserId() == $this->getCreatorUserId();
    }
    
    /**
     * Gets the title of this entry.
     * @return string
     */
    public function getTitle() {
        return $this->row['title'];
    }
    
    /**
     * Sets the title of this entry.
     * @param string $newTitle The new title to set.
     */
    public function setTitle($newTitle) {
        if ($newTitle == $this->getTitle()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE entry SET title = ? WHERE entryid = ?');
        $query->bindValue(1, $newTitle, PDO::PARAM_STR);
        $query->bindValue(2, $this->getEntryId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['title'] = $newTitle;
        $this->changed();
    }
    
    /**
     * Gets the description of this entry.
     * @return string
     */
    public function getDescription() {
        return $this->row['description'];
    }
    
    /**
     * Sets the description of this entry.
     * @param string $newDescription The new description to set.
     */
    public function setDescription($newDescription) {
        if ($newDescription == $this->getDescription()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE entry SET description = ? WHERE entryid = ?');
        $query->bindValue(1, $newDescription, PDO::PARAM_STR);
        $query->bindValue(2, $this->getEntryId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['description'] = $newDescription;
        $this->changed();
    }
    
    /**
     * Determines whether or not this entry has a due date.
     * @return boolean
     */
    public function hasDueTime() {
        return $this->getDueTime() > 0;
    }
    
    /**
     * Gets the time that this entry is due.
     * @return int
     */
    public function getDueTime() {
        return intval($this->row['due_at']);
    }
    
    /**
     * Sets the time that this entry is due.
     * @param int $time The time to use.
     */
    public function setDueTime($time) {
        if ($time == $this->getDueTime()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE entry SET due_at = ? WHERE entryid = ?');
        $query->bindValue(1, $time, PDO::PARAM_INT);
        $query->bindValue(2, $this->getEntryId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['due_at'] = $time;
        $this->changed();
    }
    
    /**
     * Gets the time that this entry should be displayed at in the schedule.
     * @return int
     */
    public function getDisplayTime() {
        return intval($this->row['display_at']);
    }
    
    /**
     * Sets the time that this entry should be displayed at in the schedule.
     * @param int $time The time to use.
     */
    public function setDisplayTime($time) {
        if ($time == $this->getDisplayTime()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE entry SET display_at = ? WHERE entryid = ?');
        $query->bindValue(1, $time, PDO::PARAM_INT);
        $query->bindValue(2, $this->getEntryId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['display_at'] = $time;
        $this->changed();
    }
    
}
