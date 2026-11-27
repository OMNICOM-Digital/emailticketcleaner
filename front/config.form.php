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

/**
 * Settings page.
 *
 * Served by GLPI's legacy file loader with GLPI already booted (no inc/includes.php: deprecated in 11,
 * logs a deprecation in 12). CSRF on POST is checked by core's CheckCsrfListener on both versions
 * (11: token field; 12: Sec-Fetch-Site / Origin headers) — the form still sends the token for 11.
 *
 * 04 G1/G2: the right is checked here on every request, independently of menu visibility.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Emailticketcleaner\Rule;
use GlpiPlugin\Emailticketcleaner\Settings;

Session::checkRight(Settings::RIGHT, READ);

if (isset($_POST['update'])) {
    Session::checkRight(Settings::RIGHT, UPDATE);
    $rejected = Settings::save($_POST);
    Session::addMessageAfterRedirect(__s('Settings saved', 'emailticketcleaner'));
    if ($rejected !== []) {
        // 04 G11: name the refusal and the object.
        Session::addMessageAfterRedirect(
            htmlescape(sprintf(
                __('These markers are not valid and were not saved: %s', 'emailticketcleaner'),
                implode(', ', $rejected)
            )),
            false,
            WARNING
        );
    }
    Html::back();
}

$settings = Settings::getAll() ?? Settings::getDefaults();

Html::header(Rule::getMenuName(), '', 'config', Rule::class);

TemplateRenderer::getInstance()->display('@emailticketcleaner/config.html.twig', [
    'settings'   => $settings,
    'can_edit'   => Session::haveRight(Settings::RIGHT, UPDATE),
    'rules_url'  => Rule::getSearchURL(),
]);

Html::footer();
