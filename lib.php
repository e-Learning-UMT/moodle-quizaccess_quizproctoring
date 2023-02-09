<?php
/**
 * library functions for quizaccess_quizproctoring plugin.
 *
 * @package    quizaccess
 * @subpackage quizproctoring
 * @copyright  2020 Mahendra Soni <ms@taketwotechnologies.com> {@link https://taketwotechnologies.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

define('QUIZACCESS_QUIZPROCTORING_NOFACEDETECTED', 'nofacedetected');
define('QUIZACCESS_QUIZPROCTORING_MULTIFACESDETECTED', 'multifacesdetected');
define('QUIZACCESS_QUIZPROCTORING_FACESNOTMATCHED', 'facesnotmatched');
define('QUIZACCESS_QUIZPROCTORING_EYESNOTOPENED', 'eyesnotopened');
define('QUIZACCESS_QUIZPROCTORING_FACEMATCHTHRESHOLD', 90);
define('QUIZACCESS_QUIZPROCTORING_FACEMASKDETECTED', 'facemaskdetected');
define('QUIZACCESS_QUIZPROCTORING_FACEMASKTHRESHOLD', 80);
define('QUIZACCESS_QUIZPROCTORING_COMPLETION_PASSED', 'completionpassed');
define('QUIZACCESS_QUIZPROCTORING_COMPLETION_FAILED', 'completionfailed');

/**
 * Serves the quizaccess proctoring files.
 *
 * @package  quizaccess_quizproctoring
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */

 function quizaccess_quizproctoring_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, 
    array $options=array()) {
    global $DB;
    $itemid = array_shift($args);
    $relativepath = implode('/', $args);
    if (!$data = $DB->get_record("quizaccess_proctor_data", array('id' => $itemid)) && $filearea == 'identity') {
        return false;
    }

    $fs = get_file_storage();
    $fullpath = "/$context->id/quizaccess_quizproctoring/$filearea/$itemid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

function camera_task_start($cmid, $attemptid, $quizid) {
    global $DB, $PAGE, $OUTPUT, $USER;
    // Update main image attempt id as soon as user landed on attemp page.
    $user = $DB->get_record('user', array('id' => $USER->id), '*', MUST_EXIST);
    if ($proctored_data = $DB->get_record('quizaccess_proctor_data', array('userid' => $user->id, 'quizid' => $quizid, 'image_status' => 'M', 'attemptid' => 0))) {
        $proctored_data->attemptid = $attemptid;
        $DB->update_record('quizaccess_proctor_data', $proctored_data);
    }
    $interval = $DB->get_record('quizaccess_quizproctoring',array('quizid'=>$quizid));
    $PAGE->requires->js_call_amd('quizaccess_quizproctoring/add_camera', 'init',[$cmid, false, true, $attemptid,$interval->time_interval]);
    $PAGE->requires->js_call_amd('quizaccess_quizproctoring/quiz_protection', 'init');
}

function storeimage($data, $cmid, $attemptid, $quizid, $mainimage, $status='') {
    global $USER, $DB, $COURSE;

    $user = $DB->get_record('user', array('id' => $USER->id), '*', MUST_EXIST);
    //we are all good, store the image
    if ( $mainimage ) {
        if ($qpd = $DB->get_record('quizaccess_proctor_data', array('userid' => $user->id, 'quizid' => $quizid, 'attemptid' => $attemptid, 'image_status' => 'M' ))) {
            $DB->delete_records('quizaccess_proctor_data', array('id' => $qpd->id));
        }
        if ($qpd = $DB->get_record('quizaccess_proctor_data', array('userid' => $user->id, 'quizid' => $quizid, 'attemptid' => $attemptid, 'image_status' => 'I' ))) {
            $DB->delete_records('quizaccess_proctor_data', array('id' => $qpd->id));
        }
    }

    $record = new stdClass();
    $record->userid = $user->id;
    $record->quizid = $quizid;
    $record->image_status = $mainimage ? 'I' : 'A';
    $record->aws_response = 'aws';
    $record->timecreated = time();
    $record->timemodified = time();
    $record->userimg = $data;
    $record->attemptid  = $attemptid;
    $record->status  = $status;
    $id = $DB->insert_record('quizaccess_proctor_data', $record);

    $tmpdir = make_temp_directory('quizaccess_quizproctoring/captured/');
    file_put_contents($tmpdir . 'myimage.png', $data);
    $fs = get_file_storage();
    // Prepare file record object
    $context = context_module::instance($cmid);
    $fileinfo = array(
        'contextid' => $context->id,
        'component' => 'quizaccess_quizproctoring',
        'filearea'  => 'cameraimages',   
        'itemid'    => $id,
        'filepath'  => '/',
        'filename'  => $attemptid . "_" . $USER->id . '_myimage.png');
    $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'], 
            $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
    if ($file) {
        $file->delete();
    }
    $fs->create_file_from_pathname($fileinfo, $tmpdir . 'myimage.png');
    @unlink($tmpdir . 'myimage.png');

    if ( !$mainimage ) {
        $quizaccess_quizproctoring = $DB->get_record('quizaccess_quizproctoring',array('quizid' => $quizid));

        $error_string = '';
        if (isset($quizaccess_quizproctoring->warning_threshold) && $quizaccess_quizproctoring->warning_threshold != 0) {
            $errors = array(QUIZACCESS_QUIZPROCTORING_NOFACEDETECTED, QUIZACCESS_QUIZPROCTORING_MULTIFACESDETECTED, QUIZACCESS_QUIZPROCTORING_FACESNOTMATCHED, QUIZACCESS_QUIZPROCTORING_FACEMASKDETECTED);
            list($inSql, $inParams) = $DB->get_in_or_equal($errors);
            $error_records = $DB->get_records_sql("SELECT * from {quizaccess_proctor_data} where userid = $user->id AND quizid = $quizid AND attemptid = $attemptid AND image_status = 'A' AND status $inSql", $inParams);

            if (count($error_records) >= $quizaccess_quizproctoring->warning_threshold) {
                // Submit quiz
                $attemptobj = quiz_attempt::create($attemptid);
                $attemptobj->process_finish(time(), false);
                echo json_encode(array('status' => 'true', 'redirect' => 'true', 'url' => $attemptobj->review_url()->out()));
                die();
            }

            if ($status) {
                $left = $quizaccess_quizproctoring->warning_threshold - count($error_records);
                if ($COURSE->lang == 'fr' || $COURSE->lang == 'fr_ca') {
                    if ($left == 1) {
                        $left = $left .get_string('avertissement', 'quizaccess_quizproctoring');
                    } else {
                        $left = $left .get_string('avertissements', 'quizaccess_quizproctoring');
                    }
                } else {
                    if ($left == 1) {
                        $left = $left . get_string('warning', 'quizaccess_quizproctoring');
                    } else {
                       $left = $left . get_string('warnings', 'quizaccess_quizproctoring');
                     }
                }
                $error_string = get_string('warningsleft', 'quizaccess_quizproctoring', $left);
           }


            if ($status && $status != QUIZACCESS_QUIZPROCTORING_EYESNOTOPENED) {
                print_error($status, 'quizaccess_quizproctoring', '', $error_string);
                die();
            }
        }
    }
}