<?php
/*OdProxy 1.1 SomeBottle 20201018*/
header('Access-Control-Allow-Origin: *');
error_reporting(E_ALL ^ E_NOTICE ^ E_WARNING);
set_time_limit(0);
$allowtags = array('1drv', 'onedrive', 'sharepoint');
$streamtype = array('mp4', 'mp3', 'm4a', 'mpg', 'mpeg', 'wav', 'ogg', 'webm');
$rq = explode('?', $_SERVER["REQUEST_URI"]) [1];
$rq = urldecode($rq);
$parsed = parse_url($rq);
if (!isset($parsed['scheme'])) {
    $rq = 'https:' . $rq;
}
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
            $contenttype = ex($streamtype, $type);
            header('Content-Type: ' . $contenttype);
            header("Accept-Ranges: bytes");
            if (isset($_SERVER['HTTP_RANGE'])) {
                list($name, $range) = explode("=", $_SERVER['HTTP_RANGE']);
                list($begin, $end) = explode("-", $range);
                $firstget = false;
            } else {
                $firstget = true; /*是否第一次加range头*/
                $begin = 0;
                $end = $bytes - 1;
            }
            /*多亏微软接受Range头部：https://docs.microsoft.com/zh-cn/graph/api/driveitem-get-content?view=graph-rest-1.0&tabs=http#partial-range-downloads  */
            $opts = array('http' => array('method' => 'GET', 'header' => array('Range: bytes=' . $begin . '-' . $end), 'timeout' => 15 * 60), 'ssl' => array('verify_peer' => false, 'verify_peer_name' => false));
            $ct = stream_context_create($opts);
            $st = fopen($rq, 'r', false, $ct);
            $rthd = $http_response_header; /*return header*/
            foreach ($rthd as $v) {
                header($v); /*把返回头原样输出*/
            }
            if ($firstget) header('HTTP/1.1 200 OK'); /*下载文件的时候必须要返回头200 OK才行*/
            @ob_start();
            while (!feof($st)) { /*输出流媒体*/
                $output = fread($st, 524288);
                echo $output;
                ob_flush();
                echo ob_get_clean();
                flush();
            }
            @ob_end_flush();
        } else {
            $st = fopen($rq, 'r');
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
            @ob_end_flush();
        }
        fclose($st);
    } else {
        echo 'Bad request:illegal file';
    }
} else {
    echo 'Bad request:empty';
}
?>
