<?php declare(strict_types=1);

namespace Generate\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'generate_openai_api_key',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'OpenAI api key', // @translate
                    'info' => 'The api key can be created with an account on OpenAI developer platform.', // @translate
                    'documentation' => 'https://platform.openai.com',
                ],
                'attributes' => [
                    'id' => 'generate_openai_api_key',
                    'placeholder' => 'sk-proj-xxx',
                ],
            ])
            ->add([
                'name' => 'generate_openai_organization',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'OpenAI organization id', // @translate
                    'info' => 'The optional organization id allows to follow members in the OpenAI dashboard.', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_openai_organization',
                    'placeholder' => 'org-yyy',
                ],
            ])
            ->add([
                'name' => 'generate_openai_project',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'OpenAI project id', // @translate
                    'info' => 'The optional project id allows to follow various projects in the OpenAI dashboard.', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_openai_project',
                    'placeholder' => 'proj-zzz',
                ],
            ])
        ;
    }
}
