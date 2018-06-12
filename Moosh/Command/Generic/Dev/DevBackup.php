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

        //$this->addOption('f|filename:', 'path to filename to save the course backup');
        //$this->addOption('p|path:', 'path to save the course backup');
        //$this->addOption('F|fullbackup', 'do full backup instead of general');
        //$this->addOption('template', 'do template backup instead of general');

        //$this->addArgument('id');
    }

    private function course_backup($courseid, $path) {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');       

        //check if course id exists
        $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

        $shortname = str_replace(' ', '_', $course->shortname);

        $options = $this->expandedOptions;

        // TODO: update this to reflect correct destination filename
        $filename = $path . '/backup_' . $courseid . "_". str_replace('/','_',$shortname) . '_' . date('Y.m.d') . '.mbz';

        //check if destination file does not exist and can be created
        if (file_exists($filename)) {
            cli_error("File '{$filename}' already exists, I will not over-write it.");
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
        $rs = $DB->get_recordset('local_rollover', ['state' => ROLLOVER_COURSE_WAITING]);
        //$coursecount = iterator_count($rs);
        //echo("Number of courses remaining = $coursecount\n");
        //$rs->rewind();

        foreach ($rs as $rollovercourse) {
            echo "Creating backup. Course id = {$rollovercourse->id}\n";
            self::course_backup($rollovercourse->id, $config->backupfilepath);
        }

        $rs->close();
        
    }
}
