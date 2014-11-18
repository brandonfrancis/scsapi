<?php

class course_controller {
    
    function get_list() {
        Auth::checkLoggedIn();
        
        // Get all of the courses for the user
        $courses = Course::forUser(Auth::getUser());
        
        // Transform it into an array of contexts
        $contexts = array();
        foreach ($courses as $course) {
            array_push($contexts, $course->getContext(Auth::getUser()));
        }
        
        // Render all of the contexts
        View::renderJson($contexts);
    }
    
    function create() {
        Auth::checkLoggedIn();
        
        // Make sure the user is an admin
        if (!Auth::getUser()->isAdmin()) {
            throw new Exception('Only admins may create new courses.');
        }
        
        // Create a new course
        $course = Course::create(Auth::getUser(), 'New course', 'NOT-SET-00');
        
        // Rendet the context for the new course
        View::renderJson($course->getContext(Auth::getUser()));
    }
    
    function get() {
        Auth::checkLoggedIn();
        
        // Get the course from the id given
        $course = Course::fromId(Input::get('courseid'));
        
        // Make sure the user can view this course
        if (!$course->canView(Auth::getUser())) {
            throw new Exception('You cannot view details about this course.');
        }
        
        // Render the context for the course
        View::renderJson($course->getContext(Auth::getUser()));
    }
    
    function edit() {
        Auth::checkLoggedIn();
        
        // Get the course and make sure the user can edit it
        $course = Course::fromId(Input::get('courseid'));
        if (!$course->canEdit(Auth::getUser())) {
            throw new Exception('You cannot update this course.');
        }
        
        // Set the new info
        $course->setTitle(Input::get('title'));
        $course->setCode(Input::get('code'));
        
        // Give back the new context
        View::renderJson($course->getContext(Auth::getUser()));
        
    }
    
    function add_students() {
        Auth::checkLoggedIn();
        
        // Get the course and make sure the user can edit it
        $course = Course::fromId(Input::get('courseid'));
        if (!$course->canEdit(Auth::getUser())) {
            throw new Exception('You cannot add users to this course.');
        }
        
        // Get the comma seperated list of emails
        $list = Utils::removeWhitespace(Input::get('list'));
        $emails = explode(',', $list);
        
        // Go through the email addresses
        foreach ($emails as $email) {
            
            // Make sure it is a valid address
            if (!Utils::isValidEmail($email)) {
                continue;
            }
            
            // Get the user it belongs to
            $user = User::fromEmail($email);
            if ($user->isGuest()) {
                continue;
            }
            
            // Add the user to the course
            $course->addStudent($user);
            
        }
        
        // Return the new context
        View::renderJson($course->getContext(Auth::getUser()));
        
    }
    
    function add_professor() {
        Auth::checkLoggedIn();
        
        // Get the course and make sure the user can edit it
        $course = Course::fromId(Input::get('courseid'));
        if (!$course->canEdit(Auth::getUser())) {
            throw new Exception('You cannot add users to this course.');
        }

        // Get the comma seperated list of emails
        $email = Input::get('list');


        // Make sure it is a valid address
        if (!Utils::isValidEmail($email)) {
            throw new Exception('Invalid email address given.');
        }

        // Get the user it belongs to
        $user = User::fromEmail($email);
        if ($user->isGuest()) {
            throw new Exception('Invalid email address given, no user found.');
        }

        // Add the user to the course
        $course->addProfessor($user);

        // Return the new context
        View::renderJson($course->getContext(Auth::getUser()));
    }
    
    function remove_student() {
        Auth::checkLoggedIn();
        
        // Get the course and make sure the user can edit it
        $course = Course::fromId(Input::get('courseid'));
        if (!$course->canEdit(Auth::getUser())) {
            throw new Exception('You cannot remove users from this course');
        }
        
        // Get the user id to remove
        $email = Input::get('list');
        $user = User::fromEmail($email);        
        
        // Make sure permissions are not being overstepped
        if ($course->getCreatorUserId() != Auth::getUser()->getUserId() &&
                !$user->isAdmin() && $user->getUserId() == $course->getCreatorUserId()) {
            throw new Exception('You are not allowed to remove the creator from the class.');
        }
        
        
        
        // Remove the user
        $course->removeUser($user);
        
        // Render the new context
        View::renderJson($course->getContext(Auth::getUser()));
        
    }
    
}

