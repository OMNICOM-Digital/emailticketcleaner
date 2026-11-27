<?php

/**
 * -------------------------------------------------------------------------
 * Email Ticket Cleaner plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * Copyright (C) 2026 OMNICOM s.r.o.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 OMNICOM s.r.o.
 * @license   GPL-3.0-or-later https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/OMNICOM-Digital/emailticketcleaner
 * -------------------------------------------------------------------------
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Emailticketcleaner\ImportHook;
use GlpiPlugin\Emailticketcleaner\Rule;
use GlpiPlugin\Emailticketcleaner\Settings;

define('PLUGIN_EMAILTICKETCLEANER_VERSION', '1.0.0');
// Declare only what was tested (PROJECT-REFERENCE §7 G0). Max is exclusive (core Plugin::checkGlpiVersion).
// 1.0.0 is released for GLPI 11 only; GLPI 12 is declared once live-tested (decision 2026-11-26).
// The GLPI 12 code paths (Compat\Glpi12) stay in place for that release.
define('PLUGIN_EMAILTICKETCLEANER_MIN_GLPI', '11.0.11');
define('PLUGIN_EMAILTICKETCLEANER_MAX_GLPI', '11.0.99');

/**
 * Plugin init. Runs on every GLPI request: no DB reads here (context/02 setup.php).
 */
function plugin_init_emailticketcleaner(): void
{
    global $PLUGIN_HOOKS;

    Plugin::registerClass(Rule::class);

    // Cleaning of mail-collector imports. Verified on 11.0/bugfixes and 12.0/bugfixes:
    // CommonDBTM::add() calls PRE_ITEM_ADD with the item, before prepareInputForAdd() (PROJECT-REFERENCE §7 M2).
    // Plugin::doHook() matches get_class($item) exactly, so each itemtype is listed.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_ADD]['emailticketcleaner'] = [
        Ticket::class       => [ImportHook::class, 'preItemAdd'],
        ITILFollowup::class => [ImportHook::class, 'preItemAdd'],
    ];

    // Admin UI: settings page (gear icon on Setup > Plugins) and Setup menu entry (rule list).
    if (Session::haveRight(Settings::RIGHT, READ)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['emailticketcleaner'] = 'front/config.form.php';
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['emailticketcleaner']  = ['config' => Rule::class];
    }
}

/**
 * @return array<string, mixed>
 */
function plugin_version_emailticketcleaner(): array
{
    return [
        'name'         => 'Email Ticket Cleaner',
        'version'      => PLUGIN_EMAILTICKETCLEANER_VERSION,
        'author'       => 'OMNICOM',
        'license'      => 'GPL-3.0-or-later',
        'homepage'     => 'https://github.com/OMNICOM-Digital/emailticketcleaner',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_EMAILTICKETCLEANER_MIN_GLPI,
                'max' => PLUGIN_EMAILTICKETCLEANER_MAX_GLPI,
            ],
            'php' => [
                // GLPI 11 requires 8.2, GLPI 12 requires 8.3 (PROJECT-REFERENCE §7 G0).
                'min' => '8.2',
            ],
        ],
    ];
}
