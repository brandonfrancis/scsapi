<?php

// Load up the framework
require_once '../core/autoload.php';


// Check to see if a connection to the database could be established
try {

    // Get the connection
    $connection = Database::connection();
    
} catch (Exception $ex) {

    View::renderView('install_splash', array('show_button' => false, 'result' => 'Database connection was not successful.'));
    exit;
    
}

// See if we're doing the installation
if (Input::exists('do_install')) {

    // See if it's already installed
    $query = Database::connection()->prepare('SHOW TABLES LIKE ?');
    $query->bindValue(1, 'course', PDO::PARAM_STR);
    $query->execute();
    if ($query->rowCount() > 0) {
        View::renderView('install_result', array('result' => 'Nothing was done. Already installed.'));
        exit;
    }
    
    $install_sql = <<<STR
-- Create syntax for TABLE 'answer_likes'
CREATE TABLE `answer_likes` (
  `answerid` int(11) unsigned NOT NULL,
  `userid` int(11) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`answerid`,`userid`),
  KEY `userid` (`userid`),
  CONSTRAINT `answer_likes_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `user` (`userid`) ON DELETE CASCADE,
  CONSTRAINT `answer_likes_ibfk_1` FOREIGN KEY (`answerid`) REFERENCES `question_answer` (`answerid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'attachment'
CREATE TABLE `attachment` (
  `attachmentid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `size` int(11) unsigned NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `created_by` int(11) unsigned DEFAULT NULL,
  `created_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`attachmentid`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `attachment_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `user` (`userid`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'course'
CREATE TABLE `course` (
  `courseid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` int(10) unsigned NOT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `title` varchar(150) NOT NULL DEFAULT '',
  `code` varchar(150) NOT NULL DEFAULT '',
  PRIMARY KEY (`courseid`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `course_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `user` (`userid`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'course_user'
CREATE TABLE `course_user` (
  `courseid` int(11) unsigned NOT NULL,
  `userid` int(10) unsigned NOT NULL,
  `is_professor` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL,
  PRIMARY KEY (`courseid`,`userid`),
  KEY `userid` (`userid`),
  CONSTRAINT `course_user_ibfk_2` FOREIGN KEY (`userid`) REFERENCES `user` (`userid`) ON DELETE CASCADE,
  CONSTRAINT `course_user_ibfk_3` FOREIGN KEY (`courseid`) REFERENCES `course` (`courseid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'entry'
CREATE TABLE `entry` (
  `entryid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `courseid` int(11) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `display_at` int(11) unsigned NOT NULL,
  `due_at` int(11) unsigned NOT NULL DEFAULT '0',
  `title` varchar(250) NOT NULL DEFAULT '',
  `description` text NOT NULL,
  `visible` tinyint(1) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`entryid`),
  KEY `courseid` (`courseid`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `entry_ibfk_1` FOREIGN KEY (`courseid`) REFERENCES `course` (`courseid`) ON DELETE CASCADE,
  CONSTRAINT `entry_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `user` (`userid`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'entry_attachment'
CREATE TABLE `entry_attachment` (
  `entryid` int(11) unsigned NOT NULL,
  `attachmentid` int(11) unsigned NOT NULL,
  PRIMARY KEY (`entryid`,`attachmentid`),
  KEY `attachmentid` (`attachmentid`),
  CONSTRAINT `entry_attachment_ibfk_2` FOREIGN KEY (`entryid`) REFERENCES `entry` (`entryid`),
  CONSTRAINT `entry_attachment_ibfk_1` FOREIGN KEY (`attachmentid`) REFERENCES `attachment` (`attachmentid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'question'
CREATE TABLE `question` (
  `questionid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `entryid` int(11) unsigned NOT NULL,
  `is_private` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `is_closed` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL,
  `first_answer` int(11) unsigned DEFAULT NULL,
  `title` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`questionid`),
  KEY `first_answer` (`first_answer`),
  KEY `entryid` (`entryid`),
  CONSTRAINT `question_ibfk_5` FOREIGN KEY (`entryid`) REFERENCES `entry` (`entryid`) ON DELETE CASCADE,
  CONSTRAINT `question_ibfk_4` FOREIGN KEY (`first_answer`) REFERENCES `question_answer` (`answerid`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'question_answer'
CREATE TABLE `question_answer` (
  `answerid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `questionid` int(11) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  `created_by` int(11) unsigned NOT NULL,
  `edited_at` int(11) unsigned NOT NULL,
  `edited_by` int(11) unsigned NOT NULL,
  `text` text NOT NULL,
  PRIMARY KEY (`answerid`),
  KEY `questionid` (`questionid`),
  KEY `created_by` (`created_by`),
  KEY `edited_by` (`edited_by`),
  CONSTRAINT `question_answer_ibfk_1` FOREIGN KEY (`questionid`) REFERENCES `question` (`questionid`) ON DELETE CASCADE,
  CONSTRAINT `question_answer_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `user` (`userid`),
  CONSTRAINT `question_answer_ibfk_3` FOREIGN KEY (`edited_by`) REFERENCES `user` (`userid`)
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'user'
CREATE TABLE `user` (
  `userid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `first_name` varchar(25) NOT NULL,
  `last_name` varchar(25) NOT NULL,
  `email` varchar(50) NOT NULL,
  `email_verified` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `email_token` varchar(32) NOT NULL,
  `salt` varchar(64) NOT NULL,
  `salt_cookie` varchar(64) NOT NULL,
  `password` varchar(64) NOT NULL,
  `password_temp` varchar(64) NOT NULL DEFAULT '0',
  `password_temp_time` int(11) unsigned NOT NULL DEFAULT '0',
  `created_at` int(11) unsigned NOT NULL,
  `created_from` varchar(25) NOT NULL,
  `last_visit_at` int(11) unsigned NOT NULL DEFAULT '0',
  `last_visit_from` varchar(25) NOT NULL,
  `current_visit_at` int(11) unsigned NOT NULL DEFAULT '0',
  `current_visit_from` varchar(25) NOT NULL,
  `is_admin` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `avatar_attachmentid` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`userid`),
  UNIQUE KEY `email` (`email`),
  KEY `avatar_attachmentid` (`avatar_attachmentid`),
  CONSTRAINT `user_ibfk_1` FOREIGN KEY (`avatar_attachmentid`) REFERENCES `attachment` (`attachmentid`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=latin1;

-- Create syntax for TABLE 'user_notification'
CREATE TABLE `user_notification` (
  `notificationid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `userid` int(11) unsigned NOT NULL,
  `created_at` int(11) unsigned NOT NULL,
  `read_at` int(11) unsigned NOT NULL DEFAULT '0',
  `emailed_at` int(11) unsigned NOT NULL DEFAULT '0',
  `message` varchar(250) NOT NULL DEFAULT 'No message',
  `link` varchar(250) DEFAULT '',
  `image_url` varchar(250) DEFAULT '',
  PRIMARY KEY (`notificationid`),
  KEY `userid` (`userid`),
  CONSTRAINT `user_notification_ibfk_1` FOREIGN KEY (`userid`) REFERENCES `user` (`userid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
STR;
    
    $install_query = Database::connection()->prepare($install_sql);
    if (!$install_query->execute()) {
        View::renderView('install_result', array('result' => 'Nothing was done. Query failed.'));
        exit;
    }
    
    // Now create the first user
    $admin = User::create('Admin', 'Admin', 'admin@admin.com', 'abc123');
    
    // Make the user an admin
    $adminQuery = Database::connection()->prepare('UPDATE user SET is_admin = 1 WHERE userid = ?');
    $adminQuery->bindValue(1, $admin->getUserId(), PDO::PARAM_INT);
    $adminQuery->execute();
    
    // Create an initial course
    Course::create($admin, 'Test Course', 'testcourse-001');

    // We're doing the installation
    View::renderView('install_result', array('result' => 'Installed! Close and delete this script.'));
    exit;
    
}

View::renderView('install_splash', array('show_button' => true, 'result' => 'Database connection was successful.'));