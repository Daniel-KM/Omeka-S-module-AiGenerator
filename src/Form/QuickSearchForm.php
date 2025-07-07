<?php declare(strict_types=1);

namespace AiGenerator\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\View\Helper\Url;
use Omeka\Form\Element as OmekaElement;

class QuickSearchForm extends Form
{
    /**
     * @var array
     */
    protected $resourceTemplates = [];

    /**
     * @var Url
     */
    protected $urlHelper;

    public function init(): void
    {
        $this->setAttribute('method', 'get');

        // No csrf: see main search form.
        $this->remove('csrf');

        // $urlHelper = $this->getUrlHelper();

        $this
            ->add([
                'name' => 'resource_template_id',
                'type' => OmekaElement\ResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Template', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'resource_template_id',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a template…', // @translate
                ],
            ])

            ->add([
                'name' => 'fulltext_search',
                'type' => Element\Search::class,
                'options' => [
                    'label' => 'Text', // @translate
                ],
                'attributes' => [
                    'id' => 'fulltext_search',
                ],
            ])

            ->add([
                'name' => 'created',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Date', // @translate
                ],
                'attributes' => [
                    'id' => 'created',
                    'placeholder' => 'Set a date with optional comparator…', // @translate
                ],
            ])

            ->add([
                'name' => 'reviewed',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Reviewed', // @translate
                    'value_options' => [
                        '' => 'Any', // @translate
                        '1' => 'Yes', // @translate
                        '00' => 'No', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'reviewed',
                    'value' => '',
                ],
            ])

            /*
            ->add([
                'name' => 'owner_id',
                'type' => OmekaElement\ResourceSelect::class,
                'options' => [
                    'label' => 'Owner', // @ translate
                    'resource_value_options' => [
                        'resource' => 'users',
                        'query' => [],
                        'option_text_callback' => function ($user) {
                            return $user->name();
                        },
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'owner_id',
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select a user…', // @ translate
                    'data-api-base-url' => $urlHelper('api/default', ['resource' => 'users']),
                ],
            ])
            */
            // TODO Fix issue when the number of users is too big to allow to keep the selector.
            ->add([
                'name' => 'owner_id',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'User by id', // @translate
                ],
                'attributes' => [
                    'id' => 'owner_id',
                ],
            ])

            ->add([
                'name' => 'submit',
                'type' => Element\Submit::class,
                'attributes' => [
                    'id' => 'submit',
                    'value' => 'Search', // @translate
                    'type' => 'submit',
                ],
            ]);

        $this->getInputFilter()
            ->add([
                'name' => 'resource_template_id',
                'required' => false,
            ]);
    }

    public function setUrlHelper(Url $urlHelper): self
    {
        $this->urlHelper = $urlHelper;
        return $this;
    }
}
