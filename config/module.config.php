<?php declare(strict_types=1);

namespace Generate;

return [
    'api_adapters' => [
        'invokables' => [
            'generated_resources' => Api\Adapter\GeneratedResourceAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'canGenerate' => View\Helper\CanGenerate::class,
        ],
        'factories' => [
            'generatedResourceFields' => Service\ViewHelper\GeneratedResourceFieldsFactory::class,
            'generatedResourceSearchFilters' => Service\ViewHelper\GeneratedResourceSearchFiltersFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\GenerateForm::class => Form\GenerateForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\QuickSearchForm::class => Service\Form\QuickSearchFormFactory::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'Generate\Controller\Admin\Index' => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'generativeData' => Mvc\Controller\Plugin\GenerativeData::class,
        ],
        // TODO Create a service for AiGenerator.
        'factories' => [
            'generateViaOpenAi' => Service\ControllerPlugin\GenerateViaOpenAiFactory::class,
        ],
    ],
    'navigation' => [
        'AdminResource' => [
            'generate' => [
                'label' => 'Generations', // @translate
                'class' => 'o-icon- generated-resources fa-robot',
                'route' => 'admin/generated-resource',
                // 'resource' => Controller\Admin\IndexController::class,
                // 'privilege' => 'browse',
                'pages' => [
                    [
                        'route' => 'admin/generated-resources/default',
                        'controller' => Controller\Admin\IndexController::class,
                        'visible' => false,
                    ],
                    [
                        'route' => 'admin/generated-resource/id',
                        'controller' => Controller\Admin\IndexController::class,
                        'visible' => false,
                    ],
                ],
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'generated-resource' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/generated-resource',
                            'defaults' => [
                                '__NAMESPACE__' => 'Generate\Controller\Admin',
                                'controller' => 'index',
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                            ],
                            'id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:id[/:action]',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'generate' => [
        'config' => [
            'generate_openai_api_key' => '',
            'generate_openai_organization' => '',
            'generate_openai_project' => '',
        ],
        'settings' => [
            'generate_roles' => [],
            'generate_prompt_system' => <<<'TXT'
                You are a system to analyze images. The images are stored in a database and your analyse is used to search and find these images.
                Therefore, describe the main content of the image which might be relevant for search/find operations.
                Output the response as a structured json. Return only the requested properties.
                Example:
                {properties_sample_json}
                TXT, // @translate
            'generate_prompt_user' => 'Analyze the image then generate metadata as json', // @translate
        ],
    ],
];
