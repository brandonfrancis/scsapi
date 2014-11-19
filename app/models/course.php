<?php

Routes::set('course/get_list', 'course#get_list');
Routes::set('course/get', 'course#get');
Routes::set('course/edit', 'course#edit');
Routes::set('course/add_students', 'course#add_students');
Routes::set('course/add_professor', 'course#add_professor');
Routes::set('course/remove_student', 'course#remove_student');
Routes::set('course/create', 'course#create');

/**
 * Represents a school course.
 */
class Course {
    
    /**
     * The local database row.
     */
    private $row;
    
    /**
     * The array of users who are in this course.
     * @var User[]
     */
    private $users;
    
    /**
     * The array of users who are professors for this course.
     * @var User[] 
     */
    private $professors;
    
    /**
     * Gets a course given its unique id.
     * @param int $courseid The course id.
     * @return Course
     */
    public static function fromId($courseid) {
        
        // See if theres a cache hit
        $cached = ObjCache::get(OBJCACHE_TYPE_COURSE, $courseid);
        if ($cached != null) {
            return $cached;
        }
        
        // Do the database query
        $query = Database::connection()->prepare('SELECT * FROM course WHERE courseid = ?');
        $query->bindValue(1, $courseid, PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            throw new Exception('Course does not exist.');
        }
        
        // Cache it and return
        return self::fromRow($query->fetch());
    }
    
    /**
     * Gets a course given its unique row.
     * @param array $row The database row to use.
     * @return Course
     */
    public static function fromRow($row) {
        $course = new Course();
        $course->row = $row;
        ObjCache::set(OBJCACHE_TYPE_COURSE, $course->getCourseId(), $course);
        return $course;
    }
    
    /**
     * Gets an array of courses for the given user.
     * @param User $user The user to use.
     * @return Course
     */
    public static function forUser(User $user) {
        
        // Do the query
        $query = Database::connection()->prepare('SELECT course.* FROM course, course_user'
                . ' WHERE (course.courseid = course_user.courseid AND course_user.userid = ?) OR (course.created_by = ?) ORDER BY course.title');
        $query->bindValue(1, $user->getUserId(), PDO::PARAM_INT);
        $query->bindValue(2, $user->getUserId(), PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            return array();
        }
        
        // Build the array of courses
        $courses = array();
        $result = $query->fetchAll();
        foreach ($result as $row) {
            array_push($courses, self::fromRow($row));
        }
        
        // Return the array
        return $courses;
    }
    
    /**
     * Creates a new course.
     * @param User $creator The creator of the course.
     * @param type $title The title of the course.
     * @param type $code The course identification code.
     * @return Course
     * @throws Exception
     */
    public static function create(User $creator, $title, $code) {
    
        // Insert it into the database
        $query = Database::connection()->prepare('INSERT INTO course (created_at, created_by, title, code)'
                . ' VALUES (?, ?, ?, ?)');
        $query->bindValue(1, time(), PDO::PARAM_INT);
        $query->bindValue(2, $creator->getUserId(), PDO::PARAM_INT);
        $query->bindValue(3, $title, PDO::PARAM_STR);
        $query->bindValue(4, $code, PDO::PARAM_STR);
        if (!$query->execute()) {
            throw new Exception('Course could not be created in the database.');
        }
        
        // Get the course from the last insert id
        $course = self::fromId(Database::connection()->lastInsertId());
        
        // Add the creator as a professor
        $course->addProfessor($creator);
                
        // Return the course
        return $course;
        
    }
    
    /**
     * Called when this course has been updated in any way that should be
     * broadcast to all students and professors in the class.
     */
    public function perform_sync() {
        
        // Let's make sure we have the users
        $this->getCourseUsers();
        
        // Go through all of the students and professors
        foreach ($this->users as $user) {
            Sync::emit($user, 'course', $this->getCourseId(), $this->getContext($user));
        }
        foreach ($this->professors as $user) {
            Sync::emit($user, 'course', $this->getCourseId(), $this->getContext($user));
        }
        
        // Sync to all admins
        foreach (User::admins() as $user) {
            Sync::emit($user, 'course', $this->getCourseId(), $this->getContext($user));
        }
        
        
    }
    
    /**
     * Updates the array of users who are in this course.
     * @param Course $course
     */
    private function getCourseUsers() {
        
        // If we already have the results don't do anything
        if ($this->users != null && $this->professors != null) {
            return;
        }
        
        // Query the database for all of the user rows in the course
        $query = Database::connection()->prepare('SELECT user.*, course_user.is_professor FROM course_user, user '
                . 'WHERE course_user.courseid = ? AND user.userid = course_user.userid ORDER BY user.userid');
        $query->bindValue(1, $this->getCourseId(), PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            return array();
        }
        
        // Reset the arrays
        $this->users = array();
        $this->professors = array();
        
        // Go through and add the user to the correct array
        $result = $query->fetchAll();
        foreach ($result as $row) {
            $user = User::fromRow($row);
            if (boolval($row['is_professor'])) {
                $this->professors[$user->getUserId()] = $user;
            } else {
                $this->users[$user->getUserId()] = $user;
            }
        }
        
    }
    
    /**
     * Gets called when this course changes.
     */
    private function changed() {
        Sync::course($this);
    }
    
    /**
     * Constructs a new Course object.
     */
    private function __construct() {
        $this->row = null;
        $this->users = null;
        $this->getCourseUsers();
    }
    
    /**
     * Gets the context for this course.
     * @param User $user The user to get the context for.
     * @return array
     */
    public function getContext(User $user) {
        
        $array = array(
            'courseid' => $this->getCourseId(),
            'can_view' => $this->canView($user),
            'can_edit' => $this->canEdit($user),
            'title' => $this->getTitle(),
            'code' => $this->getCode()
        );
        
        // See if this user can view the course and add the other course info
        if ($this->canView($user)) {
            
            // Add the entries with all of their questions and answers
            $entries = Entry::forCourse($this);
            $entry_contexts = array();
            foreach ($entries as $entry) {
                $context = $entry->getContext($user);
                if ($context == null) {
                    continue;
                }
                array_push($entry_contexts, $context);
            }
            $array['entries'] = $entry_contexts;
            
            // Put the professor and users into the context
            $professor_contexts = array();
            foreach ($this->professors as $professor) {
                array_push($professor_contexts, $professor->getContext($user));
            }
            $student_contexts = array();
            foreach ($this->users as $student) {
                array_push($student_contexts, $student->getContext($user));
            }
            $array['professors'] = $professor_contexts;
            $array['students'] = $student_contexts;
            
        }

        // Return the context
        return $array;
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
     * Adds a user as a student to this course.
     * @param User $user The user to add.
     */
    public function addStudent(User $user) {
        
        // See if permissions need to be lowered
        if ($this->canEdit($user)) {
            $this->removeUser($user);
        }
        
        // Add the user
        $this->addUser($user, false);
    }
    
    /**
     * Adds a user as a professor to this course.
     * @param User $user The user to add.
     */
    public function addProfessor(User $user) {
        
        // See if permissions need to be elevated
        if ($this->canView($user) && !$this->canEdit($user)) {
            $this->removeUser($user);
        }
        
        // Add the user
        $this->addUser($user, true);
    }
    
    /**
     * Adds a user to this course.
     * @param User $user The user to add.
     * @param type $is_professor Whether or not this user should be a professor.
     * @throws Exception
     */
    private function addUser(User $user, $is_professor) {
        $is_professor = boolval($is_professor);
        
        // Make sure the user isn't already in the course
        if ($this->canView($user) || $user->isGuest()) {
            return;
        }
        
        // Do the query
        $query = Database::connection()->prepare('INSERT INTO course_user (courseid, userid, is_professor, created_at) VALUES (?, ?, ?, ?)');
        $query->bindValue(1, $this->getCourseId(), PDO::PARAM_INT);
        $query->bindValue(2, $user->getUserId(), PDO::PARAM_INT);
        $query->bindValue(3, $is_professor, PDO::PARAM_BOOL);
        $query->bindValue(4, time(), PDO::PARAM_INT);
        if (!$query->execute()) {
            throw new Exception('Could not add user to course.');
        }
        
        // Add them to the local array
        if ($is_professor) {
            $this->professors[$user->getUserId()] = $user;
        } else {
            $this->users[$user->getUserId()] = $user;
        }
        
        // We should now sync
        $this->changed();
        
    }
    
    /**
     * Removes a user from this course.
     * @param User $user The user to remove.
     * @throws Exception
     */
    public function removeUser(User $user) {
                
        // Make sure they're part of the class already
        if (!$this->canView($user)) {
            return;
        }
        
        // Do the query
        $query = Database::connection()->prepare('DELETE FROM course_user WHERE courseid = ? AND userid = ?');
        $query->bindValue(1, $this->getCourseId(), PDO::PARAM_INT);
        $query->bindValue(2, $user->getUserId(), PDO::PARAM_INT);
        if (!$query->execute()) {
            throw new Exception('Could not remove user from course.');
        }
        
        // Remove them from the local arrays
        if (key_exists($user->getUserId(), $this->professors)) {
            unset($this->professors[$user->getUserId()]);
        }
        if (key_exists($user->getUserId(), $this->users)) {
            unset($this->users[$user->getUserId()]);
        }
        
        // Now let's sync
        $this->changed();
        
    }
    
    /**
     * Determines whether or not a given user can view this course.
     * @param User $user The user to check permissions for.
     * @return boolean
     */
    public function canView(User $user) {
        $this->getCourseUsers();
        return key_exists($user->getUserId(), $this->users) || $this->canEdit($user);
    }
    
    /**
     * Determines whether or not a given user can edit this course.
     * @param User $user The user to check permissions for.
     * @return boolean
     */
    public function canEdit(User $user) {
        if ($user->isAdmin()) {
            return true;
        }
        $this->getCourseUsers();
        return key_exists($user->getUserId(), $this->professors);
    }
    
    /**
     * Gets the title of this course.
     * @return string
     */
    public function getTitle() {
        return $this->row['title'];
    }
    
    /**
     * Sets the title of this course.
     * @param string $newTitle The new title to set.
     */
    public function setTitle($newTitle) {
        if ($newTitle == $this->getTitle()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE course SET title = ? WHERE courseid = ?');
        $query->bindValue(1, $newTitle, PDO::PARAM_STR);
        $query->bindValue(2, $this->getCourseId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['title'] = $newTitle;
        $this->changed();
    }
    
    /**
     * Gets the course identification code for this course.
     * @return string
     */
    public function getCode() {
        return $this->row['code'];
    }
    
    /**
     * Sets the course identification code of this course.
     * @param string $newCode The new code to set.
     */
    public function setCode($newCode) {
        if ($newCode == $this->getCode()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE course SET code = ? WHERE courseid = ?');
        $query->bindValue(1, $newCode, PDO::PARAM_STR);
        $query->bindValue(2, $this->getCourseId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['code'] = $newCode;
        $this->changed();
    }
    
}
