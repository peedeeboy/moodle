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
 * This script fixes orphaned assignfeedback_editpdf files.
 *
 * Due to MDL-69570, when a Course was reset several files belonging
 * to assignfeedback_editpdf where left on both the filesystem and
 * as rows in database tables (e.g. the files table).  This script
 * finds and purges those files / database rows.
 *
 * @package    core
 * @subpackage cli
 * @copyright  2021 Pete Whelpton (p.whelpton@lse.ac.uk)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Parameter defaults
$long = array('fix'  => false, 'help' => false);
$short = array('f' => 'fix', 'h' => 'help');

// Get CLI parameters and check they are valid
list($options, $unrecognized) = cli_get_params($long, $short);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

// Print help
if ($options['help']) {
    $help =
        "Fix orphaned assignfeedback_editpdf files.

        This script detects assignfeedback_editpdf files and database rows
        left orphaned after a Course reset and deletes them. 

        Options:
        -h, --help            Print out this help
        -f, --fix             Fix the orphaned assignfeedback_editpdf files on the filesystem
                              and in the DB.
                              If not specified only check and report problems.
        Example:
        \$sudo -u www-data /usr/bin/php admin/cli/fix_orphaned_assignfeedback_editpdf_files.php
        \$sudo -u www-data /usr/bin/php admin/cli/fix_orphaned_assignfeedback_editpdf_files.php -f
        ";

    echo $help;
    die;
}


// Get orphaned files - ignore `importhtml` file area as this is linked
// to assign_submission, not assign_grades
cli_heading('Checking for orphaned assignfeedback_editpdf files');

$sql = "SELECT 
        f.filename,
        f.filesize,
        f.contextid,
        f.component,
        f.filearea,
        f.itemid
    FROM {files} f
        LEFT OUTER JOIN {assign_grades} g on f.itemid = g.id
    WHERE f.component = 'assignfeedback_editpdf'
        AND f.filearea IN ('download', 'combined', 'partial', 'pages', 'readonlypages')
        AND g.id IS NULL
    ORDER BY f.id";

// Process list of orphaned files
$numFiles = 0;
$numBytes = 0;
$rowOffset = 0;
$grades = array();
$fs = get_file_storage();
$areMoreRecordsToProcess = true;

// Hacky workaround to issues raised in MDL-60174 and MDL-56132 with large recordsets
// causing OOM issues with MySQLi connector
while ($areMoreRecordsToProcess) {
    $documents = $DB->get_recordset_sql($sql, null, $rowOffset, 100);

    if (!$documents->valid()) {
        $areMoreRecordsToProcess = false;
    }

    // Delete files from filesystem
    foreach ($documents as $document) {
        $numFiles += 1;
        $numBytes += $document->filesize;

        cli_writeln("Found orphaned file: {$document->filename}");
        if (!empty($options['fix'])) {
            // Add gradeid to array, so we can remove DB entries later
            cli_write("Deleting...");
            $grades[] = $document->itemid;
            if (!in_array($document->itemid, $grades)) {
                array_push($grades, $document->itemid);
            }
            // Delete the file and DB entires.
            $fs->delete_area_files($document->contextid, $document->component, $document->filearea, $document->itemid);
            cli_writeln("  Done!");
        }
    }

    // Offset 100 rows if we are not deleting them, so we don't return the same 100 rows next loop
    if(empty($options['fix'])) {
        $rowOffset += 100;
    }

    $documents->close();
}

// Delete database rows
if (!empty($options['fix'])) {

    foreach ($grades as $grade) {
        cli_write("Deleting database entries for Grade: {$grade}...");
        $DB->delete_records('assignfeedback_editpdf_annot', array('gradeid' => $grade));
        $DB->delete_records('assignfeedback_editpdf_cmnt', array('gradeid' => $grade));
        $DB->delete_records('assignfeedback_editpdf_rot', array('gradeid' => $grade));
        cli_writeln("  Done!");
    }
}

if (($numFiles > 0) && !empty($options['fix'])) {
    cli_writeln("Found and removed {$numFiles} orphaned assignfeedback_editpdf files"
        . " freeing {$numBytes} bytes.");
} else if ($numFiles > 0) {
    cli_writeln("Found {$numFiles} orphaned assignfeedback_editpdf files. To fix (and free up {$numBytes} bytes), run:");
    cli_writeln("\$sudo -u www-data /usr/bin/php admin/cli/fix_orphaned_assignfeedback_editpdf_files.php --fix");
} else {
    cli_writeln("No orphaned assignfeedback_editpdf files found.");
}
