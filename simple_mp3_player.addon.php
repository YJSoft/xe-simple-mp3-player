<?php
if(!defined("__XE__")) exit();

$simple_mp3_autoload_map = array(
  "PHPMP3" => _XE_PATH_ . 'addons/simple_mp3_player/lib/phpmp3.php',
  "getID3" => _XE_PATH_ . 'addons/simple_mp3_player/lib/getid3/getid3.php',
  "SimpleEncrypt" => _XE_PATH_ . 'addons/simple_mp3_player/simple_encrypt.module.php',
  "SimpleMP3Describer" => _XE_PATH_ . 'addons/simple_mp3_player/simple_mp3describer.module.php'
);

function _simple_mp3_autoload_function($class) {
    if(isset($simple_mp3_autoload_map[$class])) require_once($simple_mp3_autoload_map[$class]);
}

spl_autoload_register('_simple_mp3_autoload_function');

$act = Context::get('act');
if($called_position === 'before_module_init' && in_array($_SERVER['REQUEST_METHOD'], array('GET', 'POST'))){
    if(in_array($act, array('geSimpleMP3Description', 'geSimpleMP3Descriptions'))) {
        $config = new stdClass();
        $config->use_mediasession = !(isset($addon_info->use_mediasession) && $addon_info->use_mediasession === "N");
        $config->use_url_encrypt = !(isset($addon_info->use_url_encrypt) && $addon_info->use_url_encrypt === "N");
        $config->allow_autoplay = !(isset($addon_info->allow_autoplay) && $addon_info->allow_autoplay === "N");
        $config->link_to_media = (isset($addon_info->link_to_media) && $addon_info->link_to_media === "Y");
        $config->default_cover = isset($addon_info->default_cover) ? $addon_info->default_cover : null;
        $config->allow_browser_cache = (isset($addon_info->allow_browser_cache) && $addon_info->allow_browser_cache === "Y");
        $config->playlist_player_selector = isset($addon_info->playlist_player_selector) ? $addon_info->playlist_player_selector : null;
        if(!$config->default_cover) {
            $config->default_cover = _XE_PATH_ . 'addons/simple_mp3_player/img/no_cover.png';
        }
        if(!$config->playlist_player_selector) {
            $config->playlist_player_selector = '.simple_mp3_player';
        }

        $password = null;
        if(SimpleEncrypt::getPassword()) {
            $password = SimpleEncrypt::getPassword();
        } else if(!SimpleEncrypt::buildNewPassword()) {
            $config->use_url_encrypt = false;
        } else {
            $password = SimpleEncrypt::getPassword();
        }

        if(!$password) {
            $config->use_url_encrypt = false;
            $config->allow_browser_cache = true;
        }

        $result = new stdClass();
        if($act === 'geSimpleMP3Descriptions') {
            ini_set('max_execution_time', 15);
            $document_srl = Context::get('document_srl');
            $describer = new SimpleMP3Describer($config->allow_browser_cache, $config->use_url_encrypt, $password);
            $descriptions = $describer->getDescriptionsByDocumentSrl($document_srl);
            $result->descriptions = $descriptions;
        }
        $result->message = "success";
        $result->config = $config;
        echo json_encode($result);

        exit();
    }

} else if(in_array($act, array('procFileDelete', 'procBoardDeleteDocument', 'procBoardDeleteComment'))) {
    if($called_position === 'before_module_proc') {
        $target_srl = Context::get('document_srl');
        if(!$target_srl) {
            $target_srl = Context::get('comment_srl');
        }
        if($target_srl) {
            SimpleMP3Describer::prepareToRemoveFilesFromTargetSrl($target_srl);
        } else {
            $file_srl = Context::get('file_srl');
            $file_srls = Context::get('file_srls');
            if($file_srls) {
                $file_srls = explode(',',$file_srls);
            } else if($file_srl) {
                $file_srls = array($file_srl);
            }
            if($file_srls) {
                SimpleMP3Describer::prepareToRemoveFilesFromByFileSrls($file_srls);
            }
        }
    } else if ($called_position === 'after_module_proc') {
        SimpleMP3Describer::HandleDeleteDescription();
    }

} else if($called_position == 'after_module_proc' && Context::getResponseMethod()!="XMLRPC" && Context::get('document_srl')) {
    Context::loadFile(array(_XE_PATH_ . 'addons/simple_mp3_player/js/corejs.min.js', 'body', '', null), true);
    Context::loadFile(array(_XE_PATH_ . 'addons/simple_mp3_player/js/transmuxer.js', 'body', '', null), true);
    Context::loadFile(array(_XE_PATH_ . 'addons/simple_mp3_player/js/base.js', 'body', '', null), true);
    if(!isset($addon_info->playlist_player) || !$addon_info->playlist_player) {
        $addon_info->playlist_player = 'APlayer';
    }
    if($addon_info->playlist_player === 'APlayer') {
        Context::loadFile(_XE_PATH_ . 'addons/simple_mp3_player/css/APlayer.min.css', true);
        Context::loadFile(array(_XE_PATH_ . 'addons/simple_mp3_player/js/APlayer.min.js', 'body', '', null), true);
        Context::loadFile(array(_XE_PATH_ . 'addons/simple_mp3_player/js/aplayer_loader.js', 'body', '', null), true);
    } else if($addon_info->playlist_player === 'APlayer_fixed') {
        Context::loadFile(_XE_PATH_ . 'addons/simple_mp3_player/css/APlayer.min.css', true);
        Context::loadFile(array(_XE_PATH_ . 'addons/simple_mp3_player/js/APlayer.min.js', 'body', '', null), true);
        Context::loadFile(array(_XE_PATH_ . 'addons/simple_mp3_player/js/aplayer_fixed_loader.js', 'body', '', null), true);
    }
    if(isset($addon_info->link_to_media) && $addon_info->link_to_media === "Y") {
        Context::loadFile(array(_XE_PATH_ . 'addons/simple_mp3_player/js/mp3link_to_player.js', 'body', '', null), true);
    }
}
