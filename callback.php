<?php

use Mautic\Auth\ApiAuth;

require_once 'vendor/autoload.php';

require_once('shared.php');

$config = load_settings();

$_SESSION['oauth']['state'] = $config['mautic']['state'];

$initAuth = new ApiAuth();
$auth     = $initAuth->newAuth($config['mautic']);

$auth->validateAccessToken();
if ($auth->accessTokenUpdated()) {
    $accessTokenData = $auth->getAccessTokenData();
    $config['mautic'] = array_merge($config['mautic'],
    [
        'accessToken' => $accessTokenData['access_token'],
        'accessTokenExpires' => $accessTokenData['expires'],
        'refreshToken' => $accessTokenData['refresh_token'],
    ]);
    echo 'Access token acquired, you can close this window and go back to the terminal';
    save_settings($config);
}