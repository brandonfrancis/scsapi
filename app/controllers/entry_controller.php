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
    
    function get_attachment() {
        Auth::checkLoggedIn();
        $entry = Entry::fromId(Input::get('entryid'));
        $attachment = Attachment::fromId(Input::get('attachmentid'));
        
        // Make sure this user can view the entry
        if (!$entry->canView(Auth::getUser())) {
            throw new Exception('You cannot view this entry.');
        }
        
        // Make sure the attachment belongs to the entry
        $attachments = $entry->getAttachments();
        $found = false;
        foreach ($attachments as $curAttachment) {
            if ($curAttachment->getAttachmentId() == $attachment->getAttachmentId()) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new Exception('The requested attachment does not belong to the entry given.');
        }
        
        // Render the attachment
        if ($attachment->getAttachmentType() == Attachment::ATTACHMENT_TYPE_IMAGE) {
            View::renderImage(Attachment::getStoragePath($attachment->getAttachmentId()));
        } else {
            View::renderFile(Attachment::getStoragePath($attachment->getAttachmentId()));
        }
        
    }
    
    function upload_attachment() {
        Auth::checkLoggedIn();
        $entry = Entry::fromId(Input::get('entryid'));
        
        // Make sure the user can edit this entry
        if (!$entry->canEdit(Auth::getUser())) {
            throw new Exception('You are not allowed to edit this entry.');
        }
        
        // Get the uploaded attachments and add them to the entry
        $attachments = Attachment::handleUpload();
        foreach ($attachments as $attachment) {
            $entry->addAttachment($attachment);
        }
        
        // Render the new context
        View::renderJson($entry->getContext(Auth::getUser()));
    }
    
}

