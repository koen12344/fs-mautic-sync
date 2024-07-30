<?php

namespace FSMauticSync\Storage;

interface AdapterInterface
{
    /**
     * Get the ID of a Mautic custom item by passing the Freemius Install ID
     *
     * @param $freemius_id
     * @param $type
     * @return int|null Mautic custom item ID
     */
    public function get_mautic_id_by_freemius_id($freemius_id, $type);

    /**
     * Store a matching Freemius and Mautic custom item ID
     *
     * @param $freemius_id
     * @param $mautic_item_id
     * @param $type
     * @return bool Success
     */
    public function store_id_match($freemius_id, $mautic_item_id, $type);
}