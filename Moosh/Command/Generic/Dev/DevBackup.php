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

    private function course_backup($courseid) {
        global $CFG, $DB, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');       

        //check if course id exists
        $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

        $shortname = str_replace(' ', '_', $course->shortname);

        $options = $this->expandedOptions;

        // TODO: change this to reflect correct save path
        $cwd=$this->cwd;

        // TODO: update this to reflect correct destination filename
        $filename = $cwd . '/backup_' . $courseid . "_". str_replace('/','_',$shortname) . '_' . date('Y.m.d') . '.mbz';

        //check if destination file does not exist and can be created
        if (file_exists($filename)) {
            cli_error("File '{$filename}' already exists, I will not over-write it.");
        }

        $bc = new backup_controller(\backup::TYPE_1COURSE, $this->arguments[0], backup::FORMAT_MOODLE,
            backup::INTERACTIVE_YES, backup::MODE_GENERAL, $USER->id);

        if ($options['fullbackup']) {
            $tasks = $bc->get_plan()->get_tasks();
            foreach ($tasks as &$task) {
                if ($task instanceof \backup_root_task) {
                    $setting = $task->get_setting('logs');
                    $setting->set_value('1');
                    $setting = $task->get_setting('grade_histories');
                    $setting->set_value('1');
                } 
            } 
        }

        if ($options['template']) {
            $tasks = $bc->get_plan()->get_tasks();
            foreach ($tasks as &$task) {
                if ($task instanceof \backup_root_task) {
                    $setting = $task->get_setting('users');
                    $setting->set_value('0');
                    $setting = $task->get_setting('anonymize');
                    $setting->set_value('1');
                    $setting = $task->get_setting('role_assignments');
                    $setting->set_value('0');
                    $setting = $task->get_setting('filters');
                    $setting->set_value('0');
                    $setting = $task->get_setting('comments');
                    $setting->set_value('0');
                    $setting = $task->get_setting('logs');
                    $setting->set_value('0');
                    $setting = $task->get_setting('grade_histories');
                    $setting->set_value('0');
                } 
            } 
        }
        
        $bc->set_status(backup::STATUS_AWAITING);
        $bc->execute_plan();
        $result = $bc->get_results();

        if(isset($result['backup_destination']) && $result['backup_destination']) {
            $file = $result['backup_destination'];
            /** @var $file stored_file */

            if(!$file->copy_content_to($options['filename'])) {
                cli_error("Problems copying final backup to '". $options['filename'] . "'");
            } else {
                printf("%s\n", $options['filename']);
            }
        } else {
	    echo $bc->get_backupid();
        }
    }

    public function execute() {
        global $CFG, $DB, $USER;

    }
}
