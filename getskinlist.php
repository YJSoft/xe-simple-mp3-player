<?php
$act = $_GET['act'];
if($act == 'getSimpleMP3SkinInfo') {
    $result = new stdClass();
    $result->message = "not-implemented";
    $result->code = -1;
    echo json_encode($result);
} else if($act == 'getSimpleMP3SkinList') {
    $result = new stdClass();
    $result->skins = array();
    $result->message = "not-implemented";
    $result->code = -1;
    echo json_encode($result);
}
