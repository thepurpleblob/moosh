<?php
/**
 * moosh - Moodle Shell
 *
 * @copyright  2018 Howard Miller
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace Moosh\Command\Generic\Dev;
use Moosh\MooshCommand;
use backup_controller;
use backup;
use local_rollover\locallib;

class DevBackup extends MooshCommand
{
    public function __construct()
    {
        parent::__construct('backup', 'dev');
    }

    private function course_backup($courseid, $path) {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');       

        //check if course id exists
        $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

        $shortname = str_replace(' ', '_', $course->shortname);

        $options = $this->expandedOptions;

        $filename = $path . '/backup_' . $courseid . "_". str_replace('/','_',$shortname) . '_' . date('Y.m.d') . '.mbz';

        //check if destination file does not exist and can be created
        if (file_exists($filename)) {
            echo("File '{$filename}' already exists, deleting.\n");
            unlink($filename);
        }

        $bc = new backup_controller(\backup::TYPE_1COURSE, $courseid, backup::FORMAT_MOODLE,
            backup::INTERACTIVE_YES, backup::MODE_GENERAL, $USER->id);

        $tasks = $bc->get_plan()->get_tasks();
        foreach ($tasks as &$task) {
            if ($task instanceof \backup_root_task) {
                //$setting = $task->get_setting('logs');
                //$setting->set_value('1');
                $setting = $task->get_setting('grade_histories');
                $setting->set_value('1');
            } 
        } 

        $bc->set_status(backup::STATUS_AWAITING);
        $bc->execute_plan();
        $result = $bc->get_results();

        if(isset($result['backup_destination']) && $result['backup_destination']) {
            $file = $result['backup_destination'];
            /** @var $file stored_file */

            if(!$file->copy_content_to($filename)) {
                cli_error("Problems copying final backup to '". $filename . "'");
            } else {
                printf("%s\n", $filename);
            }
        } else {
	    echo $bc->get_backupid();
        }
    }

    private function update_state($id, $status) {
        global $DB;
 
        $ro = $DB->get_record('local_rollover', ['id' => $id], '*', MUST_EXIST);
        $ro->state = $status;
        $DB->update_record('local_rollover', $ro);

        return $ro;
    }

    public function execute() {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/local/rollover/classes/locallib.php');       

        // Get/check config set up in local_rollover 
        $config = get_config('local_rollover');
        if (!$config->enable) {
            echo("Rollover is not currently enabled\n");
            die;
        }
        if (!$config->destinationcategory) {
            echo("Cannot execute rollover. Destination category not defined\n");
            die;
        }
        if (!$config->appendtext && !$config->prependtext) {
            echo("Cannot execute rollover. One of prepend/append text must be defined\n");
            die;
        }
        if (!$config->shortprependtext) {
            echo("Cannot execute rollover. Short name prepend text not defined\n");
            die;
        }

        // Get courses waiting to be processed
        $coursecount = $DB->count_records('local_rollover', ['state' => ROLLOVER_COURSE_WAITING]);
        $rs = $DB->get_recordset('local_rollover', ['state' => ROLLOVER_COURSE_WAITING]);
        echo("Number of courses remaining = $coursecount\n");

        // Note starting time for limit
        $starttime = time();
        if (!$config->timelimit) {
            $config->timelimit = 240; // Default = 4 hours;
        }
        $endtime = $starttime + (60 * $config->timelimit);
        echo("Starting at backups at " . date('H:s', $starttime) . "\n");

        $count = 0;
        foreach ($rs as $rollovercourse) {
            echo "Creating backup. Course id = {$rollovercourse->courseid}\n";
            self::course_backup($rollovercourse->courseid, $config->backupfilepath);
            self::update_state($rollovercourse->id, ROLLOVER_COURSE_BACKUP);
            $count++;

            // Check if our time is up
            if (time() > $endtime) {
                echo("Time limit exceeded\n");
                break;
            }
        }
        echo("$count courses has been backed up\n");
        echo("Completed this set of backups at " . date('H:s', time()) . "\n");

        $rs->close();
        
    }
}
