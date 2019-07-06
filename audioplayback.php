<?php
require_once './simple_encrypt.module.php';

/**
 * Function to determine source is encrypted
 * @return boolean TRUE if source is encrypted
 */
function isEncrypted() {
    $Signature = isset($_GET['Signature']) ? $_GET['Signature'] : null;

    return !!$Signature;
}

/**
 * Generate hash fron GET parameter
 * and check hash to check header is valid
 * If password is wrong, SN will not match
 * @param  [type] $password password string
 * @return [type]           TRUE if request is valid
 */
function determineValidParameter($password) {
    $arguments = isset($_GET['arguments']) ? $_GET['arguments'] : null;
    $hash = isset($_GET['SN']) ? $_GET['SN'] : null;
    $text = '';
    if(!$arguments || !$hash) {
        return FALSE;
    }
    $arguments_split = explode(',', $arguments);
    foreach($arguments_split as $eachArgument) {
        $argType = gettype($eachArgument);
        if(!isset($_GET[$eachArgument]) || !($argType === 'number' || $argType === 'string')) {
            return FALSE;
        }

        $text .= (string)$_GET[$eachArgument];
    }

    return substr(md5($text . $password), 0, 12) == $hash;
}

$password = SimpleEncrypt::getPassword();

// If GET parameter is invalid(Expired / Invalid password)
if(!determineValidParameter($password)) {
    // Consider this request as "Not valid" and send HTTP 403
    header('HTTP/1.1 403 Forbidden');
    header('X-SimpleEncrypt-Reason: Invalid Parameter');
    return;
}

$uploaded_filename = null;

// If source is encrypted(needs decryption to play)
if(isEncrypted()) {
    // Check encrypt / decrypt is supported on this system
    if(SimpleEncrypt::isEncryptSupported()) {
        $Signature = $_GET['Signature'];
        if($Signature && $password) {
            $data = SimpleEncrypt::getDecrypt($Signature, $password);
            if($data) {
                $uploaded_filename = $data;
            }
        }
    }
// If source is not encrypted, pass uploaded mp3 file path(direct play)
} else {
    $uploaded_filename = $file = $_GET['file'];
}

// Determine MIME type from request
$mimeType = isset($_GET['mime']) && $_GET['mime'] !== 'unknown' ? $_GET['mime'] : null;

// Parse offset, isSegmant
$startOffset = isset($_GET['start']) ? (int)$_GET['start'] : null;
$endOffset = isset($_GET['end']) ? (int)$_GET['end'] : null;
$isSegment = $_GET['type'] === 'realtime';

// Try to load mp3 source
$uploaded_filename = '../../'.$uploaded_filename;
$filesize = null;

// If file exists
if($uploaded_filename && file_exists($uploaded_filename)) {
    $filesize = filesize($uploaded_filename);

    // Check range parameter if segmented play(realtime)
    if($isSegment) {
        // If range is invalid
        if($startOffset < 0 || $endOffset>$filesize || $startOffset>$endOffset) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            exit();
        }
    }
// If file not exists
} else {
    header('HTTP/1.1 404 Not Found');
    exit();
}

// Get file start / end position, length
$streamStartOffset = isset($_GET['streamStartOffset']) ? (int)$_GET['streamStartOffset'] : 0;
$streamEndOffset = isset($_GET['streamEndOffset']) ? (int)$_GET['streamEndOffset'] : $filesize-1;
$streamLength = $streamStartOffset !== null && $streamEndOffset !== null ? $streamEndOffset-$streamStartOffset+1 : $filesize;

// Open mp3 file to memory
$file = fopen($uploaded_filename, 'r');
header('Accept-Ranges: bytes');

// If segmented(realtime) play
if($isSegment) {
    $size = $endOffset-$startOffset+1;
    fseek($file, $startOffset);
    $data = fread($file, $size);
    header('Content-Length: ' . $size);

    echo $data;
// Else(send whole file at once)
} else {
    header('Content-Type: '.$mimeType);
    header("Accept-Ranges: bytes");
    $httpRange = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : null;
    $c_start = 0;
    $c_end   = $streamEndOffset-$streamStartOffset;
    $c_length = $c_end+1;
    $_start = $c_start;
    $_end = $c_end;
    if ($httpRange) {
        list(, $range) = explode('=', $httpRange, 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $c_start-$c_end/".($c_length));
            exit;
        }
        if ($range == '-') {
            $_start = 0;
        }else{
            $range  = explode('-', $range);
            $_start = $range[0];
            $_end   = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $c_length-1;
        }
        $_end = ($_end > $c_end) ? $c_end : $_end;
        if ($_start > $_end || $_start > $c_length - 1 || $_end >= $c_length || $_start < $c_start || $_end > $c_end) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $c_start-$c_end/$c_length");
            exit;
        }

        header('HTTP/1.1 206 Partial Content');
    }

    $start  = $streamStartOffset + $_start;
    $end    = $streamStartOffset + $_end;
    $length = $_end - $_start + 1;
    fseek($file, $start);

    header("Content-Range: bytes $_start-$_end/$c_length");
    header("Content-Length: ".$length);

    $buffer = 1024 * 8;
    while(!feof($file) && ($p = ftell($file)) <= $end) {
        if ($p + $buffer > $end) {
            $buffer = $end - $p + 1;
        }
        set_time_limit(0);
        echo fread($file, $buffer);
        flush();
    }
}

fclose($file);
