<?php

/**
 * @package     Cybersalt.Plugin.System.SgCache
 * @copyright   Copyright (C) 2026 Cybersalt. All rights reserved.
 * @license     GNU General Public License version 2 or later
 */

namespace Cybersalt\Plugin\System\SgCache\Field;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Field\ListField;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

class ComponentselectField extends ListField
{
    protected $type = 'Componentselect';

    public function setup(\SimpleXMLElement $element, $value, $group = null)
    {
        $result = parent::setup($element, $value, $group);

        // The manifest default (and a fresh install's stored value) is one comma-separated
        // string. Split it so the multi-select shows those components as selected.
        if ($this->multiple && \is_string($this->value)) {
            $this->value = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $this->value))));
        }

        return $result;
    }

    protected function getOptions(): array
    {
        $options = parent::getOptions();

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $db->getQuery(true)
                ->select([
                    $db->quoteName('element'),
                    $db->quoteName('name'),
                ])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->order($db->quoteName('name') . ' ASC');

            $db->setQuery($query);
            $components = $db->loadObjectList();

            foreach ($components as $component) {
                // Try to translate the component name
                $label = Text::_($component->name);

                // If translation returns the key, use the element name nicely formatted
                if ($label === $component->name && str_starts_with($component->element, 'com_')) {
                    $label = ucfirst(substr($component->element, 4));
                }

                $options[] = (object) [
                    'value' => $component->element,
                    'text'  => $label . ' (' . $component->element . ')',
                ];
            }
        } catch (\Exception $e) {
            // Silently fail — the field will just have no options
        }

        return $options;
    }
}
