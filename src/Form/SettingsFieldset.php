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

            ->add([
                // This element is a select built with a factory, not a class.
                // Anyway, it cannot be used simply, because it requires a value.
                // 'type' => 'Omeka\Form\Element\RoleSelect',
                'type' => CommonElement\OptionalRoleSelect::class,
                'name' => 'generate_roles',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Roles allowed to generate via AI', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'generate_roles',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select roles…', // @translate
                ],
            ])
            ->add([
                'type' => Element\Textarea::class,
                'name' => 'generate_prompt_system',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Prompt to set context of a session for resource analysis', // @translate
                    'info' => 'Write the prompt in the language the record should be.', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_prompt_system',
                    'required' => false,
                ],
            ])
            ->add([
                'type' => Element\Textarea::class,
                'name' => 'generate_prompt_user',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Prompt to generate resource metadata', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_prompt_user',
                    'required' => false,
                ],
            ])
        ;
    }
}
