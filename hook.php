<?php

/**
 * -------------------------------------------------------------------------
 * Email Ticket Cleaner plugin for GLPI
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 OMNICOM s.r.o.
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/OMNICOM-Digital/emailticketcleaner
 * -------------------------------------------------------------------------
 */

use GlpiPlugin\Emailticketcleaner\RuleRepository;
use GlpiPlugin\Emailticketcleaner\Settings;

/**
 * Install and upgrade. Runs only through Setup > Plugins > Install / Update.
 *
 * APIs used exist with the same signature on 11.0/bugfixes and 12.0/bugfixes (PROJECT-REFERENCE §7 G5):
 * Migration::addField(), addKey(), addRight(), executeMigration(); DBmysql::tableExists(), doQuery(string);
 * DBConnection::getDefaultCharset(), getDefaultCollation(), getDefaultPrimaryKeySignOption().
 */
function plugin_emailticketcleaner_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_EMAILTICKETCLEANER_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $key_sign  = DBConnection::getDefaultPrimaryKeySignOption();
    $table     = RuleRepository::TABLE;

    if (!$DB->tableExists($table)) {
        $DB->doQuery(
            "CREATE TABLE `$table` (
                `id` int {$key_sign} NOT NULL AUTO_INCREMENT,
                `name` varchar(255) DEFAULT NULL,
                `comment` text,
                `pattern` text,
                `target` varchar(10) NOT NULL DEFAULT 'body',
                `ranking` int NOT NULL DEFAULT '0',
                `is_active` tinyint NOT NULL DEFAULT '1',
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `name` (`name`),
                KEY `is_active_ranking` (`is_active`, `ranking`),
                KEY `date_mod` (`date_mod`),
                KEY `date_creation` (`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC"
        );
    }

    // Own right, granted to profiles that have core config READ|UPDATE (context/02 Rights).
    $migration->addRight(Settings::RIGHT, ALLSTANDARDRIGHT, ['config' => READ | UPDATE]);

    $migration->executeMigration();

    // Defaults for keys not stored yet; never overwrites an admin's choice.
    Settings::installDefaults();

    return true;
}

function plugin_emailticketcleaner_uninstall(): bool
{
    global $DB;

    $table = RuleRepository::TABLE;
    if ($DB->tableExists($table)) {
        $DB->doQuery("DROP TABLE `$table`");
    }

    // No Migration counterpart for addRight(); remove the profile rights rows directly.
    ProfileRight::deleteProfileRights([Settings::RIGHT]);

    $DB->delete('glpi_displaypreferences', ['itemtype' => 'GlpiPlugin\\Emailticketcleaner\\Rule']);

    Settings::uninstall();

    return true;
}
