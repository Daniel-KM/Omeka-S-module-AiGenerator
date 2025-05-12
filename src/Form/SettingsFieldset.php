<?php declare(strict_types=1);

namespace Generate\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class SettingsFieldset extends Fieldset
{
    protected $label = 'Generate Resource Metadata'; // @translate

    protected $elementGroups = [
        'generate' => 'Generate Resource Metadata', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'generate')
            ->setOption('element_groups', $this->elementGroups)
        ;
    }
}
