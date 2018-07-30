<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Version details.
 *
 * @package    local_temporary_enrolments
 * @copyright  2018 onwards Lafayette College ITS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot. '/lib/accesslib.php');
require_once($CFG->dirroot. '/admin/roles/classes/define_role_table_advanced.php');
require_once($CFG->dirroot. '/lib/moodlelib.php');

/**
 * Send an email (init, remind, upgrade, or expire).
 *
 * @param object $data This should be an event object (or in the case of remind_task(), a fake event object).
 * @param string $which studentinit | teacherinit | remind | upgrade | expire
 * @param string $sendto relateduserid | userid (defaults to student, can be set to 'userid' to send to teacher)
 *
 * @return void
 */
function send_temporary_enrolments_email($assignerid, $assigneeid, $courseid, $raid, $which, $sendto='assigneeid') {
    global $DB;

    // Build 'from' object.
    $noreplyuser = \core_user::get_noreply_user();
    $from = new stdClass();
    $from->customheaders = 'Auto-Submitted: auto-generated';
    $from->maildisplay = true; // Required to prevent Notice.
    $from->email = $noreplyuser->email; // Required to prevent Notice.

    // Get related data.
    $course = $DB->get_record('course', array('id' => $courseid));
    $student = $DB->get_record('user', array('id' => $assigneeid));
    $teacher = $DB->get_record('user', array('id' => $assignerid));
    if ($DB->record_exists('local_temporary_enrolments', array('roleassignid' => $raid))) {
        $expiration = $DB->get_record('local_temporary_enrolments', array('roleassignid' => $raid));
    } else {
        $expiration = new stdClass();
        $expiration->timeend = 0;
    }

    $replace = array(
        '/\{TEACHER\}/' => $teacher->firstname,
        '/\{STUDENTFIRST\}/' => $student->firstname,
        '/\{STUDENTLAST\}/' => $student->lastname,
        '/\{STUDENTFULL\}/' => fullname($student),
        '/\{COURSE\}/' => $course->fullname,
        '/\{TIMELEFT\}/' => round(($expiration->timeend - time()) / 86400),
        '/\{SUBJECT: (.*)\}\s+/' => '',
    );

    // Get raw email content.
    $message = get_config('local_temporary_enrolments', $which . '_content');

     // Subject.
    $subject = array();
    preg_match("/\{SUBJECT: (.*)\}\s+/", $message, $subject); // Pull SUBJECT line out of message content.

    // Build final email body and subject and to address.
    $subject = preg_replace(array_keys($replace), array_values($replace), $subject[1]);
    $message = preg_replace(array_keys($replace), array_values($replace), $message);
    $to = $DB->get_record('user', array('id' => $$sendto));

    // Send email.
    email_to_user($to, $from, $subject, $message);
}

/**
 * Add a role assignment to the custom table.
 *
 * @param int $raid Role assignment id
 * @param int $raroleid Role assignment role id
 * @param int $timecreated Time the role assignment was created
 *
 * @return void
 */
function add_to_custom_table($raid, $raroleid, $timecreated) {
    global $DB;

    $insert = new stdClass();
    $insert->roleassignid = $raid;
    $insert->roleid = $raroleid; // Stored so we can easily check that table is up to date if role settings are changed.
    $length = get_config('local_temporary_enrolments', 'length');
    $insert->timeend = $timecreated + $length;
    $insert->timestart = $timecreated;
    $DB->insert_record('local_temporary_enrolments', $insert);
}

function get_temp_role() {
    global $DB;

    if ($id = get_config('local_temporary_enrolments', 'roleid')) {
        return $DB->get_record('role', array('id' => $id));
    }
}

function handle_update_length() {
    global $DB;
    $length = get_config('local_temporary_enrolments', 'length');
    update_length($length);
}

function handle_update_reminder_freq() {
    global $DB;
    $remindfreq = get_config('local_temporary_enrolments', 'remind_freq');
    $task = $DB->get_record('task_scheduled', array('classname' => '\local_temporary_enrolments\task\remind_task'));
    update_remind_freq($task, $remindfreq);
}

function handle_existing_assignments() {
    global $DB;
    // Wipe any outdated entries in the custom table.
    $DB->delete_records('local_temporary_enrolments');
    $roleid = get_temp_role()->id;
    // Add existing role assignments.
    $ss = "local_temporary_enrolments_existingassignments"; // Setting name string.
    $onoff = array_key_exists('s_'.$ss, $_POST) ? $_POST['s_'.$ss] : get_config('local_temporary_enrolments', 'existingassignments');
    if ($onoff) {
        $toadd = $DB->get_records('role_assignments', array('roleid' => $roleid));
        $now = time();
        foreach ($toadd as $assignment) {
            $start = array_key_exists('s_'.$ss.'_start', $_POST) ? $_POST['s_'.$ss.'_start'] : get_config('local_temporary_enrolments', 'existingassignments_start');
            $starttime = $assignment->timemodified; // Default.
            if ($start) {
                $starttime = $now;
            }
            add_to_custom_table($assignment->id, $assignment->roleid, $starttime);
            $sendemail = array_key_exists('s_'.$ss.'_email', $_POST) ? $_POST['s_'.$ss.'_email'] : get_config('local_temporary_enrolments', 'existingassignments_email');
            if ($sendemail) {
                $assignerid = 1;
                $assigneeid = $assignment->userid;
                $context = $DB->get_record('context', array('id' => $assignment->contextid));
                $courseid = $context->instanceid;
                $raid = $assignment->id;
                $which = 'studentinit';
                send_temporary_enrolments_email($assignerid, $assigneeid, $courseid, $raid, $which);
            }
        }
    }
}

/**
 * Update the frequency of reminder emails in the database (based on config)
 *
 * @param object $task Should be a the remind_task entry from task_scheduled table
 * @param object $newfreq Should be the config value for remind frequency
 *
 * @return void
 */
function update_remind_freq($task, $newfreq) {
    global $DB;
    $update = new stdClass();
    $update->id = $task->id;
    $update->day = '*/'.$newfreq;
    $DB->update_record('task_scheduled', $update);
}

/**
 * Update the remaining length of a temporary enrolment
 *
 * @param object $newlength Should be the updated length of the temporary enrolment
 *
 * @return void
 */function update_length($newlength) {
    global $DB;

    $expirations = $DB->get_records('local_temporary_enrolments');
    foreach ($expirations as $expiration) {
        $newtimeend = $expiration->timestart + $newlength;
        $update = new stdClass();
        $update->id = $expiration->id;
        $update->timeend = $newtimeend;
        $DB->update_record('local_temporary_enrolments', $update);
    }
}
