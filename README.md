`fs-mautic-sync` is a PHP command line tool to sync user & site data from Freemius to Mautic.

This is intended to be a one-time-use initial sync script. The sync should be maintained through the [fs-mautic-hooks](https://github.com/koen12344/fs-mautic-hooks) script.

## Features
* Syncs Freemius users & sites
* Uses Mautic oAuth2 authentication
* Adds every site install as a new "company" in Mautic
* Creates the appropriate custom fields in Mautic
* Synced data includes:
  * Plugin, WP and PHP version
  * Plan
  * Install state
  * Uninstall reason

## Requirements
* PHP & Composer installed in PATH
* Mautic with API enabled

## Installation & usage

1. Clone the repository
2. Run `composer install` in the `fs-mautic-sync` directory to get the necessary dependencies
3. Open a terminal in the `fs-mautic-sync` directory and run `php sync.php`
4. The tool will ask for your Freemius dev ID & key which you can find at https://dashboard.freemius.com/#!/profile/
5. The tool will initiate an oAuth flow for Mautic. Create new API credentials in Mautic with the redirect URI set to `http://localhost:8123`

### Important
Only use this tool locally, do not expose it to the WWW. 