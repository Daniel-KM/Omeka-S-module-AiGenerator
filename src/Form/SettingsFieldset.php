<?php

declare(strict_types=1);

namespace AiGenerator\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class SettingsFieldset extends Fieldset
{
    protected $label = 'AI Record Generator'; // @translate

    protected $elementGroups = [
        'ai_generator' => 'AI Record Generator', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'ai-generator')
            ->setOption('element_groups', $this->elementGroups)

            ->add([
                // This element is a select built with a factory, not a class.
                // Anyway, it cannot be used simply, because it requires a value.
                // 'type' => 'Omeka\Form\Element\RoleSelect',
                'type' => CommonElement\OptionalRoleSelect::class,
                'name' => 'aigenerator_roles',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Roles allowed to generate via AI', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'aigenerator_roles',
                    'multiple' => true,
                    'required' => false,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select roles…', // @translate
                ],
            ])

            ->add([
                'type' => Element\Checkbox::class,
                'name' => 'aigenerator_validate',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Validate by default if user has rights', // @translate
                ],
                'attributes' => [
                    'id' => 'aigenerator_validate',
                ],
            ])

            ->add([
                'type' => OmekaElement\ArrayTextarea::class,
                'name' => 'aigenerator_models',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'List of available models (as name = label)', // @translate
                    'info' => 'To analyze an image costs about 300 to 5000 tokens, according to the model, the image size, the length of the prompt and the length of the response. Warning: a multiplicator of 1.6 to 2.6 is used to analyze images.', // @translate
                    'documentation' => 'https://platform.openai.com/docs/pricing',
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'aigenerator_models',
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
                'name' => 'aigenerator_model',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Default model name', // @translate
                    'info' => 'Use exact name from above field.', // @translate
                ],
                'attributes' => [
                    'id' => 'aigenerator_model',
                    'required' => false,
                    'placeholder' => 'gpt-4.1-nano',
                ],
            ])
            ->add([
                'type' => Element\Text::class,
                'name' => 'aigenerator_max_tokens',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Default max tokens by request', // @translate
                ],
                'attributes' => [
                    'id' => 'aigenerator_max_tokens',
                    'required' => false,
                ],
            ])
            ->add([
                'type' => CommonElement\OptionalRadio::class,
                'name' => 'aigenerator_derivative',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Default derivative image', // @translate
                    'value_options' => [
                        'original' => 'Original', // @translate
                        'large' => 'Large', // @translate
                        'medium' => 'Midsized', // @translate
                        'square' => 'Square', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'aigenerator_derivative',
                    'required' => false,
                ],
            ])

            ->add([
                'type' => Element\Textarea::class,
                'name' => 'aigenerator_prompt_system',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Prompt to set context of a session for resource analysis', // @translate
                    'info' => 'Write the prompt in the language the record should be. Keep the prompt short to take care of credits.', // @translate
                ],
                'attributes' => [
                    'id' => 'aigenerator_prompt_system',
                    'required' => false,
                ],
            ])
            ->add([
                'type' => Element\Textarea::class,
                'name' => 'aigenerator_prompt_user',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Prompt to generate ai record', // @translate
                    'info' => 'May be empty when the prompt for context is complete.', // @translate
                ],
                'attributes' => [
                    'id' => 'aigenerator_prompt_user',
                    'required' => false,
                ],
            ])

            ->add([
                'type' => CommonElement\OptionalItemSetSelect::class,
                'name' => 'aigenerator_item_sets_auto',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Item sets for automatic generation', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'aigenerator_item_sets_auto',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select item sets…', // @translate
                ],
            ])

            ->add([
                'type' => Element\Checkbox::class,
                'name' => 'aigenerator_hide_flag_review',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'HIde the flag review/unreviewed', // @translate
                ],
                'attributes' => [
                    'id' => 'aigenerator_hide_flag_review',
                ],
            ])
        ;
    }
}
