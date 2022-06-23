<?php

namespace FSMauticSync\Storage;

interface AdapterInterface
{
    /**
     * Get the ID of a Mautic custom item by passing the Freemius Install ID
     *
     * @param $freemius_install_id
     * @return int|null Mautic custom item ID
     */
    public function get_mautic_id_by_freemius_id($freemius_install_id);

    /**
     * Store a matching Freemius and Mautic custom item ID
     *
     * @param $freemius_install_id
     * @param $mautic_item_id
     * @return bool Success
     */
    public function store_id_match($freemius_install_id, $mautic_item_id);
}