<?php

declare(strict_types=1);

namespace AiGenerator\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class BatchEditFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->setName('ai_generator')
            ->setOptions([
                'element_group' => 'ai_generator',
                'label' => 'AI Record Generator', // @translate
            ])
            ->setAttributes([
                'id' => 'ai-generator',
                'class' => 'field-container',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ])

            ->add([
                'name' => 'generate',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Generate', // @translate
                    'info' => 'It is recommended to process this task in background.', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'ai-generator-generate',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'validate',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Validate', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'ai-generator-validate',
                    'class' => 'ai-generator-settings',
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'model',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Model', // @translate
                ],
                'attributes' => [
                    'id' => 'ai-generator-model',
                    'class' => 'ai-generator-settings',
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'max_tokens',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Maximum tokens', // @translate
                ],
                'attributes' => [
                    'id' => 'ai-generator-max-tokens',
                    'min' => 0,
                    'class' => 'ai-generator-settings',
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'derivative',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Derivative image', // @translate
                ],
                'attributes' => [
                    'id' => 'ai-generator-derivative',
                    'class' => 'ai-generator-settings',
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'type' => Element\Textarea::class,
                'name' => 'prompt_system',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Prompt (session)', // @translate
                ],
                'attributes' => [
                    'id' => 'ai-generator-prompt-system',
                    'class' => 'ai-generator-settings',
                    'rows' => 5,
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'type' => Element\Textarea::class,
                'name' => 'prompt_user',
                'options' => [
                    'element_group' => 'ai_generator',
                    'label' => 'Prompt (user)', // @translate
                ],
                'attributes' => [
                    'id' => 'ai-generator-prompt-user',
                    'class' => 'ai-generator-settings',
                    'rows' => 5,
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;
    }
}
