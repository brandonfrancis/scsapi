<?php

Routes::set('question/get', 'question#get');
Routes::set('question/create', 'question#create');
Routes::set('question/delete', 'question#delete');
Routes::set('question/edit', 'question#edit');
Routes::set('question/toggle_closed', 'question#toggle_closed');
Routes::set('question/toggle_private', 'question#toggle_private');
Routes::set('answer/get', 'question#get_answer');
Routes::set('answer/create', 'question#create_answer');
Routes::set('answer/delete', 'question#delete_answer');
Routes::set('answer/edit', 'question#edit_answer');
Routes::set('answer/toggle_like', 'question#toggle_like');

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
     * Gets a list of questions for a given entry.
     * @param Entry $entry The entry to get the questions for.
     * @return Question[]
     */
    public static function forEntry(Entry $entry) {
        
        // Do the database query
        $query = Database::connection()->prepare('SELECT * FROM question WHERE entryid = ? ORDER BY created_at DESC');
        $query->bindValue(1, $entry->getEntryId(), PDO::PARAM_INT);
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
     * @param Entry $entry The entry it belongs to.
     * @param string $title The title for the question being asked.
     * @param string $text The text of the question being asked.
     * @param boolean $private Whether or not this question is private.
     * @throws Exception
     */
    public static function create(User $creator, Entry $entry, $title, $text, $private = false) {
        $private = boolval($private);
        
        // Start the transaction
        Database::connection()->beginTransaction();
        
        // Do the database insert
        $query = Database::connection()->prepare('INSERT INTO question (entryid, is_private, created_at, title) VALUES (?, ?, ?, ?)');
        $query->bindValue(1, $entry->getEntryId(), PDO::PARAM_INT);
        $query->bindValue(2, $private, PDO::PARAM_BOOL);
        $query->bindValue(3, time(), PDO::PARAM_INT);
        $query->bindValue(4, $title, PDO::PARAM_STR);
        if (!$query->execute()) {
            Database::connection()->rollBack();
            throw new Exception('Question could not be inserted into the database.');
        }
        
        // Get the question we just made
        $question = self::fromId(Database::connection()->lastInsertId());
        
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
     * Deletes this question.
     */
    public function delete() {
        $query = Database::connection()->prepare('DELETE FROM question WHERE questionid = ?');
        $query->bindValue(1, $this->getQuestionId(), PDO::PARAM_INT);
        $query->execute();
        $this->changed();
        ObjCache::invalidate(OBJCACHE_TYPE_QUESTION, $this->getQuestionId());
    }
    
    /**
     * Gets called when the question changes.
     */
    public function changed() {
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
        $answers_contexts = array_map(function($user, $contextUser) {
            return $user->getContext($contextUser);
        }, $answers, count($answers) > 0 ? array_fill(0, count($answers), $user) : array());
        return array(   
            'questionid' => $this->getQuestionId(),
            'entryid' => $this->getEntryId(),
            'courseid' => $this->getCourseId(),
            'title' => $this->getTitle(),
            'is_private' => $this->isPrivate(),
            'is_closed' => $this->isClosed(),
            'can_answer' => $this->canAnswer($user),
            'can_edit' => $this->canEdit($user),
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
        $entry = Entry::fromId($this->getEntryId());
        return $entry->getCourseId();
    }
    
    /**
     * Gets the id of the entry this question belongs to.
     * @return int
     */
    public function getEntryId() {
        return intval($this->row['entryid']);
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
     * Gets the id of the first answer for this question.
     * @return int
     */
    public function getFirstAnswerId() {
        return intval($this->row['first_answer']);
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
        $entry = Entry::fromId($this->getEntryId());
        if (!$entry->canView($user)) {
            return false;
        }
        
        // If it is private they need to be the asker or a professor
        if ($this->isPrivate()) {
            
            // See if they are the professor
            if ($entry->canEdit($user)) {
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

            // See if they are a professor for the course
            $course = Course::fromId($this->getCourseId());
            if (!$course->canEdit($user)) {
                return false;
            }
            
        }

        // They can answer the question
        return true;
    }

    /**
     * Determines whether or not a given user can edit the question.
     * @param User $user The user to check.
     * @return boolean
     */
    public function canEdit(User $user) {

        // See if they are a professor for the course
        $entry = Entry::fromId($this->getEntryId());
        if ($entry->canEdit($user)) {
            return true;
        }

        // See if they asked the question
        $firstAnswer = QuestionAnswer::fromId($this->getFirstAnswerId());
        if ($firstAnswer->getUserId() == $user->getUserId()) {
            return true;
        }
        
        // They cannot edit
        return false;
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
    public function changed() {
        $question = Question::fromId($this->getQuestionId());
        $question->invalidateAnswerCache();
        $question->changed();
    }
    
    /**
     * Returns the context for this answer.
     * @return array
     */
    public function getContext(User $user) {
        
        // Build the likes array
        $likesUsers = $this->getLikes();
        $likes_contexts = array_map(function($user, $contextUser) { 
            return $user->getContext($contextUser); 
        }, $likesUsers, count($likesUsers) > 0 ? array_fill(0, count($likesUsers), $user) : array());
        
        // See if the professor has liked this answer
        $professorLiked = false;
        $course = Course::fromId(Question::fromId($this->getQuestionId())->getCourseId());
        foreach ($likesUsers as $user) {
            if ($course->canEdit($user)) {
                $professorLiked = true;
                break;
            }
        }
        $isProfessor = $course->canEdit(User::fromId($this->getUserId()));
        
        // Return the context
        return array(
            'answerid' => $this->getAnswerId(),
            'questionid' => $this->getQuestionId(),
            'created_at' => $this->getCreationTime(),
            'created_by' => User::fromId($this->getUserId())->getContext($user),
            'edited' => $this->isEdited(),
            'edited_at' => $this->getEditedTime(),
            'edited_by' => User::fromId($this->getEditorUserid())->getContext($user),
            'text' => $this->getText(),
            'can_edit' => $this->canEdit($user),
            'has_liked' => $this->hasLiked($user),
            'likes' => $likes_contexts,
            'professor_liked' => $professorLiked,
            'is_professor' => $isProfessor
        );
    }
    
    /**
     * Gets an array of users who like this answer.
     * @return User[]
     */
    private function getLikes() {
        
        // See if we can return the cached result
        if ($this->likesCache != null) {
            return $this->likesCache;
        }
        
        // Do the query
        $query = Database::connection()->prepare('SELECT user.* from user, answer_likes WHERE answer_likes.answerid = ? AND'
                . ' answer_likes.userid = user.userid ORDER BY answer_likes.created_at DESC');
        $query->bindValue(1, $this->getAnswerId(), PDO::PARAM_INT);
        $query->execute();
        
        // Create the array of users
        $results = $query->fetchAll();
        $users = array();
        foreach ($results as $row) {
            array_push($users, User::fromRow($row));
        }
        
        // Set the cache and return
        $this->likesCache = $users;
        return $users;
    }
    private $likesCache = null;
    
    /**
     * Determines whether or not a user has liked this answer.
     * @param User $user The user to check for.
     * @return boolean
     */
    public function hasLiked(User $user) {
        $users = $this->getLikes();
        for ($i = 0; $i < count($users); $i++) {
            if ($users[$i]->getUserId() === $user->getUserId()) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Makes a user like or unlike this answer.
     * @param User $user The user to use.
     */
    public function toggleLike(User $user) {
        
        // See if this user has liked it already.
        if ($this->hasLiked($user)) {
            
            // Remove the like from the database
            $query = Database::connection()->prepare('DELETE FROM answer_likes WHERE answerid = ? AND userid = ?');
            $query->bindValue(1, $this->getAnswerId(), PDO::PARAM_INT);
            $query->bindValue(2, $user->getUserId(), PDO::PARAM_INT);
            $query->execute();
            
            // Invalidate the cache
            $this->likesCache = null;
            $this->changed();
            return;
        }
        
        // Add the like to the database
        $query = Database::connection()->prepare('INSERT INTO answer_likes (answerid, userid, created_at) VALUES (?, ?, ?)');
        $query->bindValue(1, $this->getAnswerId(), PDO::PARAM_INT);
        $query->bindValue(2, $user->getUserId(), PDO::PARAM_INT);
        $query->bindValue(3, time(), PDO::PARAM_INT);
        $query->execute();
        
        // Invalidate the cache
        $this->likesCache = null;
        $this->changed();
        
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
     * Deletes this answer.
     */
    public function delete() {
        
        // Let's see if this is the first answer
        // if it is, we should delete the question manually
        // even though the database will take care of it anyway
        // because we want to ensure "changed" fires correctly to the users
        $question = Question::fromId($this->getQuestionId());
        if ($question->getFirstAnswerId() == $this->getAnswerId()) {
            
            // Delete the question and this answer will go away with it
            $question->delete();
            
        } else {
            
            // We can just delete this answer and the question wil still exist
            $query = Database::connection()->prepare('DELETE FROM question_answer WHERE answerid = ?');
            $query->bindValue(1, $this->getAnswerId(), PDO::PARAM_INT);
            $query->execute();
            $this->changed();
            
        }
        
        // Make sure to invalidate this answer
        ObjCache::invalidate(OBJCACHE_TYPE_ANSWER, $this->getAnswerId());
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
    
    /**
     * Determines whether or not a user can view this answer.
     * @param User $user The user to check.
     * @return boolean
     */
    public function canView(User $user) {
        if ($user->getUserId() == $this->getUserId()) {
            return true;
        }
        $question = Question::fromId($this->getQuestionId());
        return $question->canView($user);
    }
    
    /**
     * Determines whether or not a user can edit this answer.
     * @param User $user The user to check.
     * @return boolean
     */
    public function canEdit(User $user) {
        if ($user->getUserId() == $this->getUserId()) {
            return true;
        }
        $question = Question::fromId($this->getQuestionId());
        return $question->canEdit($user);
    }
    
}
