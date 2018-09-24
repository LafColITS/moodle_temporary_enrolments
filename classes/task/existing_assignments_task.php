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
 * @package    local_temporary_enrolments
 * @copyright  2018 onwards Lafayette College ITS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_temporary_enrolments\task;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot. '/local/temporary_enrolments/lib.php');
require_once($CFG->dirroot. '/lib/moodlelib.php');

/**
 * Adhoc task that handles pre-existing role assignments (for when the temporary
 * marker role is changed).
 */
class existing_assignments_task extends \core\task\adhoc_task {

    public function get_component() {
        return 'local_temporary_enrolments';
    }

    public function execute() {
        global $DB;

        // purge_all_caches();
        $cache = \cache::make('core', 'config');
        // $cache->delete('local_temporary_enrolments');
        $data = $cache->get('local_temporary_enrolments');
        $cachedroleid = $data['roleid'] ?: 'NO VALUE';
        file_put_contents('/var/www/html/vendor/bin/existingassignments.log', "In adhoc task, cache says role id is $cachedroleid\n", FILE_APPEND);

        // set_config('onoff', 1, 'local_temporary_enrolments');

        // $last_processed_roleid = get_config('local_temporary_enrolments', 'last_processed_roleid');

        // file_put_contents('existingassignments.log', "____________________________\n", FILE_APPEND);
        // $rolename = \get_temp_role()->name;
        // $role = get_temp_role();
        // $roleid = $role->id;
        // $roleid = get_config('local_temporary_enrolments', 'roleid');
        //SELECT * FROM b_config_plugins WHERE plugin='local_temporary_enrolments' AND name='roleid'
        if ($roleid = $DB->get_record('config_plugins', array('plugin' => 'local_temporary_enrolments', 'name' => 'roleid'))->value) {
            if ($role = $DB->get_record('role', array('id' => $roleid))) {
                $rolename = $role->shortname;
            } else {
                $rolename = '!no role!';
            }
        } else {
            $rolename = '!no role!';
        }

        if ($cachedroleid = get_config('local_temporary_enrolments', 'roleid')) {
            if ($cachedrole = $DB->get_record('role', array('id' => $cachedroleid))) {
                $cachedrolename = $cachedrole->shortname;
            } else {
                $cachedrolename = '!no role!';
            }
        } else {
            $cachedrolename = '!no role!';
        }
        $now = time();
        // file_put_contents('existingassignments.log', "IN ADHOC TASK IT IS $now\n", FILE_APPEND);
        // file_put_contents('existingassignments.log', "Real role is $rolename\n", FILE_APPEND);
        // file_put_contents('existingassignments.log', "Cached role is $cachedrolename\n", FILE_APPEND);



        // if ($last_processed_roleid && $last_processed_roleid === $roleid) {
        //     file_put_contents('existingassignments.log', "aborting due to same id\n", FILE_APPEND);
        //     return;
        // }
        $roleid = get_temp_role()->id;

        file_put_contents('existingassignments.log', "1\n", FILE_APPEND);

        // Delete custom table entries (for old role only).
        $DB->delete_records_select('local_temporary_enrolments', "roleid <> $roleid");

        file_put_contents('existingassignments.log', "2\n", FILE_APPEND);

        // If existing assignments management is turned off, abort.
        if (!get_config('local_temporary_enrolments', 'existing_assignments')) {
            file_put_contents('existingassignments.log', "aborting due to setting off\n", FILE_APPEND);
            return;
        }

        file_put_contents('existingassignments.log', "3\n", FILE_APPEND);

        // Add existing role assignments.
        $toadd = $DB->get_records('role_assignments', array('roleid' => $roleid));
        $now = time();
        $timestart = get_config('local_temporary_enrolments', 'existing_assignments_start');
        $sendemail = get_config('local_temporary_enrolments', 'existing_assignments_email');

        file_put_contents('existingassignments.log', "4\n", FILE_APPEND);

        foreach ($toadd as $assignment) {
            if ($timestart == 0) { // User has selected "assignment creation" as start time.
                $starttime = $assignment->timemodified;
                // file_put_contents('existingassignments.log', "starttime is $starttime and $now is $now\n", FILE_APPEND);
            } else { // User has selected "now" as start time.
                $starttime = $now;
                // file_put_contents('existingassignments.log', "starttime is $starttime and $now is $now\n", FILE_APPEND);
            }
            $temp = add_to_custom_table($assignment->id, $assignment->roleid, $starttime);
            if ($temp) {
                // file_put_contents('existingassignments.log', "added to table\n", FILE_APPEND);
            } else {
                // file_put_contents('existingassignments.log', "DID NOT add to table\n", FILE_APPEND);
            }
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
        // set_config('last_processed_roleid', $roleid, 'local_temporary_enrolments');
    }
}
