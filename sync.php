<?php

use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

set_time_limit(0);

require_once "vendor/autoload.php";

require_once('vendor/freemius/php-sdk/freemius/Freemius.php'); //Autoload doesn't work on the Freemius API?

require_once ("config.php");

$handle = fopen ("php://stdin","r");


function get_user_input($text){
    global $handle;
    echo "{$text}: ";
    $line = fgets($handle);
    return trim($line);
}


/**
 * Connect to Freemius
 */
$freemius_plugins = false;
do{
//    $freemius_dev_id = (int)get_user_input("Please enter your Freemius Dev ID (1234)");
//
//    $freemius_public_key = get_user_input("Please enter your Freemius Public key");
//    $freemius_secret_key = get_user_input("Please enter your Freemius Secret key");
    $freemius_dev_id = $freemius_config['dev_id'];
    $freemius_public_key = $freemius_config['public_key'];
    $freemius_secret_key = $freemius_config['secret_key'];

    $freemius_api = new Freemius_Api('developer', $freemius_dev_id, $freemius_public_key, $freemius_secret_key);

    if(!$freemius_api->Test()){
        echo "No connectivity with the Freemius API\n";
        exit;
    }

    $freemius_plugins = $freemius_api->Api("/plugins.json");
    if(isset($freemius_plugins->error)){
        echo "Could not connect to Freemius API: {$freemius_plugins->error->message}\n";
        $freemius_plugins = false;
    }

}while(!$freemius_plugins);
echo "Successfully connected to Freemius\n";

/**
 * Get Plugin ID
 */
$plugin_id = false;
$options = '';
$valid_choices = [];
foreach($freemius_plugins->plugins as $plugin) {
    $options .= "[{$plugin->id}] - {$plugin->title} ({$plugin->slug})\n";
    $valid_choices[] = (int)$plugin->id;
}
do{
//    $plugin_id = (int)get_user_input("Plugin to sync contacts for:\n{$options}");
    $plugin_id = $freemius_config['plugin_id'];
    if(!in_array($plugin_id, $valid_choices)){
        $plugin_id = false;
        echo "Invalid plugin ID\n";
    }
}while(!$plugin_id);
echo "Syncing contacts for plugin {$plugin_id}\n";

/**
 * Connect to database
 */
$database = new mysqli($database_config['hostname'], $database_config['username'], $database_config['password'], $database_config['database']);
if($database->connect_errno){
    echo "Failed to connect to database: {$database->connect_errno}\n";
    exit;
}

$database->query("
CREATE TABLE IF NOT EXISTS `companyids` (
  `internal_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `freemius_id` int(10) unsigned NOT NULL,
  `mautic_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`internal_id`,`freemius_id`,`mautic_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
");

echo "Connected to database and created table\n";

/**
 * Connect to Mautic
 */
echo "\n\n-- Please make sure Basic Auth is enabled on your Mautic install & all appropriate custom fields are created before continuing --\n\n";

$mautic_connected = false;
$init_auth = new ApiAuth();
do{
//    $mautic_baseurl = get_user_input("Please enter the URL of your Mautic install (no trailing slash)");
//    $mautic_username = get_user_input("Please enter your Mautic username");
//    $mautic_password = get_user_input("Please enter your Mautic password");
    $mautic_baseurl = $mautic_config['base_url'];
    $mautic_username = $mautic_config['username'];
    $mautic_password = $mautic_config['password'];

    $mautic_auth = $init_auth->newAuth(
        [
            'userName'      => $mautic_username,
            'password'      => $mautic_password,
        ],
        'BasicAuth'
    );
    $mautic_api = new MauticApi();
    $mautic_user_api = $mautic_api->newApi('users', $mautic_auth, $mautic_baseurl);
    $self = $mautic_user_api->getSelf();
    if(!empty($self['errors'])){
        foreach($self['errors'] as $error){
            echo "Could not connect to Mautic API: {$error['message']}\n";
        }
        $mautic_connected = false;
    }else{
        $mautic_connected = true;
    }
}while(!$mautic_connected);
echo "Successfully connected to Mautic API\n";

$mautic_contact_api = $mautic_api->newApi('contacts', $mautic_auth, $mautic_baseurl);
$mautic_company_api = $mautic_api->newApi('companies', $mautic_auth, $mautic_baseurl);

function get_mautic_contact_by_freemius_id($id){
    global $mautic_contact_api;
    $contacts = $mautic_contact_api->getList(
        "freemius_id:{$id}",
        0,
        1
    )['contacts'];
    if(count($contacts) === 0){
        return false;
    }
    return reset($contacts);
}

function get_mautic_company_by_freemius_id($id){
    global $mautic_company_api;

}

/**
 * Loop through all Freemius plugin users
 */
$offset = 0;
do{
    $query = http_build_query([
        'offset' => $offset,
        'count' => 25,
        'fields' =>  'id,email,first,last,is_marketing_allowed,ip',
    ]);
    $plugin_users = $freemius_api->Api("/plugins/{$plugin_id}/users.json?{$query}", 'GET' );

    foreach($plugin_users->users as $user){
        echo "Handling Freemius User {$user->email}\n";
        $mautic_contact = create_or_update_mautic_contact($user);
        handle_freemius_sites($user->id, $mautic_contact['id']);
    }

    $offset = $offset + 25;
}while(count($plugin_users->users) === 25);


function create_or_update_mautic_contact($user){
    global $mautic_contact_api;
    //$found_contact = get_mautic_contact_by_freemius_id($user->id);

    $data = array(
        'email'             => $user->email,
        'ipAddress'         => $user->ip,
        'firstname'         => $user->first,
        'lastname'          => $user->last,
        'freemius_id'       => $user->id,
        'marketing_allowed' => $user->is_marketing_allowed
    );


//    if($found_contact){
//        $contact = $mautic_contact_api->edit($found_contact['id'], $data, false)['contact'];
//        echo "Updating Mautic contact ID {$found_contact['id']}\n";
//        return $contact;
//    }

    $contact = $mautic_contact_api->create($data)['contact'];
    echo "Creating new Mautic contact\n";
    return $contact;
}


function handle_freemius_sites($freemius_user_id, $mautic_contact_id){
    global $freemius_api;
    global $plugin_id;
    $offset = 0;
    do{
        echo $freemius_user_id;
        $query = http_build_query([
            'offset'    => $offset,
            'user_id'   => $freemius_user_id,
            'count'     => 25,
            'fields'    => 'id,url,title,plan_id,is_active,is_uninstalled',
        ]);
        $installs = $freemius_api->Api("/plugins/{$plugin_id}/installs.json?{$query}", 'GET');

        foreach($installs->installs as $install){
            echo "Handling Freemius site install {$install->url}\n";
            create_or_update_mautic_company($install, $mautic_contact_id);
        }
        $offset = $offset + 25;
    }while(count($installs->installs) === 25);

}

function create_or_update_mautic_company($install, $mautic_contact_id){
    global $mautic_company_api, $database;
    $data = [
        'companyname'           => $install->title,
        'companywebsite'        => $install->url,
        'plan'                  => $install->plan_id,
        'freemius_install_id'   => $install->id,
        'install_state'         => ($install->is_active ? 'activated' : ($install->is_uninstalled ? 'uninstalled' : 'unknown'))
    ];

    $company = $mautic_company_api->create($data)['company'];
    $database->query("INSERT INTO companyids (freemius_id, mautic_id) VALUES ('".(int)$install->id."', '".(int)$company['id']."')");
    $mautic_company_api->addContact($company['id'], $mautic_contact_id);
}
