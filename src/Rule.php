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

namespace GlpiPlugin\Emailticketcleaner;

use Dropdown;
use Glpi\Search\DefaultSearchRequestInterface;
use GlpiPlugin\Emailticketcleaner\Compat\RuleBase;
use Session;

/**
 * Admin-defined regular-expression rule, managed with GLPI's standard dropdown list and form.
 *
 * No front/ files: GLPI routes /plugins/emailticketcleaner/front/rule(.form).php to
 * GenericListController / DropdownFormController for CommonDropdown classes
 * (11.0/bugfixes and 12.0/bugfixes src/Glpi/Kernel/Listener/RequestListener/LegacyItemtypeRouteListener.php
 * ::findPluginClass(), identical). Those controllers check canView()/check() on this class.
 *
 * $rightname and $can_be_translated are declared in Compat\RuleBase (GLPI 11/12 typed-property split, §7 G1).
 * Overridden method signatures are identical on 11 and 12 (PROJECT-REFERENCE §7 G2).
 *
 * Table glpi_plugin_emailticketcleaner_rules: id, name, comment, pattern, target, ranking, is_active,
 * date_mod, date_creation. Not entity-scoped (per-entity settings are phase 2).
 */
class Rule extends RuleBase implements DefaultSearchRequestInterface
{
    public const TARGET_BODY  = Pattern::TARGET_BODY;
    public const TARGET_TITLE = Pattern::TARGET_TITLE;

    public static function getTypeName($nb = 0)
    {
        return _n('Cleaning rule', 'Cleaning rules', $nb, 'emailticketcleaner');
    }

    public static function getMenuName()
    {
        return __('Email Ticket Cleaner', 'emailticketcleaner');
    }

    public static function getIcon()
    {
        return 'ti ti-mail-cog';
    }

    /**
     * Menu position of the rule list and form: Setup > Email Ticket Cleaner (our menu_toadd entry).
     *
     * CommonDropdown::getSectorizedDetails() returns ['config', CommonDropdown::class, static::class], which places
     * every dropdown under Setup > Dropdowns (breadcrumb, "Dropdown definition" link). The generic list/form
     * controllers build Html::header() from CommonGLPI::getHeaderParameters() → getSectorizedDetails().
     * Same signature and behaviour on 11.0/bugfixes and 12.0/bugfixes (src/CommonDropdown.php, src/CommonGLPI.php).
     */
    public static function getSectorizedDetails(): array
    {
        return ['config', self::class];
    }

    /**
     * Extra menu link to the settings page (CommonGLPI::getMenuContent() reads it on 11 and 12).
     *
     * @return array<string, string>
     */
    public static function getAdditionalMenuLinks()
    {
        return [
            '<i class="ti ti-adjustments" aria-hidden="true"></i><span class="visually-hidden">'
                . __s('Settings', 'emailticketcleaner') . '</span>'
                => '/plugins/emailticketcleaner/front/config.form.php',
        ];
    }

    public static function getDefaultSearchRequest(): array
    {
        return [
            'sort'  => [self::getRankingSearchOptionId()],
            'order' => ['ASC'],
        ];
    }

    private static function getRankingSearchOptionId(): int
    {
        return 3101;
    }

    public function getAdditionalFields()
    {
        return [
            [
                'name'  => 'pattern',
                'label' => __('Regular expression', 'emailticketcleaner'),
                'type'  => 'textarea',
                'list'  => true,
            ],
            [
                'name'  => 'target',
                'label' => __('Applies to', 'emailticketcleaner'),
                'type'  => 'target',
                'list'  => true,
            ],
            [
                'name'  => 'ranking',
                'label' => __('Order', 'emailticketcleaner'),
                'type'  => 'integer',
                'min'   => 0,
                'max'   => 10000,
                'list'  => true,
            ],
            [
                'name'  => 'is_active',
                'label' => __('Active'),
                'type'  => 'bool',
                'list'  => true,
            ],
        ];
    }

    public function displaySpecificTypeField($ID, $field = [], array $options = [])
    {
        if (($field['type'] ?? '') === 'target') {
            Dropdown::showFromArray('target', self::getTargets(), [
                'value' => $this->fields['target'] ?? self::TARGET_BODY,
            ]);
        }
    }

    /** @return array<string, string> */
    public static function getTargets(): array
    {
        return [
            self::TARGET_BODY  => __('Message text', 'emailticketcleaner'),
            self::TARGET_TITLE => __('Ticket title', 'emailticketcleaner'),
        ];
    }

    public function rawSearchOptions()
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => '3100',
            'table'         => static::getTable(),
            'field'         => 'pattern',
            'name'          => __('Regular expression', 'emailticketcleaner'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];
        $tab[] = [
            'id'            => (string) self::getRankingSearchOptionId(),
            'table'         => static::getTable(),
            'field'         => 'ranking',
            'name'          => __('Order', 'emailticketcleaner'),
            'datatype'      => 'integer',
        ];
        $tab[] = [
            'id'            => '3102',
            'table'         => static::getTable(),
            'field'         => 'target',
            'name'          => __('Applies to', 'emailticketcleaner'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
        ];
        $tab[] = [
            'id'            => '3103',
            'table'         => static::getTable(),
            'field'         => 'is_active',
            'name'          => __('Active'),
            'datatype'      => 'bool',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if ($field === 'target' && is_array($values)) {
            return htmlescape(self::getTargets()[$values['target'] ?? ''] ?? '');
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if ($field === 'target') {
            $options['display'] = false;
            $options['value']   = is_array($values) ? ($values['target'] ?? '') : $values;
            return Dropdown::showFromArray($name, self::getTargets(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    /** Defaults for the "new rule" form; CommonDBTM::getEmpty() sets every field to '' (11 and 12). */
    public function post_getEmpty() // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- core method name
    {
        $this->fields['is_active'] = 1;
        $this->fields['target']    = self::TARGET_BODY;
        $this->fields['ranking']   = 0;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->validateInput($input, true);
        return $input === false ? false : parent::prepareInputForAdd($input);
    }

    public function prepareInputForUpdate($input)
    {
        $input = $this->validateInput($input, false);
        return $input === false ? false : parent::prepareInputForUpdate($input);
    }

    /**
     * 04 P2: refuse an invalid pattern on save, with GLPI's error message naming the rule.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function validateInput(array $input, bool $is_add): array|false
    {
        if (isset($input['target']) && !array_key_exists($input['target'], self::getTargets())) {
            $input['target'] = self::TARGET_BODY;
        }
        if (isset($input['ranking'])) {
            $input['ranking'] = max(0, min(10000, (int) $input['ranking']));
        }
        if ($is_add || array_key_exists('pattern', $input)) {
            $pattern = $input['pattern'] ?? '';
            $error   = is_string($pattern) ? self::getPatternError($pattern) : __('Invalid value');
            if ($error !== null) {
                $name = is_string($input['name'] ?? null) ? $input['name'] : ($this->fields['name'] ?? '');
                Session::addMessageAfterRedirect(
                    htmlescape(sprintf(
                        __('Invalid regular expression in rule "%1$s": %2$s', 'emailticketcleaner'),
                        $name,
                        $error
                    )),
                    false,
                    ERROR
                );
                return false;
            }
        }
        return $input;
    }

    /** Translated reason when the pattern body is not usable, null when it compiles (04 P2). */
    public static function getPatternError(string $body): ?string
    {
        $problem = Pattern::getProblem($body);
        if ($problem === null) {
            return null;
        }
        return match ($problem[0]) {
            'empty'         => __('the expression is empty', 'emailticketcleaner'),
            'too-long'      => sprintf(__('longer than %d characters', 'emailticketcleaner'), Pattern::MAX_LENGTH),
            'invalid-chars' => __('contains invalid characters', 'emailticketcleaner'),
            default         => $problem[1],
        };
    }
}
