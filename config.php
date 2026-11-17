<?php
if(!isset($seg)){
    exit;
}
$url_host = filter_input(INPUT_SERVER, 'HTTP_HOST');
//define('pg', "http://$url_host/sgo_reviver");
define('pg', "https://sgoreviver.com.br");