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

namespace local_temporary_enrolments;
require_once($CFG->dirroot. '/lib/moodlelib.php');
require_once($CFG->dirroot. '/enrol/manual/lib.php');
require_once($CFG->dirroot. '/local/temporary_enrolments/lib.php');
require_once($CFG->dirroot. '/lib/accesslib.php');
use stdClass;

class observers {

    /**
     * Actions to take upon a user being assigned the 'temporary_enrolment' role
     * Most likely will be initial enrolment
     * Possibly could be role re-upping to reset expiration timer
     *
     * @param \core\event\role_assigned $event
     * @return void
     */
    public static function initialize($event) {
        global $DB, $CFG;

        if ($CFG->local_temporary_enrolments_onoff) {

            // Get temporary_enrolment role.
            $role = get_temp_role();

            if ($event->objectid == $role->id) {

                $allroles = $DB->get_records('role_assignments', array('userid' => $event->relateduserid, 'contextid' => $event->contextid));

                if (count($allroles) > 1) {
                    role_unassign($role->id, $event->relateduserid, $event->contextid);
                } else {

                    // Send STUDENT initial email.
                    if ($CFG->local_temporary_enrolments_studentinit_onoff) {
                        $assignerid = $event->userid;
                        $assigneeid = $event->relateduserid;
                        $courseid = $event->courseid;
                        $raid = $event->other['id'];
                        $which = 'studentinit';
                        send_temporary_enrolments_email($assignerid, $assigneeid, $courseid, $raid, $which);
                    }

                    // Send TEACHER initial email.
                    if ($CFG->local_temporary_enrolments_teacherinit_onoff) {
                        $assignerid = $event->userid;
                        $assigneeid = $event->relateduserid;
                        $courseid = $event->courseid;
                        $raid = $event->other['id'];
                        $which = 'teacherinit';
                        send_temporary_enrolments_email($assignerid, $assigneeid, $courseid, $raid, $which, 'assignerid');
                    }

                    // Set expiration time.
                    add_to_custom_table($event->other['id'], $event->objectid, $event->timecreated);
                }
            }
        }
    }

    /**
     * Actions to be taken when a temporary_enrolment user is enrolled fully
     *
     * @param \core\event\role_assigned $event
     * @return void
     */
    public static function upgrade($event) {
        global $DB, $CFG;

        if ($CFG->local_temporary_enrolments_onoff) {

            // Get temporary_enrolment role.
            $role = get_temp_role();

            // Does student have temporary role?
            $hasrole = $DB->record_exists('role_assignments', array('contextid' => $event->contextid, 'roleid' => $role->id, 'userid' => $event->relateduserid));

            // If student has temp role AND the flatfile enrolment was of a different role.
            if ($event->objectid != $role->id && $hasrole) {
                // Send upgrade email.
                if ($CFG->local_temporary_enrolments_upgrade_onoff) {
                    $assignerid = $event->userid;
                    $assigneeid = $event->relateduserid;
                    $courseid = $event->courseid;
                    $raid = $event->other['id'];
                    $which = 'upgrade';
                    send_temporary_enrolments_email($assignerid, $assigneeid, $courseid, $raid, $which);
                }

                // Remove temp role and update the entry in our custom table.
                $roleassignment = $DB->get_record('role_assignments', array('contextid' => $event->contextid, 'roleid' => $role->id, 'userid' => $event->relateduserid));
                $expiration = $DB->get_record('local_temporary_enrolments', array('roleassignid' => $roleassignment->id));
                $update = new stdClass();
                $update->id = $expiration->id;
                $update->upgraded = 1;
                $DB->update_record('local_temporary_enrolments', $update);
                role_unassign($role->id, $event->relateduserid, $event->contextid);
            }
        }
    }

    /**
     * Actions to be taken on 'temporary_enrolment' role unassignment
     *
     * @param \core\event\role_unassigned $event
     * @return void
     */
    public static function expire($event) {
        global $DB, $CFG;

        if ($CFG->local_temporary_enrolments_onoff) {

            // Get temporary_enrolment role.
            $role = get_temp_role();

            if ($event->objectid == $role->id) {
                $expiration = $DB->get_record('local_temporary_enrolments', array('roleassignid' => $event->other['id']));
                if (gettype($expiration) == 'object' && !$expiration->upgraded) { // Check if the enrolment was removed by upgrade().
                    $assignerid = $event->userid;
                    $assigneeid = $event->relateduserid;
                    $courseid = $event->courseid;
                    $raid = $event->other['id'];
                    $which = 'expire';
                    send_temporary_enrolments_email($assignerid, $assigneeid, $courseid, $raid, $which);
                }

                // Remove manual enrolment if there are no roles...
                $plugin = new \enrol_manual_plugin();
                $manualenrol = $DB->get_record('enrol', array('enrol' => 'manual', 'courseid' => $event->courseid));
                if (!$DB->record_exists('role_assignments', array('contextid' => $event->contextid, 'userid' => $event->relateduserid))) {
                    $plugin->unenrol_user($manualenrol, $event->relateduserid);
                } else {
                    // ...or else if there are other enrolments.
                    $sql = "SELECT * FROM {user_enrolments} WHERE userid=$event->relateduserid and (";
                    $enrols = $DB->get_records('enrol', array('courseid' => $event->courseid));
                    $enrolids = array();
                    foreach ($enrols as $enrol) {
                        array_push($enrolids, "enrolid=".$enrol->id);
                    }
                    $s = implode(' or ', $enrolids);
                    $sql = $sql.$s.')';

                    $userenrols = $DB->get_records_sql($sql);
                    if (count($userenrols) > 1) {
                        $plugin->unenrol_user($manualenrol, $event->relateduserid);
                    }
                }
            }
        }

        // Remove entry from our custom table.
        $expiration = $DB->get_record('local_temporary_enrolments', array('roleassignid' => $event->other['id']));
        if ($expiration) {
            $DB->delete_records('local_temporary_enrolments', array('id' => $expiration->id));
        }

    }
}
