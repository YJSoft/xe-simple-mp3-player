<?php
/**
 * Handles MP3 file streaming
 */
class SimpleMP3Describer {
    private $use_encrypt = false;
    private $password = null;
    private $allow_browser_cache = false;

    public function __construct($allow_browser_cache = false, $use_encrypt = false, $password = null) {
        if($password) {
            $this->password = $password;
        }
        $this->allow_browser_cache = $allow_browser_cache;
        if($use_encrypt && SimpleEncrypt::isEncryptSupported()) {
            $this->use_encrypt = $use_encrypt;
            $this->password = $password ? $password : SimpleEncrypt::getPassword();
        }
    }

    public function getURLEncrypt($uploaded_filename) {
        return SimpleEncrypt::getEncrypt($uploaded_filename, $this->password);
    }

    public function getMIMEType($extension = null) {
        if($extension) {
            $extension = strtolower($extension);
            if($extension === 'mp3') {
                return 'audio/mpeg';
            } else if($extension === 'm4a') {
                return 'audio/mp4';
            } else if($extension === 'ogg') {
                return 'audio/ogg';
            } else if($extension === 'flac') {
                return 'audio/flac';
            }
        }

        return null;
    }

    private function createMP3URL($uploaded_filename, $args = array()) {
        if($this->allow_browser_cache) {
            return $uploaded_filename;
        }

        $argsArr = array();
        if($this->use_encrypt) {
            $argsArr[] = array('key'=> 'Signature', 'value' => $this->getURLEncrypt($uploaded_filename));
        } else {
            $argsArr[] = array('key'=> 'file', 'value' => $uploaded_filename);
        }

        $argsArr = array_merge($argsArr, $args);

        return $this->createURLWithParameters($argsArr, array('Signature', 'duration'));
    }

    private function createURLWithParameters($argsArr, $skipArgsArr = array()) {
        $url = './addons/simple_mp3_player/audioplayback.php?';
        $keys = array();
        $valueStr = '';
        $isFirst = true;
        foreach($argsArr as $arg) {
            $_arg= (object)$arg;
            $_arg->value = trim($_arg->value);
            if(!$_arg->value && $_arg->value != '0') {
                $_arg->value = 'null';
            }
            if($isFirst) {
                $url .= $_arg->key."=".urlencode($_arg->value);
                $isFirst=false;
            } else {
                $url .= "&".$_arg->key."=".urlencode($_arg->value);
            }
            if(in_array($_arg->key, $skipArgsArr)) {
                continue;
            }
            $keys[] = $_arg->key;
            $valueStr .= $_arg->value;
        }

        $hash = md5($valueStr.$this->password);
        $url .= "&arguments=".implode(",", $keys);
        $url .= "&SN=".substr($hash, 0, 12);

        return $url;
    }

    public function getDescriptionsByDocumentSrl($document_srl) {
        if(!$this->isGranted($document_srl)) {
            return null;
        }
        $descriptions = array();
        $files = $this->getMultipleFilePathname($document_srl);
        $ip = $_SERVER['REMOTE_ADDR'];
        $timestamp = time();
        if($files) {
            foreach($files as $file) {
                $description = self::getDescription($file->file_srl, $file->uploaded_filename, $file->source_filename, $document_srl);
                if($description) {
                    $fileParts = pathinfo($file->uploaded_filename);
                    $sourceFileParts = pathinfo($file->source_filename);
                    $extension = $fileParts && isset($fileParts['extension']) ? $fileParts['extension'] :
                        (isset($sourceFileParts['extension']) ? $sourceFileParts['extension'] : null);
                    if($extension) {
                        $extension = strtolower($extension);
                    }
                    if(isset($description->stream) && $description->stream && $description->stream->format) {
                        $format = $description->stream->format;
                        if($format === 'mp3' && $extension !== 'mp3') {
                            $extension = 'mp3';
                        }
                        if($format === 'flac' && $extension !== 'flac') {
                            $extension = 'flac';
                        }
                        if($format === 'mp4' && !($extension === 'mp4' ||$extension === 'm4a')) {
                            $extension = 'm4a';
                        }
                    }
                    $mime = $this->getMIMEType($extension);
                    if(!$mime) {
                        $mime = 'unknown';
                    }
                    if($description->offsetInfo) {
                        $offsetInfo = $description->offsetInfo;
                        $offsets = $offsetInfo->offsets;
                        $duration = $offsetInfo->duration;
                        $offsetSize = count($offsets);
                        $streamStartOffset = $offsets[0]->startOffset;
                        $streamEndOffset = $offsets[$offsetSize-1]->endOffset;
                        $description->filePath = $this->createMP3URL($file->uploaded_filename, array(
                            array('key'=>'streamStartOffset', 'value'=>$streamStartOffset),
                            array('key'=>'streamEndOffset', 'value'=>$streamEndOffset),
                            array('key'=>'document_srl', 'value'=>$document_srl),
                            array('key'=>'file_srl', 'value'=>$file->file_srl),
                            array('key'=>'mime', 'value'=>$mime),
                            array('key'=>'duration', 'value'=>$duration),
                            array('key'=>'timestamp', 'value'=>$timestamp),
                            array('key'=>'type', 'value'=>'progressive')
                        ));
                        if(!$this->allow_browser_cache) {
                            $currentOffset = 0;
                            foreach ($offsets as $eachOffset) {
                                $eachOffset->url = $this->createMP3URL($file->uploaded_filename, array(
                                    array('key'=>'document_srl', 'value'=>$document_srl),
                                    array('key'=>'file_srl', 'value'=>$file->file_srl),
                                    array('key'=>'streamStartOffset', 'value'=>$streamStartOffset),
                                    array('key'=>'streamEndOffset', 'value'=>$streamEndOffset),
                                    array('key'=>'mime', 'value'=>$mime),
                                    array('key'=>'start', 'value'=>$eachOffset->startOffset),
                                    array('key'=>'end', 'value'=>$eachOffset->endOffset),
                                    array('key'=>'duration', 'value'=>$duration),
                                    array('key'=>'ip', 'value'=>$ip),
                                    array('key'=>'offset', 'value'=>$currentOffset),
                                    array('key'=>'timestamp', 'value'=>$timestamp),
                                    array('key'=>'type', 'value'=>'realtime')
                                ));
                                $currentOffset += $eachOffset->time;
                            }
                        }
                    } else {
                        $arguments = array(
                            array('key'=>'document_srl', 'value'=>$document_srl),
                            array('key'=>'file_srl', 'value'=>$file->file_srl),
                            array('key'=>'mime', 'value'=>$mime),
                            array('key'=>'ip', 'value'=>$ip),
                            array('key'=>'timestamp', 'value'=>$timestamp),
                            array('key'=>'type', 'value'=>'progressive')
                        );
                        if(isset($description->stream)) {
                            $stream = $description->stream;
                            if(isset($stream->duration)) {
                                $arguments[] = array('key'=>'duration', 'value'=>$stream->duration);
                            }
                        }

                        $description->filePath = $this->createMP3URL($file->uploaded_filename, $arguments);
                    }
                }
                $obj = new stdClass;
                $obj->file_srl = $file->file_srl;
                $obj->description = $description;
                $descriptions[] = $obj;
            }
        }

        return $descriptions;
    }

    static function getDescription($file_srl, $uploaded_filename, $source_filename, $document_srl = null) {
        $description = self::getDescriptionFile($file_srl, $uploaded_filename);
        if(!$description) {
            $description = self::getMP3DescriptionFromOrigin($document_srl, $file_srl, $source_filename, $uploaded_filename);
        }

        return $description;
    }

    static function getDescriptionFilePath($file_srl = null, $mp3FilePath = null) {
        $basepath = "./files/simple_mp3_player/";
        $regex = "/(\d+)\/(?:(\d+)\/)?(?:(\d+)\/)?\w+.\w+$/";
        preg_match_all($regex, $mp3FilePath, $result);
        if(count($result[1])) {
            return $basepath . $result[1][0] . "/" . (count($result[2]) && $result[2][0] ? ($result[2][0] . "/") : '') . (count($result[3]) && $result[3][0] ? ($result[3][0] . "/") : '') . ($file_srl ? ($file_srl . "/") : '');
        }

        return null;
    }

    static function getDescriptionFile($file_srl, $pathname) {
        $basePath = self::getDescriptionFilePath($file_srl, $pathname);
        if($basePath) {
            $description = FileHandler::readFile($basePath."description.json");
            if($description) {
                return json_decode($description);
            }
        }

        return null;
    }

    static function getMP3DescriptionFromOrigin($document_srl, $file_srl, $source_filename = null, $filepath = null) {
        if(!$filepath) {
            $filepathData = self::getFilePathname($file_srl, $document_srl);
            if($filepathData) {
                $filepath = $filepathData->uploaded_filename;
            }
        }
        $descriptionFilePath = self::getDescriptionFilePath($file_srl, $filepath);
        if(!$filepath || !$descriptionFilePath) {
            return null;
        }
        $fileParts = pathinfo($filepath);
        $sourceFileParts = pathinfo($source_filename);
        $extension = $fileParts && isset($fileParts['extension']) ? $fileParts['extension'] :
            ($source_filename && $sourceFileParts && isset($sourceFileParts['extension']) ? $sourceFileParts['extension'] : null);
        if(!in_array($extension, array('mp3', 'm4a', 'flac', 'ogg'))) {
            return null;
        }

        $mp3Spec = self::getMP3Spec($filepath);
        $tags = $mp3Spec ? $mp3Spec->tags : null;
        $stream = $mp3Spec ? $mp3Spec->stream : null;
        $obj = new stdClass();
        $obj->filePath = $filepath;
        $obj->filename = $source_filename;
        $obj->offsetInfo = null;
        $obj->tags = $tags;
        $obj->stream = $stream;
        $obj->isValidFile = !!($stream && $stream->format);
        if(($stream && $stream->format === 'mp3') || (!$stream && $extension === 'mp3')) {
            $offsets = self::getSplitPosition($filepath);
            $obj->isValidFile = !!(isset($offsets->duration) && $offsets->duration > 2);
            $obj->offsetInfo = $offsets;
        }

        return self::createDescriptionFile($obj, $descriptionFilePath);
    }

    static function createDescriptionFile($originDescription = null, $savePath) {
        if($originDescription && $savePath) {
            if(!FileHandler::makeDir($savePath)) {
                return null;
            }
            FileHandler::removeFilesInDir($savePath);

            $tag = $originDescription->tags;
            $albumArt = $tag->albumArt;

            $albumArtBuffer = null;
            $albumArtExtension = null;
            if($albumArt && count($albumArt) >= 2) {
                $albumArtBuffer = $albumArt['data'];
                $albumArtExtension = $albumArt['image_mime'] === 'image/png' ? 'png' : ($albumArt['image_mime'] === 'image/gif' ? 'gif' : ($albumArt['image_mime'] === 'image/jpeg' ? 'jpg' : ($albumArt['image_mime'] === 'image/bmp' ? 'bmp' : null)));
            }

            unset($tag->albumArt);
            if($albumArtBuffer && $albumArtExtension) {
                $albumArtFilePath = $savePath . "Cover." . $albumArtExtension;
                FileHandler::writeFile($albumArtFilePath, $albumArtBuffer);
                $tag->albumArt = $albumArtFilePath;
            }
            FileHandler::writeFile($savePath."description.json", json_encode($originDescription));

            return $originDescription;
        }

        return null;
    }

    static function getSplitPosition($pathname) {
        try {
            $mp3 = new PHPMP3($pathname);
            $offsets = $mp3->getSplitPosition(array(2,3,5));
            if(count($offsets) < 3) {
                return null;
            }

            $duration = 0;
            foreach($offsets as $key=>$value) {
                $duration += $value->time;
            }

            $obj = new stdClass;
            $obj->duration = $duration;
            $obj->offsets = $offsets;

            return $obj;
        } catch(Exception $e) {
            return null;
        }
    }

    function isGranted($document_srl = 0) {
        if($document_srl) {
            $oDocumentModel = getModel('document');
            $oDocument = $oDocumentModel->getDocument($document_srl);

            return $oDocument->isExists() && $oDocument->isAccessible();
        }

        return false;
    }

    function getMultipleFilePathname($upload_target_srl = null) {
        if($upload_target_srl) {
            $oFileModel = getModel('file');
            $oFileList = $oFileModel->getFiles($upload_target_srl, array('file_srl', 'uploaded_filename', 'source_filename'));
            if($oFileList) {
                return $oFileList;
            }
        }

        return array();
    }

    static function getFilePathname($file_srl, $upload_target_srl = null) {
        if($file_srl) {
            $oFileModel = getModel('file');
            $oFile = $oFileModel->getFile($file_srl);
            if($oFile && (!$upload_target_srl || $upload_target_srl && $oFile->upload_target_srl == $upload_target_srl)) {
                $obj = new stdClass;
                $obj->uploaded_filename = $oFile->uploaded_filename;
                $obj->source_filename = $oFile->source_filename;

                return $obj;
            }
        }

        return null;
    }

    static function getMP3Spec($mp3Pathname) {
        try {
            $getID3 = new getID3;
            $ThisFileInfo = $getID3->analyze($mp3Pathname);
            if(!$ThisFileInfo || (isset($ThisFileInfo['error']) && count($ThisFileInfo['error']))) {
                return null;
            }
            $tags = new stdClass();
            $tags->artist = null;
            $tags->title = null;
            $tags->album = null;
            $tags->albumArt = null;
            $stream = new stdClass();
            $stream->duration = isset($ThisFileInfo['playtime_seconds']) ? $ThisFileInfo['playtime_seconds'] : null;
            $stream->format = null;
            $stream->bitrate = null;
            $stream->bitrateMode = null;
            $stream->channels = null;
            $stream->channelMode = null;
            $stream->sampleRate = null;
            $stream->startOffset = isset($ThisFileInfo['avdataoffset']) ? $ThisFileInfo['avdataoffset'] : null;
            $stream->endOffset = isset($ThisFileInfo['avdataend']) ? $ThisFileInfo['avdataend'] : null;
            $simpleData = new stdClass();
            $simpleData->format = $ThisFileInfo['fileformat'];
            $simpleData->tags = $tags;
            $simpleData->stream = $stream;
            if(isset($ThisFileInfo['tags'])) {
                $_tag = $ThisFileInfo['tags'];
                $id3 = isset($_tag['id3v2']) ? $_tag['id3v2'] : (isset($_tag['id3v1']) ? $_tag['id3v1'] : null);
                $vorbiscomment = isset($_tag['vorbiscomment']) ? $_tag['vorbiscomment'] : null;
                $quicktime = isset($_tag['quicktime']) ? $_tag['quicktime'] : null;
                $tagTraget = $id3 ? $id3 : $vorbiscomment;
                if(!$tagTraget) {
                    $tagTraget = $quicktime ? $quicktime : null;
                }
                if($tagTraget) {
                    if(isset($tagTraget['title']) && count($tagTraget['title']) && $tagTraget['title'][0]) {
                        $tags->title = removeHackTag($tagTraget['title'][0]);
                    }
                    if(isset($tagTraget['artist']) && count($tagTraget['artist']) && $tagTraget['artist'][0]) {
                        $tags->artist = removeHackTag($tagTraget['artist'][0]);
                    }
                    if(isset($tagTraget['album']) && count($tagTraget['album']) && $tagTraget['album'][0]) {
                        $tags->album = removeHackTag($tagTraget['album'][0]);
                    }
                }
            }
            if(isset($ThisFileInfo['comments']) && isset($ThisFileInfo['comments']['picture']) && count($ThisFileInfo['comments']['picture'])) {
                $tags->albumArt = $ThisFileInfo['comments']['picture'][0];
            }
            if(isset($ThisFileInfo['audio'])) {
                $audioData = $ThisFileInfo['audio'];
                if(isset($audioData['dataformat']) && $audioData['dataformat']) {
                    $stream->format = $audioData['dataformat'];
                }
                if(isset($audioData['bitrate_mode']) && $audioData['bitrate_mode']) {
                    $stream->bitrateMode = $audioData['bitrate_mode'];
                }
                if(isset($audioData['sample_rate']) && $audioData['sample_rate']) {
                    $stream->sampleRate = $audioData['sample_rate'];
                }
                if(isset($audioData['bitrate']) && $audioData['bitrate']) {
                    $stream->bitrate = $audioData['bitrate'];
                }
                if(isset($audioData['channels']) && $audioData['channels']) {
                    $stream->channels = $audioData['channels'];
                }
                if(isset($audioData['channelmode']) && $audioData['channelmode']) {
                    $stream->channelMode = $audioData['channelmode'];
                }
            }

            return $simpleData;

        } catch(Exception $e) {
            return null;
        }

    }

    static function onDeleteFile($pathname) {
        $descriptionPath = self::getDescriptionFilePath(null, $pathname);
        if($descriptionPath) {
            FileHandler::removeFilesInDir($descriptionPath);
            FileHandler::removeBlankDir($descriptionPath);
        }
    }

    public static function prepareToRemoveFilesFromTargetSrl($target_upload_srl) {
        $oFileModel = getModel('file');
        $oFileList = $oFileModel->getFiles($target_upload_srl);
        if(!isset($GLOBALS['__SIMPLE_MP3_PLAYER__'])) {
            $GLOBALS['__SIMPLE_MP3_PLAYER__'] = new stdClass;
            $GLOBALS['__SIMPLE_MP3_PLAYER__']->targetDeleteFiles = array();
        }
        foreach($oFileList as $oFile) {
            $GLOBALS['__SIMPLE_MP3_PLAYER__']->targetDeleteFiles[] = $oFile;
        }
    }

    public static function prepareToRemoveFilesFromByFileSrls($file_srls = array()) {
        $oFileModel = getModel('file');
        if(!isset($GLOBALS['__SIMPLE_MP3_PLAYER__'])) {
            $GLOBALS['__SIMPLE_MP3_PLAYER__'] = new stdClass;
            $GLOBALS['__SIMPLE_MP3_PLAYER__']->targetDeleteFiles = array();
        }
        foreach($file_srls as $file_srl) {
            $oFile = $oFileModel->getFile($file_srl);
            if($oFile) {
                $GLOBALS['__SIMPLE_MP3_PLAYER__']->targetDeleteFiles[] = $oFile;
            }
        }
    }

    public static function HandleDeleteDescription() {
        if(isset($GLOBALS['__SIMPLE_MP3_PLAYER__']) && isset($GLOBALS['__SIMPLE_MP3_PLAYER__']->targetDeleteFiles)) {
            foreach($GLOBALS['__SIMPLE_MP3_PLAYER__']->targetDeleteFiles as $oDeletedFile) {
                if($oDeletedFile && isset($oDeletedFile->uploaded_filename) && $oDeletedFile->uploaded_filename) {
                    self::onDeleteFile($oDeletedFile->uploaded_filename);
                }
            }
        }
    }

    public static function isAccessableDocument($document_srl) {
        $oDocumentModel = getModel('document');
        $oDocument = $oDocumentModel->getDocument($document_srl);
        if($oDocument && $oDocument->isExists() && $oDocument->isAccessible()) {
            return true;
        }
        return false;
    }

    public static function getALSongLyric($file_srl, $expire = 72, $renewDuration = 30) {
        $oFileModel = getModel('file');
        $oFile = $oFileModel->getFile($file_srl);
        if($oFile) {
            $upload_target_srl = $oFile->upload_target_srl;
            $isAccessableDocument = self::isAccessableDocument($upload_target_srl);
            if(!$isAccessableDocument) {
                return null;
            }
            $description = self::getDescription($file_srl, $oFile->uploaded_filename, $oFile->source_filename, $upload_target_srl);
            if($description) {
                $lyricFromFile = self::getALSongLyricFromFile($file_srl, $oFile->uploaded_filename);
                $lyricFileExists = false;
                $requireRenew = false;
                if($lyricFromFile) {
                    $lyricFileExists = true;
                    if($lyricFromFile->lyric) {
                        if($lyricFromFile->birthtime + $expire*60*60 > time()) {
                            return $lyricFromFile->lyric;
                        } else {
                            $requireRenew = true;
                        }
                    } else if($lyricFromFile->lyric === null && $lyricFromFile->birthtime + $renewDuration * 60 > time()) {
                        return null;
                    }
                }
                $startOffset = null;
                $stream = isset($description->stream) && $description->stream ? $description->stream : null;
                $offsetInfo = isset($description->offsetInfo) && $description->offsetInfo ? $description>offsetInfo : null;
                if($stream !== null && isset($stream->startOffset)) {
                    $startOffset = $stream->startOffset;
                }
                if($startOffset === null && $offsetInfo !== null) {
                    $offsets = isset($offsetInfo->offsets) && $offsetInfo->offsets ? $offsetInfo->offsets : null;
                    if($offsets && is_array($offsets) && count($offsets) > 10) {
                        $startOffset = $offsets[0]->startOffset;
                    }
                }
                if($startOffset !== null) {
                    $md5 = self::getALSongLyricHash($oFile->uploaded_filename, $startOffset);
                    if($md5) {
                        $lyric = self::getALSongLyricFromServer($md5);
                        if(!lyric && $requireRenew) {
                            $lyric = $lyricFromFile->lyric;
                        }
                        self::createALSongLyricFile($file_srl, $oFile->uploaded_filename, $lyric);
                        if($lyric) {
                            return $lyric;
                        } else if($lyricFileExists && isset($lyricFromFile->lyric)) {
                            return $lyricFromFile->lyric;
                        } else {
                            return null;
                        }
                    }
                }
            }
        }
        return null;
    }

    public static function getALSongLyricHash($filepath, $startOffset) {
        if(file_exists($filepath)) {
            $filesize = filesize($filepath);
            if($filesize-$startOffset < 163840) {
                return null;
            }
            $fd = fopen($filepath, "rb");
            fseek($fd, $startOffset, SEEK_SET);
            $hash = md5(fread($fd, 163840));
            fclose($fd);
            return $hash;
        }
        return null;
    }

    public static function createALSongLyricFile($file_srl, $uploaded_filename, $lyric = null) {
        $basepath = self::getDescriptionFilePath($file_srl, $uploaded_filename);
        $lrcFilename = $basepath.'lyric.json';
        if($basepath) {
            if(file_exists($lrcFilename)) {
                FileHandler::removeFile($lrcFilename);
            }
            $obj = new stdClass;
            $obj->file_srl = $file_srl;
            $obj->lyric = $lyric;
            $obj->birthtime = time();
            $json = json_encode($obj);
            FileHandler::writeFile($lrcFilename, $json);
        }
    }

    public static function getALSongLyricFromFile($file_srl, $uploaded_filename) {
        $basepath = self::getDescriptionFilePath($file_srl, $uploaded_filename);
        $lrcFilename = $basepath.'lyric.json';
        if($basepath) {
            if (file_exists($lrcFilename)) {
                $lrcJSON = FileHandler::readFile($lrcFilename);
                if($lrcJSON) {
                    try {
                        return json_decode($lrcJSON);
                    } catch(Exception $e) {}
                }
            }
        }
        return null;
    }

    public static function getALSongLyricFromServer($md5) {
        $url = 'http://lyrics.alsong.co.kr/alsongwebservice/service1.asmx';
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'.
            '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://www.w3.org/2003/05/soap-envelope" xmlns:SOAP-ENC="http://www.w3.org/2003/05/soap-encoding" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:ns2="ALSongWebServer/Service1Soap" xmlns:ns1="ALSongWebServer" xmlns:ns3="ALSongWebServer/Service1Soap12">
            <SOAP-ENV:Body><ns1:GetLyric8>
            <ns1:encData></ns1:encData>
            <ns1:stQuery>
            <ns1:strChecksum>'.$md5.'</ns1:strChecksum>
            <ns1:strVersion>3.46</ns1:strVersion>
            <ns1:strMACAddress></ns1:strMACAddress>
            <ns1:strIPAddress>169.254.107.9</ns1:strIPAddress>
            </ns1:stQuery>
            </ns1:GetLyric8></SOAP-ENV:Body>
            </SOAP-ENV:Envelope>';
        $content = FileHandler::getRemoteResource($url, $xml, 5, "POST", "application/soap+xml");
        preg_match('/<strLyric>(.*)?<\/strLyric>/i', $content, $lyricHTML);
        if($lyricHTML && is_array($lyricHTML) && count($lyricHTML) === 2 && $lyricHTML[1]) {
            $lrc = $lyricHTML[1];
            $lrc = str_replace('&lt;br&gt;',"\n",$lrc);
            return $lrc;
        }
        return null;
    }
}
