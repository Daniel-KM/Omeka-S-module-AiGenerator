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
                'name' => 'generate_chatgpt_api_key',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'ChatGPT api key', // @translate
                ],
            ])
        ;
    }
}
