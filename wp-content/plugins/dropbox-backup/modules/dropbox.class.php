<?php
error_reporting(E_ALL ^E_NOTICE ^E_WARNING);

require dirname(__FILE__)."/OAuthSimple.php";

class dropbox
{
    private $app_key;
    private $app_secret;
    private $token;

    private $max_filesize_mb = 150;
    private $chunk_size_mb   = 50;
    private $request_token;

    private $api_url         = "https://api.dropbox.com/1/";   // api.dropboxapi.com      api.dropbox.com
    private $api_url_content = "https://api-content.dropbox.com/1/";


    /**
    * @param $app_key
    * @param $app_secret
    * @param string $token - постоянный токен
    */
    public function __construct ($app_key, $app_secret, $token = "")
    {
        $this->app_key    = $app_key;
        $this->app_secret = $app_secret;
        $this->token      = $token;
    }

    /**
    * Проверка на то, был ли получен постоянный токен
    *
    * @return bool
    */
    public function isAuth()
    {
        return !empty($this->token);
    }

    /**
    * Получение временного токена, необходимого для авторизации и получения токена постоянного
    *
    * @return mixed
    */
    public function getRequestToken()
    {
        if (!$this->request_token) {
            $q = $this->doCurl($this->api_url."oauth/request_token");
            parse_str($q, $data_url);
            $this->request_token = $data_url;
        }

        return $this->request_token;
    }

    /**
    * Авторизация, шаг1: Генерация ссылки для авторизации приложения
    *
    * @param $callback - URL для возвращения токена после авторизации
    * @return string
    */
    public function generateAuthUrl($callback)
    {
        $request_token = $this->getRequestToken();

        return "https://www.dropbox.com/1/oauth/authorize?oauth_token=".$request_token['oauth_token']."&oauth_callback=".urlencode($callback);
    }


    /**
    * Получение токена, необходимого для запросов к API
    *
    * @param $request_token - временный токен, полученный функцией getRequestToken
    * @return string токен
    */
    public function getAccessToken($request_token)
    {
        if (!empty($this->token)) {
            return $this->token;
        }

        $this->request_token = $request_token;

        parse_str($this->doCurl($this->api_url."oauth/access_token"), $parsed_str);

        return $parsed_str;
    }

    /**
    * Удаление файла
    *
    * @param $file
    * @return mixed
    */
    public function deleteFile($file)
    {
        return $this->doApi("fileops/delete", "POST", array(
        "path" => $file,
        "root" => "auto"
        ));
    }

    /**
    * Удаление нескольких файлов
    *
    * @param array $files
    * @return array
    */
    public function deleteFiles($files = array())
    {
        $result = array();

        foreach ($files as $file) {
            $do = $this->deleteFile($file);
            $result[] = array (
            "name"      => $file,
            "result"    => $do['error'] ? 0 : 1
            );
        }

        return $result;
    }

    /**
    * Перемещение файлов
    *
    * @param $from - откуда
    * @param $to - куда
    * @return mixed
    */
    public function moveFile ($from, $to)
    {
        return $this->doApi("fileops/move", "POST", array(
        "root"       => "auto",
        "from_path"  => $from,
        "to_path"    => $to
        ));
    }

    public function listing($path)
    {
        $dir = $this->doApi("metadata/auto/".ltrim($path, "/"), "GET", array(
        "list" => TRUE
        ));

        if ($dir['error']) {
            return $dir;
        }
        //this not folder
        elseif (!$dir['is_dir']) {
            return array (
            "size"  => $dir['size'],
            "bytes"  => $dir['bytes'],
            "date"  => $dir['modified'],
            "name"  => $dir['path']
            );
        }

        $all_size = 0;
        $items   = array();
        foreach ($dir['contents'] as $item) {
            if (!$item['is_dir']) {
                switch (substr($item['size'], -2)) {
                    default:
                        $size = $item['size'];
                        break;

                    case "KB":
                        $size = substr($item['size'], 0, -2)*1024;
                        break;

                    case "MB":
                        $size = substr($item['size'], 0, -2)*1024*1024;
                        break;

                    case "GB":
                        $size = substr($item['size'], 0, -2)*1024*1024*1024;
                        break;
                }
                $all_size += $size;
            }

            $items[]  = array (
            "type"  => $item['is_dir'] ? "dir" : "file",
            "date"  => $item['modified'],
            "name"  => basename($item['path']),
            "size"  => $item['size']
            );
        }

        $dir_result = array (
        "size"  => $this->bite2other($all_size),
        "date"  => $dir['modified'],
        "name"  => $dir['path'],
        "items" => $items
        );

        return $dir_result;
    }

    private function bite2other($size)
    {
        $kb = 1024;
        $mb = 1024 * $kb;
        $gb = 1024 * $mb;
        $tb = 1024 * $gb;

        if ($size < $kb) {
            return $size.' B';
        } else if ($size < $mb) {
                return round($size / $kb, 2).' KB';
            } else if ($size < $gb) {
                    return round($size / $mb, 2).' MB';
                } else if ($size < $tb) {
                        return round($size / $gb, 2).' GB';
                    } else {
                        return round($size / $tb, 2).' TB';
        }
    }

    /**
    * Скачивание файла
    *
    * @param $file
    * @param $server_path - путь, куда скачать на сервер. Если задан -- вернет путь к файлу в случае успешной загрузки.
    * @return array
    */
    public function downloadFile($file, $server_path = "")
    {
        $isset = $this->listing($file);

        if (!$isset['error'])
        {
            //Отдаем в браузер
            if (empty($server_path)) {
                header("Pragma: public");
                header("Expires: 0");
                header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
                header("Cache-Control: private", false);
                header("Content-Type: application/force-download");
                header('Content-Disposition: attachment; filename="'.$file.'"');
                header("Content-Transfer-Encoding: binary");

                $this->doApi("files/auto/".ltrim($file, "/"), "GET", array(), array(
                'socket' => array('file_download' => $server_path),
                CURLOPT_BINARYTRANSFER => 1,
                CURLOPT_RETURNTRANSFER => 0,
                ), TRUE);
                exit;
            }
            //Скачиваем на сервер
            else {
                //Файл недоступен для записи
                if (!$fd = fopen($server_path, "wb")) {
                    return array (
                    "error" => 1,
                    "text"  => "File '".$server_path."' not writable"
                    );
                }

                $this->doApi("files/auto/".ltrim($file, "/"), "GET", array(), array(
                'socket' => array('file_download' => $server_path),
                CURLOPT_BINARYTRANSFER => 1,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_FILE           => $fd
                ), TRUE);

                fclose($fd);

                return $server_path;
            }

        }
        else {
            return $isset;
        }
    }

    /**
    * Поделиться файлом
    *
    * @param $file
    * @param bool $short_url - короткая ссылка
    * @return mixed
    */
    public function shareFile($file, $short_url = TRUE)
    {
        $result = $this->doApi("shares/auto/".ltrim($file, "/"), "POST", compact("short_url"));
        return $result['error'] ? $result : $result['url'];
    }

    /**
    * Загрузка нескольких файлов
    *
    * @param $files - массив формата файл_на_сервере => файл_в_дропбоксе
    *
    * @return array - информация о списке файлов
    */
    public function uploadFiles($files = array())
    {
        $result = array();

        foreach ($files as $file => $dbx) {
            $do = $this->uploadFile($file, $dbx);
            $result[] = array (
            "name"      => $file,
            "result"    => $do['error'] ? 0 : 1
            );
        }

        return $result;
    }

    /**
    * Загрузка файла
    *
    * @param $file_path - путь к файлу на сервере
    * @param $dropbox_path - куда загружать, если не задан, будет загружен в корень дропбокса с таким же именем
    * @param bool $overwrite - заменять ли файлы со схожими именами
    * @return mixed
    */
    public function uploadFile($file_path, $dropbox_path = "", $overwrite = FALSE)
    {
        if (empty($dropbox_path)) {
            $dropbox_path = basename($file_path);
        }

        if (!is_file($file_path)) {
            return array(
            "error" => 1,
            "text"  => "File '".$file_path."' not found."
            );
        }

        $fsize = filesize($file_path);
        $file = fopen($file_path, "rb");

        if ($fsize/1024/1024 < $this->max_filesize_mb)
        {
            $result = $this->doApi("files_put/auto/".ltrim($dropbox_path, "/"),
            "PUT",
            compact ("overwrite"),
            array(
            'socket' => array('file' => $file_path),
            CURLOPT_INFILE          => $file,
            CURLOPT_INFILESIZE      => filesize($file_path),
            CURLOPT_BINARYTRANSFER  => 1,
            CURLOPT_PUT             => 1
            ),
            TRUE);

        }
        //Файл слишком велик
        else {
            $upload_id = "";
            $offset    = 0;

            while(!feof($file)) {

                $chunk = min($fsize-$offset, $this->chunk_size_mb);
                $offset += $chunk;

                $result = $this->doApi("chunked_upload",
                "PUT",
                compact("upload_id", "offset"),
                array(
                'socket' => array('file' => $file_path, 'dropbox_path' => $dropbox_path),

                CURLOPT_INFILE          => $file,
                CURLOPT_INFILESIZE      => $chunk,
                CURLOPT_BINARYTRANSFER  => 1,
                CURLOPT_PUT             => 1
                ),
                TRUE);

                fseek($file, $offset);
                if($offset >= $fsize) {
                    break;
                }

                if (empty($upload_id)) {
                    $upload_id = $result['upload_id'];
                }
            }

            $result = $this->doApi("commit_chunked_upload/auto/".ltrim($dropbox_path, "/"),
            "POST",
            compact("upload_id", "overwrite"),
            array(),
            TRUE);
        }

        @fclose($file);

        return $result;
    }

    /**
    * Создание директории
    *
    * @param $path
    * @return mixed
    */
    public function createDir($path)
    {
        return $this->doApi("fileops/create_folder", "POST", array(
        "root" => "auto",
        "path" => $path
        ));
    }

    /**
    *  Информация об аккаунте
    *
    * @return mixed
    */
    public function accInfo()
    {
        return $this->doApi("account/info", "GET");
    }

    /** Запрос к API
    *
    * @param $op - операция (url)
    * @param $method - post/get
    * @param array $data - доп. данные, url запрос
    * @param array $opts - доп. параметры для curl
    * @param $api_content - использовать url для загрузки файлов
    * @return mixed
    */
    private function doApi ($op, $method, $data = array(), $opts = array(), $api_content = FALSE)
    {
        if (($method == "GET" || $method == "PUT") && count($data)) {
            $op .= "?".http_build_query($data);
        }

        $result = $this->doCurl((!$api_content ? $this->api_url : $this->api_url_content).$op, $method, $data, $opts);
        $return = json_decode($result, TRUE);
        return self::checkError($return);
    }

    /**
    * Обертка для отправки подписанного запроса через curl
    *
    * @param $url
    * @param string $method
    * @param array $data - POST данные
    * @param $opts - доп. параметры для curl
    * @return mixed
    */
    public function doCurl($url, $method = "POST", $data = array(), $opts = array())
    {
        $result = '';
        $oauth = new OAuthSimple($this->app_key, $this->app_secret);

        if (!$this->request_token && $this->token) {
            $this->request_token = $this->token;
        }

        if ($this->request_token) {
            $oauth->setParameters(array('oauth_token' => $this->request_token['oauth_token']));
            $oauth->signatures(array('oauth_secret'=> $this->request_token['oauth_token_secret']));
        }

        if ($method == "POST" && count($data)) {
            $oauth->setParameters(http_build_query($data));
        }

        $path = $url;
        $query = strrchr($url,'?');
        if(!empty($query)) {
            $oauth->setParameters(substr($query,1));
            $path = substr($url, 0, -strlen($query));
        }

        $signed = $oauth->sign(array(
        'action' => $method,
        'path'   => $path
        )
        );

        if (function_exists('curl_init')) {
            if (isset($opts['socket'])) {
                unset($opts['socket']);
            }
            $ch = curl_init($url);
            $opts += array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HEADER         => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            );

            if ($method == "POST") {
                $opts[CURLOPT_POST]         = TRUE;
                $opts[CURLOPT_POSTFIELDS]   = http_build_query($data);
            }

            //$oauth = new OAuthSimple($this->app_key, $this->app_secret);

            /*if (!$this->request_token && $this->token) {
            $this->request_token = $this->token;
            }  

            if ($this->request_token) {
            $oauth->setParameters(array('oauth_token' => $this->request_token['oauth_token']));
            $oauth->signatures(array('oauth_secret'=> $this->request_token['oauth_token_secret']));
            }

            if ($method == "POST" && count($data)) {
            $oauth->setParameters(http_build_query($data));
            }

            $path = $url;
            $query = strrchr($url,'?');
            if(!empty($query)) {
            $oauth->setParameters(substr($query,1));
            $path = substr($url, 0, -strlen($query));
            }

            $signed = $oauth->sign(array(
            'action' => $method,
            'path'   => $path
            )
            );  */  
            $opts[CURLOPT_HTTPHEADER][] = "Authorization: ".$signed['header'];

            if ($method == "PUT") {
                $opts[CURLOPT_CUSTOMREQUEST] = "PUT";
            }
            curl_setopt_array($ch, $opts);
            $result = curl_exec($ch);

        } elseif (function_exists('fsockopen')) {
            include_once dirname(__FILE__) . '/HttpFsockopen.php';
            $socket = new HttpFsockopen($url, false);
            if ($method == 'POST') {
                $socket->setPostData($data);
            }
            if ($method == 'PUT') {
                if (isset($opts['socket']['file'])) {
                    $data = '';
                    srand( (double)microtime() * 1000000);
                    $file_data = join('', file( $opts['socket']['file'] ) ) ;
                    $rand =  substr( md5( rand( 0, 32000 ) ), 0, 10);
                    $boundary =  $rand; 
                    $data = ""; 
                    $data .= "Content-Disposition: form-data; name=\"" . basename($opts['socket']['file']) ."\"; filename=\"" . basename( $opts['socket']['file'] ) . "\"\r\n";
                    $data .= "Content-Type: application/x-www-form-urlencoded;\r\n";  //multipart/form-data;
                    $data .= "Content-length: " . strlen($file_data) . "\r\n";
                    $data .= "Connection: close\r\n\r\n";
                    $data .= $file_data ;
                    $socket->setStrHeaders($data);
                    $socket->setMethod("PUT");
                }
            }
            $socket->setHeaders('Authorization', $signed['header']);
            $res = $socket->exec();
            if ($method == 'GET') {
                if (isset($opts['socket']['file_download'])) {
                    $r = $this->getResult($res);
                    if (isset($r[1])) {
                        file_put_contents($opts['socket']['file_download'], $r[1]);
                    }
                }
            } 
            if ( empty($result) ) {
                $res = $this->getResult($res);
                if (isset($res[1])) {
                    $res1 = $this->getResult($res[1], "\r", true);
                    preg_match("/{.*}/is", trim($res1), $r);
                    if(isset($r[0])) {
                        $result =  $r[0];
                    } else {
                        $res1 = $this->getResult($res[1], "\n", true);
                        preg_match("/{.*}/is", trim($res1), $r);
                        if(isset($r[0])) {
                            $result =  $r[0];
                        } else {
                            $res1 = $this->getResult($res[1], "\r\n", true);
                            preg_match("/{.*}/is", trim($res1), $r);
                            if(isset($r[0])) {
                                $result =  $r[0];
                            }

                        }
                    }
              
                }
            }
        }
        return $result;
    }

    private function getResult($str, $patern = "\r\n\r\n", $is_json = false)
    {
        if ($is_json === false) {
            return explode($patern, $str);
        } else {
            $res = explode($patern, $str); 
            $n = count($res);
            $result = '';
            for($i = 0; $i < $n; $i++) {
                if ( preg_match_all("/[\{\"\:\,\}\s]+/is", trim($res[$i]), $r ) ) {
                    $result .= str_replace(array("\r", "\n"), '', $res[$i]); 
                }
            }
            return $result;
        }
    }


    private static function checkError($return)
    {
        if(!empty($return['error']))
            return array ("error" => 1, "text" => $return['error']);
        return $return;
    }


}
