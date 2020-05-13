<?php
error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
set_time_limit(0);
$allowtags = array('1drv', 'onedrive', 'sharepoint');
$streamtype = array('mp4', 'mp3', 'm4a');
$rq = explode('?', $_SERVER["REQUEST_URI"]) [1];
$parsed = parse_url($rq);
if (!isset($parsed['scheme'])) {
    $rq = 'https:' . $rq;
}
$rq=urldecode($rq);
function typetrans($arr) {
    if (is_array($arr)) {
        $t = '';
        foreach ($arr as $v) {
            $t.= $v . ';';
        }
        return $t;
    } else {
        return $arr;
    }
}
function ex($arr, $v) { /*判断是否在数组里有匹配的*/
    $rt = false;
    if (!is_array($v)) {
        foreach ($arr as $val) {
            if (stripos($v, $val) !== false) {
                $rt = true;
            }
        }
    } else {
        foreach ($arr as $val) {
            foreach ($v as $val2) {
                if (stripos($val2, $val) !== false) {
                    $rt = $val2;
                }
            }
        }
    }
    return $rt;
}
if (!empty($rq) && ex($allowtags, $rq)) {
    $st = fopen($rq, 'r');
    $hd = get_headers($rq, 1);
    $bytes = $hd['Content-Length'];
    $type = $hd['Content-Type'];
    if ($bytes > 0) {
        $dispostion = $hd['Content-Disposition']; /*获取文件信息*/
        /*转发下载*/
        header('Content-Description: File Transfer');
        if (!$type) {
            $type = 'application/octet-stream';
        }
        if (ex($streamtype, typetrans($type))) { /*流媒体*/
            /*$contenttype = ex($streamtype, $type);
            header("Content-type: " . $contenttype);
            header("Accept-Ranges: bytes");
            if (isset($_SERVER['HTTP_RANGE'])) {
                header("HTTP/1.1 206 Partial Content");
                list($name, $range) = explode("=", $_SERVER['HTTP_RANGE']);
                list($begin, $end) = explode("-", $range);
                if ($end == 0) {
                    $end = $bytes - 1;
                }
            } else {
                $begin = 0;
                $end = $bytes - 1;
            }
            header("Content-Length: " . ($end - $begin + 1));
            header("Content-Disposition: " . $dispostion);
            header("Content-Range: bytes " . $begin . "-" . $end . "/" . $bytes);
            fseek($st, $begin);
            while (!feof($fp)) {
                $p = min(1048576, $end - $begin + 1);
                $begin+= $p;
                echo fread($st, $p);
            }*/
        }
            header('Content-Type: ' . $type);
            header('Content-Disposition: ' . $dispostion);
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');
            header('Content-Length: ' . $bytes);
            @ob_start();
            while (!feof($st)) {
                $output = fread($st, 1048576);
                echo $output;
                ob_flush();
                echo ob_get_clean();
                flush();
            }
            fclose($st);
            @ob_end_flush();
        fclose($st);
    } else {
        echo 'Bad request:1';
    }
} else {
    echo 'Bad request:2';
}
?>
