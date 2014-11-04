<?php

class question_controller {
    
    function get() {
        Auth::checkLoggedIn();
        $question = Question::fromId(Input::get('questionid'));
        if (!$question->canView(Auth::getUser())) {
            throw new Exception('You are not allowed to view this question.');
        }
        View::renderJson($question->getContext(Auth::getUser()));
    }
    
    function create() {
        Auth::checkLoggedIn();
        $entry = Entry::fromId(Input::get('entryid'));
        if (!$entry->canView(Auth::getUser())) {
            throw new Exception('You are not allowed to ask a question in this entry.');
        }
        $question = Question::create(Auth::getUser(), $entry, Input::get('title'), Input::get('text'), Input::getBoolean('private'));
        View::renderJson($question->getContext(Auth::getUser()));
    }
    
    function delete() {
        Auth::checkLoggedIn();
        $question = Question::fromId(Input::get('questionid'));
        if (!$question->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to delete this question.');
        }
        $question->delete();
        View::renderJson();
    }
    
    function edit() {
        Auth::checkLoggedIn();
        $question = Question::fromId(Input::get('questionid'));
        if (!$question->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to edit this question.');
        }
        $question->setTitle(Input::get('title'));
        View::renderJson($question->getContext(Auth::getUser()));
    }
    
    function toggle_closed() {
        Auth::checkLoggedIn();
        $question = Question::fromId(Input::get('questionid'));
        if (!$question->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to close or open this question.');
        }
        $question->setClosed(!$question->isClosed());
        View::renderJson($question->getContext(Auth::getUser()));
    }
    
    function toggle_private() {
        Auth::checkLoggedIn();
        $question = Question::fromId(Input::get('questionid'));
        if (!$question->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to change the visibility this question.');
        }
        $question->setPrivate(!$question->isPrivate());
        View::renderJson($question->getContext(Auth::getUser()));
    }
    
    function get_answer() {
        Auth::checkLoggedIn();
        $answer = QuestionAnswer::fromId(Input::get('answerid'));
        if (!$answer->canView(Auth::getUser())) {
            throw new Exception('You are not allowed to view this answer.');
        }
        View::renderJson($answer->getContext(Auth::getUser()));
    }
    
    function create_answer() {
        Auth::checkLoggedIn();
        $question = Question::fromId(Input::get('questionid'));
        if (!$question->canAnswer(Auth::getUser())) {
            throw new Exception('You are not allowed to answer this question.');
        }
        $answer = QuestionAnswer::create($question, Auth::getUser(), Input::get('text'));
        View::renderJson($answer->getContext(Auth::getUser()));
    }
    
    function delete_answer() {
        Auth::checkLoggedIn();
        $answer = QuestionAnswer::fromId(Input::get('answerid'));
        if (!$answer->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to delete this answer.');
        }
        $answer->delete();
        View::renderJson();
    }
    
    function edit_answer() {
        Auth::checkLoggedIn();
        $answer = QuestionAnswer::fromId(Input::get('answerid'));
        if (!$answer->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to edit this answer.');
        }
        $answer->edit(Auth::getUser(), Input::get('text'));
        View::renderJson($answer->getContext(Auth::getUser()));
    }
    
    function toggle_like() {
        Auth::checkLoggedIn();
        $answer = QuestionAnswer::fromId(Input::get('answerid'));
        if (!$answer->canView(Auth::getUser())) {
            throw new Exception('You are not allowed to like this answer.');
        }
        $answer->toggleLike(Auth::getUser());
        View::renderJson($answer->getContext(Auth::getUser()));
    }
    
}

