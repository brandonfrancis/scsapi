<?php

class Sync {

    /**
     * All of the course sync records.
     * @var Course[]
     */
    private static $coursesToSync = array();

    /**
     * Adds a course to the sync queue.
     * @param Course $course The course to add.
     */
    public static function course(Course $course) {
        self::$coursesToSync[$course->getCourseId()] = $course;
    }

    /**
     * Emits a sync to the given user.
     * @param User $user The user.
     * @param string $type The type of sync.
     * @param string $id The id to use.
     * @param object $context The context to emit.
     */
    public static function emit(User $user, $type, $id, $context) {
        $user->emit('sync', array('type' => $type, 'id' => $id, 'context' => $context));
    }

    /**
     * Gets called when the script is exiting.
     * Sends out all sync records.
     */
    public static function exiting() {

        // Use all of the course records
        foreach (self::$coursesToSync as $course) {

            // Sync the course
            $course->perform_sync();
        }
        self::$coursesToSync = array();
    }

}

/**
 * Register the sync shutdown call.
 */
function sync_shutdown() {
    Sync::exiting();
}
register_shutdown_function('sync_shutdown');
