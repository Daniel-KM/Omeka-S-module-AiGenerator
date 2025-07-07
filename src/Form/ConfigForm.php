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
                'name' => 'generate_api_key_openai',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'OpenAI api key', // @translate
                    'info' => 'The api key can be created with an account on OpenAI developer platform.', // @translate
                    'documentation' => 'https://platform.openai.com',
                ],
                'attributes' => [
                    'id' => 'generate_api_key_openai',
                ],
            ])
        ;
    }
}
