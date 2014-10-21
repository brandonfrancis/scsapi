<?php

class entry_controller {
    
    function get() {
        Auth::checkLoggedIn();
        $entry = Entry::fromId(Input::get('entryid'));
        if (!$entry->canView(Auth::getUser())) {
            throw new Exception('You are not allowed to view this entry.');
        }
        View::renderJson($entry->getContext(Auth::getUser()));
    }
    
    function create() {
        Auth::checkLoggedIn();
        $course = Course::fromId(Input::get('courseid'));
        if (!$course->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to create an entry in this course.');
        }
        $entry = Entry::create(Auth::getUser(), $course, Input::get('title'), Input::get('description'));
        View::renderJson($entry->getContext(Auth::getUser()));
    }
    
    function delete() {
        Auth::checkLoggedIn();
        $entry = Entry::fromId(Input::get('entryid'));
        if (!$entry->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to delete this entry.');
        }
        $entry->delete();
        View::renderJson();
    }
    
    function edit() {
        Auth::checkLoggedIn();
        $entry = Entry::fromId(Input::get('entryid'));
        if (!$entry->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to edit this entry.');
        }
        $entry->setTitle(Input::get('title'));
        $entry->setDescription(Input::get('description'));
        $entry->setDueTime(intval(Input::get('due_at')));
        $entry->setDisplayTime(intval(Input::get('display_at')));
        $entry->setVisible(Input::getBoolean('visible'));
        view::renderJson($entry->getContext(Auth::getUser()));
    }
    
}

