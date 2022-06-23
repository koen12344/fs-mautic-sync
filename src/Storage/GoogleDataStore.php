<?php

namespace FSMauticSync\Storage;

use Google\Cloud\Datastore\DatastoreClient;

class GoogleDataStore implements AdapterInterface
{

    /**
     * @var DatastoreClient
     */
    private $client;

    public function __construct(DatastoreClient $client)
    {
        $this->client = $client;
    }

    public function get_mautic_id_by_freemius_id($freemius_install_id)
    {
        $key = $this->client->key('FSMauticMatch', (int)$freemius_install_id);
        $mautic_id_data = $this->client->lookup($key);
        if(!is_null($mautic_id_data)){
            return (int)$mautic_id_data['mautic_item_id'];
        }
        return null;
    }

    public function store_id_match($freemius_install_id, $mautic_item_id)
    {
        $key = $this->client->key('FSMauticMatch', (int)$freemius_install_id);

        $request = $this->client->entity($key, [
            'mautic_item_id'        => (int)$mautic_item_id,
        ]);
        $this->client->insert($request);

        return true;
    }
}