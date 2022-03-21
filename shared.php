<?php
function load_settings(){
    return json_decode(file_get_contents('config.json'), true);
}

function save_settings($config){
    file_put_contents("config.json",json_encode($config));
}
