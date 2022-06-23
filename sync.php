<?php

use Mautic\Auth\ApiAuth;
use Mautic\MauticApi;

use Symfony\Component\Process\Process;

set_time_limit(0);

if(php_sapi_name()!=='cli'){
    exit("This file can only be ran from the command line.\n");
}

require_once 'vendor/autoload.php';
require_once('shared.php');

require_once('vendor/freemius/php-sdk/freemius/Freemius.php'); //Autoload doesn't work on the Freemius API?



$handle = fopen ('php://stdin','r');

$config = [];

function get_user_input($text){
    global $handle;
    echo "{$text}: ";
    $line = fgets($handle);
    return trim($line);
}

function get_user_confirmation($text){
    return strpos(strtolower(get_user_input("{$text} (y / n)")), 'y') !== false;
}

/**
 * Config loading and saving
 */
if(file_exists('config.json') && get_user_confirmation('Reload previous config?')){
    $config = load_settings();
}

/**
 * Connect to Google Datastore
 */
$config['datastore']['project_id'] = !empty($config['datastore']['project_id']) ? $config['datastore']['project_id'] : get_user_input('Please enter your Google Cloud Project ID');
$config['datastore']['keyfile_path'] = !empty($config['datastore']['keyfile_path']) ? $config['datastore']['keyfile_path'] : get_user_input('Copy the credential JSON file into the project folder and enter the filename');
$datastoreclient = new \Google\Cloud\Datastore\DatastoreClient([
    'projectId'     => $config['datastore']['project_id'],
    'keyFilePath'   => $config['datastore']['keyfile_path'],
]);

$googledatastore = new \FSMauticSync\Storage\GoogleDataStore($datastoreclient);

/**
 * Connect to Freemius
 */
$freemius_plugins = false;
do{
    $config['freemius']['dev_id'] = !empty($config['freemius']['dev_id']) ? $config['freemius']['dev_id'] : (int)get_user_input("Please enter your Freemius Dev ID (1234)");
    $config['freemius']['public_key'] = !empty($config['freemius']['public_key']) ? $config['freemius']['public_key'] : get_user_input("Please enter your Freemius Public key");
    $config['freemius']['secret_key'] = !empty($config['freemius']['secret_key']) ? $config['freemius']['secret_key'] : get_user_input("Please enter your Freemius Secret key");

    $freemius_api = new Freemius_Api('developer', $config['freemius']['dev_id'], $config['freemius']['public_key'], $config['freemius']['secret_key']);

    if(!$freemius_api->Test()){
        exit("No connectivity with the Freemius API\n");
    }

    $freemius_plugins = $freemius_api->Api("/plugins.json");
    if(isset($freemius_plugins->error)){
        echo "Could not connect to Freemius API: {$freemius_plugins->error->message}\n";
        $freemius_plugins = false;
        $config['freemius'] = null;
    }

}while(!$freemius_plugins);
echo "Successfully connected to Freemius\n";
save_settings($config);

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
    $plugin_id = $config['freemius']['plugin_id'] = !empty($config['freemius']['plugin_id']) ? $config['freemius']['plugin_id'] : (int)get_user_input("Plugin to sync contacts for:\n{$options}");
    if(!in_array($plugin_id, $valid_choices)){
        $plugin_id = false;
        echo "Invalid plugin ID\n";
    }
}while(!$plugin_id);
echo "Syncing contacts for plugin {$plugin_id}\n";
save_settings($config);

/**
 * Connect to Mautic
 */
echo "\n\n-- Please make sure API access is enabled on your Mautic install --\n\n";
echo "-- Set the Redirect URL to http://localhost:8123 in your Mautic API credentials --\n";
$mautic_connected = false;

do{
    $mautic_existing = isset($config['mautic']) ? $config['mautic'] : [];
    $config['mautic'] = array_merge($mautic_existing, [
        'baseUrl'          => !empty($config['mautic']['baseUrl']) ? $config['mautic']['baseUrl'] : get_user_input("Please enter the URL of your Mautic install (no trailing slash)"),
        'version'          => 'OAuth2', // Version of the OAuth can be OAuth2 or OAuth1a. OAuth2 is the default value.
        'clientKey'        => !empty($config['mautic']['clientKey']) ? $config['mautic']['clientKey'] : get_user_input("Please enter your Mautic client ID"),       // Client/Consumer key from Mautic
        'clientSecret'     => !empty($config['mautic']['clientSecret']) ? $config['mautic']['clientSecret'] : get_user_input("Please enter your Mautic client secret"),       // Client/Consumer secret key from Mautic
        'callback'         => 'http://localhost:8123',       // Redirect URI/Callback URI for this script
    ]);

    $initAuth = new ApiAuth();
    $auth     = $initAuth->newAuth($config['mautic']);

    $requires_auth = false;

    try{
        $auth->validateAccessToken(false);

    }catch(\Mautic\Exception\AuthorizationRequiredException $e){
        $url = $e->getAuthUrl();
        echo "Please authorize the Mautic API: {$url}\n";
        $requires_auth = true;

        //Start the PHP http server to handle the incoming authentication
        $process = new Process(['php', '-S', 'localhost:8123', 'callback.php']);
        $process->start();
    }
    echo "Waiting for authorization...\n";

    //Mautic API uses sessions to access the state key, mimic it
    if(isset($_SESSION['oauth']['state'])){
        $config['mautic']['state'] = $_SESSION['oauth']['state'];
    }
    save_settings($config);

    while($requires_auth){
        $config = load_settings();
        if(!empty($config['mautic']['accessToken'])){
            $auth = $initAuth->newAuth($config['mautic']);
            $auth->validateAccessToken();
            $process->stop();
            $requires_auth = false;
            break;
        }
        sleep(1);
    }

    if ($auth->accessTokenUpdated()) {
        $new_token = $auth->getAccessTokenData();
        $normalize = [
            'accessToken'           => $new_token['access_token'],
            'accessTokenExpires'    => $new_token['expires'],
            'refreshToken'          => $new_token['refresh_token'],
        ];
        $config['mautic'] = array_merge($config['mautic'], $normalize);
    }

    $mautic_api = new MauticApi();
    $mautic_user_api = $mautic_api->newApi('users', $auth, $config['mautic']['baseUrl']);
    $self = $mautic_user_api->getSelf();
    if(!empty($self['errors'])){
        foreach($self['errors'] as $error){
            echo "Could not connect to Mautic API: {$error['message']}\n";
        }
        $config['mautic'] = null;
        $mautic_connected = false;
    }else{
        $mautic_connected = true;
    }
}while(!$mautic_connected);
echo "Successfully connected to Mautic API\n";
save_settings($config);


if(get_user_confirmation('Create/sync plugin related custom fields in Mautic?')){
    $mautic_contact_fields_api = $mautic_api->newApi('contactFields', $auth, $config['mautic']['baseUrl']);

    $contact_fields = [
        [
            'label'                 => 'Freemius ID',
            'alias'                 => 'freemius_id',
            'type'                  => 'number',
            'isPubliclyUpdatable'   => false,
            'isUniqueIdentifier'    => true,
            'isVisible'             => false,
            'isShortVisible'        => false,
        ],
        [
            'label'                 => 'Beta tester',
            'alias'                 => 'beta_tester',
            'type'                  => 'boolean',
            'isPubliclyUpdatable'   => false,
            'isUniqueIdentifier'    => false,
            'isVisible'             => false,
            'isShortVisible'        => false,
            'defaultValue'          => 0,
            'properties'            => [
                'no'        => 'No',
                'yes'       => 'Yes',
            ]
        ],
        [
            'label'                 => 'Affiliate',
            'alias'                 => 'affiliate',
            'type'                  => 'boolean',
            'isPubliclyUpdatable'   => false,
            'isUniqueIdentifier'    => false,
            'isVisible'             => false,
            'isShortVisible'        => false,
            'defaultValue'          => 0,
            'properties'            => [
                'no'        => 'No',
                'yes'       => 'Yes',
            ]
        ]
    ];

    print_r($mautic_contact_fields_api->create($contact_fields)); //Doesn't work anymore for some reason
    echo "Created contact fields\n";


//
//    $plans = $freemius_api->Api("/plugins/{$config['freemius']['plugin_id']}/plans.json");
//    $plans_field_values = [
//        [
//            'label' => 'Unknown',
//            'value' => 'unknown'
//        ]
//    ];
//    foreach($plans->plans as $plan){
//        $plans_field_values[] = [
//            'label'             => $plan->title,
//            'value'             => $plan->id,
//        ];
//    }
//        //print_r($plans);
//    $company_fields = [
//        [
//            'label'                 => 'Freemius Install ID',
//            'alias'                 => 'freemius_install_id',
//            'type'                  => 'number',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => true,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//        ],
//        [
//            'label'                 => 'Freemius User ID',
//            'alias'                 => 'freemius_user_id',
//            'type'                  => 'number',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//        ],
//        [
//            'label'                 => 'Uninstall reason',
//            'alias'                 => 'uninstall_reason',
//            'type'                  => 'select',
//            'isPubliclyUpdatable'   => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//            'properties'            => [
//                    'list' => [
//                        [
//                        'label'             => 'No longer needed',
//                        'value'             => 1,
//                    ],
//                    [
//                        'label'             => 'Found a better plugin',
//                        'value'             => 2,
//                    ],
//                    [
//                        'label'             => 'Only needed for a short period',
//                        'value'             => 3,
//                    ],
//                    [
//                        'label'             => 'Broke my site',
//                        'value'             => 4,
//                    ],
//                    [
//                        'label'             => 'Suddenly stopped working',
//                        'value'             => 5,
//                    ],
//                    [
//                        'label'             => 'Can\'t pay anymore',
//                        'value'             => 6,
//                    ],
//                    [
//                        'label'             => 'Other',
//                        'value'             => 7,
//                    ],
//                    [
//                        'label'             => 'Didn\'t work',
//                        'value'             => 8,
//                    ],
//                    [
//                        'label'             => 'Doesn\'t like to share information',
//                        'value'             => 9,
//                    ],
//                    [
//                        'label'             => 'Couldn\'t make it work',
//                        'value'             => 10,
//                    ],
//                    [
//                        'label'             => 'Missing specific feature',
//                        'value'             => 11,
//                    ],
//                    [
//                        'label'             => 'Not working',
//                        'value'             => 12,
//                    ],
//                    [
//                        'label'             => 'Not what I was looking for',
//                        'value'             => 13,
//                    ],
//                    [
//                        'label'             => 'Didn\'t work as expected',
//                        'value'             => 14,
//                    ],
//                    [
//                        'label'             => 'Temporary deactivation',
//                        'value'             => 15,
//                    ],
//                        ]
//            ]
//        ],
//        [
//            'label'                 => 'Uninstall reason info',
//            'alias'                 => 'uninstall_reason_info',
//            'type'                  => 'textarea',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//        ],
//        [
//            'label'                 => 'Install state',
//            'alias'                 => 'install_state',
//            'type'                  => 'select',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//            'properties'            => [
//                'list'  => [[
//                        'label'             => 'Activated',
//                        'value'             => 'activated',
//                    ],
//                    [
//                        'label'             => 'Deactivated',
//                        'value'             => 'deactivated',
//                    ],
//                    [
//                        'label'             => 'Uninstalled',
//                        'value'             => 'uninstalled',
//                    ],
//                    [
//                        'label'             => 'Unknown',
//                        'value'             => 'unknown',
//                    ],
//                    ]
//            ],
//            'defaultValue'          => 'unknown'
//        ],
//        [
//            'label'                 => 'Plan',
//            'alias'                 => 'plan',
//            'type'                  => 'select',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//            'properties'            => ['list' => $plans_field_values],
//            'defaultValue'          => 'unknown'
//        ],
//        [
//            'label'                 => 'Trial plan',
//            'alias'                 => 'trial_plan',
//            'type'                  => 'select',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//            'properties'            => ['list' => $plans_field_values],
//            'defaultValue'          => 'unknown'
//        ],
//        [
//            'label'                 => 'Plugin version',
//            'alias'                 => 'plugin_version',
//            'type'                  => 'text',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//        ],
//        [
//            'label'                 => 'WordPress version',
//            'alias'                 => 'wordpress_version',
//            'type'                  => 'text',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//        ],
//        [
//            'label'                 => 'PHP version',
//            'alias'                 => 'php_version',
//            'type'                  => 'text',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//        ],
//        [
//            'label'                 => 'In trial',
//            'alias'                 => 'in_trial',
//            'type'                  => 'boolean',
//            'isPubliclyUpdatable'   => false,
//            'isUniqueIdentifier'    => false,
//            'isVisible'             => false,
//            'isShortVisible'        => false,
//            'defaultValue'          => 0,
//            'properties'            => [
//                'no'    => 'No',
//                'yes'    => 'Yes',
//            ]
//        ],
//    ];
//
//    $mautic_company_fields_api->createBatch($company_fields);
//    echo "Created company fields\n";
}


$mautic_contact_api = $mautic_api->newApi('contacts', $auth, $config['mautic']['baseUrl']);
$mautic_company_api = $mautic_api->newApi('companies', $auth, $config['mautic']['baseUrl']);

if(get_user_confirmation('Delete all existing contacts & companies in Mautic? (Choose wisely!!)')) {
    $offset = 0;
    $total = null;
    do{
        $contacts = $mautic_contact_api->getList(null, 0, 50);
        $total = is_null($total) ? $contacts['total'] : $total;
        $ids = array_keys($contacts['contacts']);
        echo "Deleting contacts {$offset}/{$total}\n";
        $mautic_contact_api->deleteBatch($ids);
        $offset = $offset + 50;
    }while(count($contacts['contacts']) >= 50);

    $offset = 0;
    $total = null;
    do{
        $companies = $mautic_company_api->getList(null, 0, 50);
        $total = is_null($total) ? $companies['total'] : $total;
        $ids = array_keys($companies['companies']);
        echo "Deleting companies {$offset}/{$total}\n";
        $mautic_company_api->deleteBatch($ids);
        $offset = $offset + 50;
    }while(count($companies['companies']) >= 50);

}

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
    $companies = $mautic_company_api->getList(
        "freemius_install_id:{$id}",
        0,
        1
    )['companies'];
    if(count($companies) === 0){
        return false;
    }
    return reset($companies);
}

if(get_user_confirmation('Sync users from Freemius to Mautic?')) {
    /**
     * Loop through all Freemius plugin users
     */
    $offset = 0;
    if(!empty($config['freemius']['contact_offset'])){
        $saved_offset = (int)$config['freemius']['contact_offset'];
        if(get_user_confirmation("Continue syncing users from previous offset? ({$saved_offset})")){
            $offset = $saved_offset;
        }
    }

    if(!isset($config['freemius']['created_contacts'])){
        $config['freemius']['created_contacts'] = [];
    }

    do{
        $query = http_build_query([
            'offset' => $offset,
            'count' => 50,
            'fields' =>  'id,email,first,last,is_marketing_allowed,ip,created',
        ]);
        $plugin_users = $freemius_api->Api("/plugins/{$plugin_id}/users.json?{$query}", 'GET' );

        $created_contacts = batch_create_mautic_users($plugin_users->users);

        echo sprintf("Added %d contacts\n", count($created_contacts));

        foreach($created_contacts as $created_contact){
            $freemius_id = $created_contact['fields']['all']['freemius_id'];
            $config['freemius']['created_contacts'][$freemius_id] = $created_contact['id'];
        }

        $offset = $offset + 50;
        $config['freemius']['contact_offset'] = $offset;
        save_settings($config);
    }while(count($plugin_users->users) === 50);

}

/*
 * [customObjects] => Array
                (
                    [data] => Array
                        (
                            [0] => Array
                                (
                                    [id] => 1
                                    [alias] => installs
                                    [data] => Array
                                        (
                                            [0] => Array
                                                (
                                                    [id] => 256
                                                    [name] => site name
                                                    [language] =>
                                                    [category] =>
                                                    [isPublished] => 1
                                                    [dateAdded] => 2022-06-13T13:38:01+00:00
                                                    [dateModified] => 2022-06-13T13:38:01+00:00
                                                    [createdBy] => 1
                                                    [modifiedBy] => 1
                                                    [attributes] => Array
                                                        (
                                                            [plugin-version] => 2.3.5
                                                            [site-url] => http://exampleapi-site.com
                                                        )

                                                )

                                        )

                                    [meta] => Array
                                        (
                                            [sort] => -dateAdded
                                            [page] => Array
                                                (
                                                    [number] => 1
                                                    [size] => 10
                                                )

                                        )

                                )

                        )

                    [meta] => Array
                        (
                            [sort] => -dateAdded
                            [page] => Array
                                (
                                    [number] => 1
                                    [size] => 10
                                )

                        )

                )

        )

 */

function batch_create_mautic_users($users){
    global $mautic_contact_api;

    $contacts = [];
    foreach($users as $user){
        $contact = [
            'email'             => $user->email,
            'ipAddress'         => $user->ip,
            'firstname'         => $user->first,
            'lastname'          => $user->last,
            'freemius_id'       => $user->id,
        ];
        if(!$user->is_marketing_allowed){
            $contact['doNotContact'] = [[
                'reason'    => 3,
                'channel'   => 'email',
            ]];
        }
        $contacts[] = $contact;
    }
    return $mautic_contact_api->createBatch($contacts)['contacts'];
}

if(!get_user_confirmation('Sync sites/installs from Freemius to Mautic?')) {
 exit;
}

/**
 * Loop through all Freemius installs
 */
$offset = 0;
if(!empty($config['freemius']['install_offset'])){
    $saved_offset = (int)$config['freemius']['install_offset'];
    if(get_user_confirmation("Continue syncing installs from previous offset? ({$saved_offset})")){
        $offset = $saved_offset;
    }
}
do{
    $query = http_build_query([
        'offset' => $offset,
        'count' => 50,
        'fields' =>  'id,user_id,url,title,plan_id,is_active,is_uninstalled,version,programming_language_version,platform_version',
    ]);
    $installs = $freemius_api->Api("/plugins/{$plugin_id}/installs.json?{$query}", 'GET');
    if(!isset($installs->installs) || !$installs->installs){
        echo "Could not fetch installs from API:\n";
        print_r($installs);
        exit;
    }

    batch_create_mautic_items($installs->installs);
    echo sprintf("Added  companies\n");

    $offset = $offset + 50;
    $config['freemius']['install_offset'] = $offset;
    save_settings($config);
}while(count($installs->installs) === 50);


function batch_create_mautic_items($installs){
    global $config, $mautic_contact_api, $googledatastore;

    foreach($installs as $install){
        if(!isset($config['freemius']['created_contacts'][$install->user_id])){ continue; }
        $mautic_id = $config['freemius']['created_contacts'][$install->user_id];

        $id_exists = $googledatastore->get_mautic_id_by_freemius_id($install->id);

        $contact = [
            'includeCustomObjects' => true,
            'customObjects'     => [
                'data'      => [
                    [
                        'alias' => 'installs',
                        'data'  => [
                            [
                                'id' => $id_exists ?: null,
                                'name'  => $install->title,
                                'attributes' => [
                                    'pluginversion'        => $install->version,
                                    'siteurl'              => $install->url,
                                    'plan'                  => $install->plan_id,
                                    'freemiusinstallid'   => $install->id,
                                    'installstate'         => ($install->is_active ? 'activated' : ($install->is_uninstalled ? 'uninstalled' : 'unknown')),
                                    'wordpressversion'     => $install->platform_version,
                                    'phpversion'           => $install->programming_language_version,
                                    'freemiususerid'      => $install->user_id,
                                ]
                            ]
                        ]
                    ],
                ]
            ]
        ];

        $response = $mautic_contact_api->edit($mautic_id, $contact, false);

        if(!isset($response['contact']['customObjects']['data']) || empty($response['contact']['customObjects']['data'])){
            echo sprintf("Could not find/create custom objects for contact - Freemius user ID %d, - Install ID %d\n", $install->user_id, $install->id);
            continue;
        }

        $all_objects = $response['contact']['customObjects']['data'];

        $custom_object_id = array_search('installs', array_column($all_objects, 'alias'));
        if($custom_object_id === false){
            echo "Installs custom object not found for contact\n";
            continue;
        }

        if(!isset($all_objects[$custom_object_id]['data']) || empty($all_objects[$custom_object_id]['data'])){
            echo "No installs found for contact\n";
            continue;
        }

        $all_items = $all_objects[$custom_object_id]['data'];

        foreach($all_items as $item){
            if($id_exists || (int)$item['attributes']['freemiusinstallid'] != (int)$install->id){ continue; }
            try{
                $googledatastore->store_id_match($install->id, $item['id']);
            }catch(\Google\Cloud\Core\Exception\ConflictException $exception){
                echo (int)$item['attributes']['freemiusinstallid']."\n";
                echo (int)$install->id."\n";

                echo "ID already stored in DB\n";
                die();
            }

        }

        echo sprintf("Added/updated site/install %s\n", $install->url);

    }
}

function batch_create_mautic_companies($installs){
    global $mautic_company_api, $config;
    $companies = [];
    foreach($installs as $install){
        $companies[] = [
            'companyname'           => $install->title,
            'companywebsite'        => $install->url,
            'plan'                  => $install->plan_id,
            'freemius_install_id'   => $install->id,
            'install_state'         => ($install->is_active ? 'activated' : ($install->is_uninstalled ? 'uninstalled' : 'unknown')),
            'plugin_version'        => $install->version,
            'wordpress_version'     => $install->platform_version,
            'php_version'           => $install->programming_language_version,
            'freemius_user_id'      => $install->user_id,
        ];

    }
    $created_companies = $mautic_company_api->createBatch($companies)['companies'];

    foreach($created_companies as $created_company){

        $freemius_user_id = $created_company['fields']['all']['freemius_user_id'];
        if(isset($config['freemius']['created_contacts'][$freemius_user_id])){
            $contact_id_by_user_id = $config['freemius']['created_contacts'][$freemius_user_id];
            $mautic_company_api->addContact($created_company['id'], $contact_id_by_user_id);
            echo "Adding contact to company {$created_company['fields']['all']['companyname']}\n";
        }
    }
    return $created_companies;
}