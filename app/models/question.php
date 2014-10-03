<?php

/**
 * Represnets a question that can be asked.
 */
class Question {
    
     /**
     * The local database row.
     */
    private $row;
    
    /**
     * The cache of answers.
     * @var QuestionAnswer[]
     */
    private $answerCache;
    
    /**
     * Gets a question given its unique id.
     * @param int $questionid The question id.
     * @return Question
     */
    public static function fromId($questionid) {
                
        // See if theres a cache hit
        $cached = ObjCache::get(OBJCACHE_TYPE_QUESTION, $questionid);
        if ($cached != null) {
            return $cached;
        }
        
        // Do the database query
        $query = Database::connection()->prepare('SELECT * FROM question WHERE questionid = ?');
        $query->bindValue(1, $questionid, PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            throw new Exception('Question does not exist.');
        }
        
        // Return it
        return self::fromRow($query->fetch());
    }
    
    /**
     * Gets a list of questions for a given course.
     * @param Course $course The course to get the questions for.
     * @return Question[]
     */
    public static function forCourse(Course $course) {
        
        // Do the database query
        $query = Database::connection()->prepare('SELECT * FROM question WHERE courseid = ? ORDER BY created_at DESC');
        $query->bindValue(1, $course->getCourseId(), PDO::PARAM_INT);
        $query->execute();
        
        // Convert the result
        $questions = array();
        $result = $query->fetchAll();
        foreach ($result as $row) {
            array_push($questions, Question::fromRow($row));
        }
        
        // Return the result
        return $questions;
    }
    
    /**
     * Gets a question given its unique row.
     * @param array $row The database row to use.
     * @return Question
     */
    public static function fromRow($row) {
        $question = new Question();
        $question->row = $row;
        ObjCache::set(OBJCACHE_TYPE_QUESTION, $question->getQuestionId(), $question);
        return $question;
    }
    
    /**
     * Creates a new question.
     * @param User $creator The asker.
     * @param Course $course The course it belongs to.
     * @param string $text The text of the question being asked.
     * @param boolean $private Whether or not this question is private.
     * @throws Exception
     */
    public static function create(User $creator, Course $course, $text, $private = false) {
        $private = boolval($private);
        
        // Start the transaction
        Database::connection()->beginTransaction();
        
        // Do the database insert
        $query = Database::connection()->prepare('INSERT INTO question (courseid, is_private, created_at) VALUES (?, ?, ?)');
        $query->bindValue(1, $course->getCourseId(), PDO::PARAM_INT);
        $query->bindValue(2, $private, PDO::PARAM_BOOL);
        $query->bindValue(3, time(), PDO::PARAM_INT);
        if (!$query->execute()) {
            Database::connection()->rollBack();
            throw new Exception('Question could not be inserted into the database.');
        }
        
        // Get the question we just made
        $question = Question::fromId(Database::connection()->lastInsertId());
        
        // Now create the first answer to the question
        $answer = null;
        try {
            
            // Create the answer
            $answer = QuestionAnswer::create($question, $creator, $text);
            
        } catch (Exception $ex) {
            
            // If it fails we roll back
            Database::connection()->rollBack();
            throw $ex;
            
        }
        
        // Now set the first answer for the question
        $query = Database::connection()->prepare('UPDATE question SET first_answer = ? WHERE questionid = ?');
        $query->bindValue(1, $answer->getAnswerId(), PDO::PARAM_INT);
        $query->bindValue(2, $question->getQuestionId(), PDO::PARAM_INT);
        if (!$query->execute()) {
            Database::connection()->rollBack();
            throw new Exception('Could not set first answer field.');
        }
        
        // Commit to the database and set the question as changed
        Database::connection()->commit();
        $question->changed();
        
        // Return the created question
        return $question;
        
    }
    
    /**
     * Gets called when the question changes.
     */
    private function changed() {
        Sync::course(Course::fromId($this->getCourseId()));
    }
    
    /**
     * Constructs a new Question object.
     */
    private function __construct() {
        $this->row = null;
        $this->answerCache = null;
    }
    
    /**
     * Gets the context for this question.
     * @param User $user The user to get the context for.
     * @return array
     */
    public function getContext(User $user) {
        if (!$this->canView($user)) {
            return null;
        }
        $answers = $this->getAnswers();
        $answers_contexts = array();
        foreach ($answers as $answer) {
            array_push($answers_contexts, $answer->getContext($user));
        }
        return array(   
            'questionid' => $this->getQuestionId(),
            'courseid' => $this->getCourseId(),
            'title' => $this->getTitle(),
            'part_of_assignment' => $this->belongsToAssignment(),
            'assignmentid' => $this->getAssignmentId(),
            'is_private' => $this->isPrivate(),
            'is_closed' => $this->isClosed(),
            'answers' => $answers_contexts
        );
    }
    
    /**
     * Gets the answers to this question.
     * @return QuestionAnswer
     */
    public function getAnswers() {
        if ($this->answerCache != null) {
            return $this->answerCache;
        }
        $this->answerCache = QuestionAnswer::forQuestion($this);
        return $this->answerCache;
    }
    
    /**
     * Forces the answer cache to be invalidated and refetched on next access.
     */
    public function invalidateAnswerCache() {
        $this->answerCache = null;
    }
    
    /**
     * Gets the id of this question.
     * @return int
     */
    public function getQuestionId() {
        return intval($this->row['questionid']);
    }
    
    /**
     * Gets the id of the course this question belongs to.
     * @return int
     */
    public function getCourseId() {
        return intval($this->row['courseid']);
    }
    
    /**
     * Gets the title of this question.
     * @return string
     */
    public function getTitle() {
        return $this->row['title'];
    }
    
    /**
     * Sets the title of this question.
     * @param string $newTitle The new title to set.
     */
    public function setTitle($newTitle) {
        if ($newTitle == $this->getTitle()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE question SET title = ? WHERE questionid = ?');
        $query->bindValue(1, $newTitle, PDO::PARAM_STR);
        $query->bindValue(2, $this->getQuestionId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['title'] = $newTitle;
        $this->changed();
    }
    
    /**
     * Determines whether or not this question belongs to an assignment.
     * @return boolean
     */
    public function belongsToAssignment() {
        return $this->row['assignmentid'] != null;
    }
    
    /**
     * Gets the assignment id of this question if it exists.
     * @return int
     */
    public function getAssignmentId() {
        if (!$this->belongsToAssignment()) {
            return 0;
        }
        return intval($this->row['assignmentid']);
    }
    
    /**
     * Determines whether or not this question is private.
     * @return boolean
     */
    public function isPrivate() {
        return boolval($this->row['is_private']);
    }
    
    /**
     * Sets whether or not this question is private.
     * @param boolean $value The value to set it to.
     */
    public function setPrivate($value) {
        $value = boolval($value);
        if ($value == $this->isPrivate()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE question SET is_private = ? WHERE questionid = ?');
        $query->bindValue(1, $value, PDO::PARAM_BOOL);
        $query->bindValue(2, $this->getQuestionId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['is_private'] = $value;
        $this->changed();
    }
    
    /**
     * Determines whether or not this questions is closed.
     * @return boolean
     */
    public function isClosed() {
        return boolval($this->row['is_closed']);
    }
    
    /**
     * Sets whether or not this question is closed.
     * @param boolean $value The value to set it to.
     */
    public function setClosed($value) {
        $value = boolval($value);
        if ($value == $this->isClosed()) {
            return;
        }
        $query = Database::connection()->prepare('UPDATE question SET is_closed = ? WHERE questionid = ?');
        $query->bindValue(1, $value, PDO::PARAM_BOOL);
        $query->bindValue(2, $this->getQuestionId(), PDO::PARAM_INT);
        $query->execute();
        $this->row['is_closed'] = $value;
        $this->changed();
    }
    
    /**
     * Determines whether or not a given user can view this question.
     * @param User $user The user to check.
     * @return boolean
     */
    public function canView(User $user) {
        
        // Make sure they are in the course
        $course = Course::fromId($this->getCourseId());
        if (!$course->canView($user)) {
            return false;
        }
        
        // If it is private they need to be the asker or a professor
        if ($this->isPrivate()) {
            
            // See if they are the professor
            if ($course->canEdit($user)) {
                return true;
            }
            
            // See if they are the asker
            $answers = $this->getAnswers();
            if (count($answers) == 0 || 
                    $answers[0]->getUserId() != $user->getUserId()) {
                return false;
            }
            
        }
        
        // They can view the question
        return true;
    }
    
    /**
     * Determines whether or not a user can answer this question.
     * @param User $user The user to check.
     * @return boolean
     */
    public function canAnswer(User $user) {
        
        // See if they cannot view the question
        if (!$this->canView($user)) {
            return false;
        }

        // If this question is closed we need to dig deeper
        if ($this->isClosed()) {
            
            // See if they can edit the course
            $course = Course::fromId($this->getCourseId());
            if (!$course->canEdit($user)) {
                return false;
            }
            
        }

        // They can answer the question
        return true;
    }
    
}

/**
 * Represents an answer to a question.
 */
class QuestionAnswer {
    
    /**
     * The local database row.
     */
    private $row;
    
    /**
     * Gets a answer given its unique id.
     * @param int $answerid The answer id.
     * @return Answer
     */
    public static function fromId($answerid) {
                
        // See if theres a cache hit
        $cached = ObjCache::get(OBJCACHE_TYPE_ANSWER, $answerid);
        if ($cached != null) {
            return $cached;
        }
        
        // Do the database query
        $query = Database::connection()->prepare('SELECT * FROM question_answer WHERE answerid = ?');
        $query->bindValue(1, $answerid, PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            throw new Exception('Answer does not exist.');
        }
        return self::fromRow($query->fetch());
    }
    
    /**
     * Gets an answer given its unique row.
     * @param array $row The database row to use.
     * @return QuestionAnswer
     */
    public static function fromRow($row) {
        $answer = new QuestionAnswer();
        $answer->row = $row;
        ObjCache::set(OBJCACHE_TYPE_ANSWER, $answer->getAnswerId(), $answer);
        return $answer;
    }
    
    /**
     * Gets an array of answers for the given question.
     * @param Question $question The question to use.
     * @return QuestionAnswer[]
     */
    public static function forQuestion(Question $question) {
        
        // Do the query
        $query = Database::connection()->prepare('SELECT * FROM question_answer WHERE questionid = ? ORDER BY created_at');
        $query->bindValue(1, $question->getQuestionId(), PDO::PARAM_INT);
        if (!$query->execute() || $query->rowCount() == 0) {
            return array();
        }
        
        // Build the array of answers
        $answers = array();
        $result = $query->fetchAll();
        foreach ($result as $row) {
            array_push($answers, self::fromRow($row));
        }
        
        // Return the array
        return $answers;
    }
    
    /**
     * Creates a new answer to a question.
     * @param Question $question The question to answer to.
     * @param User $creator The creator of the answer.
     * @param type $text The text for the answer.
     * @return QuestionAnswer
     * @throws Exception
     */
    public static function create(Question $question, User $creator, $text) {
        
        // Make sure the creator is allowed to post in the question
        if (!$question->canAnswer($creator)) {
            throw new Exception('Cannot answer this question.');
        }
        
        // Sanitate the text and make sure it fits the requirements
        $text = self::sanitateText($text);
        if (!self::checkText($text)) {
            throw new Exception('Answer text is not valid.');
        }
        
        // Do the database insert
        $curTime = time();
        $query = Database::connection()->prepare('INSERT INTO question_answer'
                . ' (questionid, created_at, created_by, edited_at, edited_by, text) VALUES (?, ?, ?, ?, ?, ?)');
        $query->bindValue(1, $question->getQuestionId(), PDO::PARAM_INT);
        $query->bindValue(2, $curTime, PDO::PARAM_INT);
        $query->bindValue(3, $creator->getUserId(), PDO::PARAM_INT);
        $query->bindValue(4, $curTime, PDO::PARAM_INT);
        $query->bindValue(5, $creator->getUserId(), PDO::PARAM_INT);
        $query->bindValue(6, $text, PDO::PARAM_STR);
        if (!$query->execute()) {
            throw new Exception('Answer could not be created in the database.');
        }
        
        // Invalidate the question's answer cache
        $question->invalidateAnswerCache();
        
        // Return the question
        $answer = self::fromId(Database::connection()->lastInsertId());
        $answer->changed();
        return $answer;
        
    }
    
    /**
     * Sanitates the text to be added into the database.
     * @param string $text The text to sanitate.
     * @return string
     */
    public static function sanitateText($text) {
        return trim($text);
    }
    
    /**
     * Checks the text to make sure it is valid.
     * @param string $text The text to check.
     * @return boolean
     */
    public static function checkText($text) {
        return strlen($text) > 0;
    }
    
    /**
     * Gets called when this answer changes.
     */
    private function changed() {
        $question = Question::fromId($this->getQuestionId());
        $course = Course::fromId($question->getCourseId());
        Sync::course($course);
    }
    
    /**
     * Returns the context for this answer.
     * @return array
     */
    public function getContext(User $user) {
        return array(
            'answerid' => $this->getAnswerId(),
            'questionid' => $this->getQuestionId(),
            'created_at' => $this->getCreationTime(),
            'created_by' => User::fromId($this->getUserId())->getContext($user),
            'edited' => $this->isEdited(),
            'edited_at' => $this->getEditedTime(),
            'edited_by' => User::fromId($this->getEditorUserid())->getContext($user),
            'text' => $this->getText()
        );
    }
    
    /**
     * Edits the text of this answer.
     * @param User $editor The user who is doing the editing.
     * @param string $newText The new text.
     */
    public function edit(User $editor, $newText) {
        
        // Do some cleaning up
        $newText = self::sanitateText($newText);
        if (!self::checkText($newText)) {
            throw new Exception('New text is not good.');
        }
        
        // Perform the database update
        $query = Database::connection()->prepare('UPDATE question_answer SET edited_at = ?, edited_by = ?, text = ? WHERE answerid = ?');
        $query->bindValue(1, time(), PDO::PARAM_INT);
        $query->bindValue(2, $editor->getUserId(), PDO::PARAM_INT);
        $query->bindValue(3, $newText, PDO::PARAM_STR);
        $query->bindValue(4, $this->getAnswerId(), PDO::PARAM_INT);
        $query->execute();
        
        // Set the local values
        $this->row['text'] = $newText;
        $this->changed();
        
    }
    
    /**
     * Gets the userid of the creator of this answer.
     * @return int
     */
    public function getUserId() {
        return intval($this->row['created_by']);
    }
    
    /**
     * Gets the id for this answer.
     * @return int
     */
    public function getAnswerId() {
        return intval($this->row['answerid']);
    }
    
    /**
     * Gets the id of the question this answer belongs to.
     * @return int
     */
    public function getQuestionId() {
        return intval($this->row['questionid']);
    }
    
    /**
     * Gets the time that this answer was created.
     * @return int
     */
    public function getCreationTime() {
        return intval($this->row['created_at']);
    }
    
    /**
     * Determines whether or not this answer has been edited.
     * @return boolean
     */
    public function isEdited() {
        return $this->getCreationTime() != $this->getEditedTime();
    }
    
    /**
     * Gets the userid who last edited this answer.
     * @return int
     */
    public function getEditorUserid() {
        return intval($this->row['edited_by']);
    }
    
    /**
     * Gets the last time that this answer was edited.
     * @return int
     */
    public function getEditedTime() {
        return intval($this->row['edited_at']);
    }
    
    /**
     * Gets the text for this answer.
     * @return string
     */
    public function getText() {
        return $this->row['text'];
    }
    
}
