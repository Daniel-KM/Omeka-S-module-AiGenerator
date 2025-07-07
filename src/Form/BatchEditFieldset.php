<?php declare(strict_types=1);

namespace Generate\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class BatchEditFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->setName('generate')
            ->setOptions([
                'element_group' => 'generate',
                'label' => 'Generate metadata', // @translate
            ])
            ->setAttributes([
                'id' => 'generate',
                'class' => 'field-container',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ])

            ->add([
                'name' => 'generate_metadata',
                'type' => Element\Checkbox::class,
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Generate metadata', // @translate
                    'info' => 'It is recommended to process this task in background.', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'generate-metadata',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'generate_model',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Model', // @translate
                ],
                'attributes' => [
                    'id' => 'generate-model',
                    'class' => 'generate-settings',
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'generate_max_tokens',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Max tokens', // @translate
                ],
                'attributes' => [
                    'id' => 'generate-max-tokens',
                    'min' => 0,
                    'class' => 'generate-settings',
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'type' => Element\Textarea::class,
                'name' => 'generate_prompt_system',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Prompt (session)', // @translate
                ],
                'attributes' => [
                    'id' => 'generate-prompt-system',
                    'class' => 'generate-settings',
                    'rows' => 5,
                    // Enabled via js when checkbox is on.
                    'disabled' => 'disabled',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'type' => Element\Textarea::class,
                'name' => 'generate_prompt_user',
                'options' => [
                    'element_group' => 'generate',
                    'label' => 'Prompt (user)', // @translate
                ],
                'attributes' => [
                    'id' => 'generate-prompt-user',
                    'class' => 'generate-settings',
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
