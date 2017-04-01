<?php 
function add_cors_http_header(){

$http_origin = $_SERVER['HTTP_ORIGIN'];

if ($http_origin == "http://localhost:4200" || $http_origin == "http://roekeloos.be/")
    {  
        header("Access-Control-Allow-Origin: $http_origin");
    }
}
add_action('init','add_cors_http_header');