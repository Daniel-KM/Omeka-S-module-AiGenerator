<?php declare(strict_types=1);

namespace Generate\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

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
                'type' => OmekaElement\ArrayTextarea::class,
                'name' => 'generate_models',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'List of available models (as name = label)', // @translate
                    'info' => 'To analyze an image costs about 300 to 5000 tokens, according to the model, the image size, the length of the prompt and the length of the response. Warning: a multiplicator of 1.6 to 2.6 is used to analyze images.', // @translate
                    'documentation' => 'https://platform.openai.com/docs/pricing',
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'generate_models',
                    'required' => false,
                    'placeholder' => <<<'TXT'
                        gpt-4.1-nano = GPT 4.1 nano ($0.50 / 1M tokens)
                        gpt-4.1-mini = GPT 4.1 mini ($2 / 1M tokens)
                        gpt-4.1 = GPT 4.1 ($10 / 1M tokens)
                        gpt-4o-mini = GPT 4o mini ($0.75 / 1M tokens)
                        gpt-4o = GPT 4o ($12.5 / 1M tokens)
                        gpt-3.5-turbo = GPT 3.5 turbo ($2 / 1M tokens)
                        gpt-4.5-preview = GPT 4.5 preview ($225 / 1M tokens)
                        TXT,
                ],
            ])
            ->add([
                'type' => Element\Text::class,
                'name' => 'generate_model',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Default model name', // @translate
                    'info' => 'Use exact name from above field.', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_model',
                    'required' => false,
                    'placeholder' => 'gpt-4.1-nano',
                ],
            ])
            ->add([
                'type' => Element\Text::class,
                'name' => 'generate_max_tokens',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Max tokens by request', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_max_tokens',
                    'required' => false,
                ],
            ])
            ->add([
                'type' => CommonElement\OptionalRadio::class,
                'name' => 'generate_derivative',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Default derivative image', // @translate
                    'value_options' => [
                        'original' => 'Original', // @translate
                        'large' => 'Large', // @translate
                        'medium' => 'Midsized', // @translate
                        'square' => 'Square', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'generate_derivative',
                    'required' => false,
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
