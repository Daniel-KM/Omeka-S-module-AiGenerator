<?php declare(strict_types=1);

namespace AiGenerator;

return [
    'api_adapters' => [
        'invokables' => [
            'ai_records' => Api\Adapter\AiRecordAdapter::class,
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
            'aiRecordFields' => Service\ViewHelper\AiRecordFieldsFactory::class,
            'aiRecordSearchFilters' => Service\ViewHelper\AiRecordSearchFiltersFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\BatchEditFieldset::class => Form\BatchEditFieldset::class,
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\QuickSearchForm::class => Service\Form\QuickSearchFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'AiGenerator\Controller\Admin\Index' => Controller\Admin\IndexController::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'generativeData' => Mvc\Controller\Plugin\GenerativeData::class,
        ],
        // TODO Create a service for AiGenerator. Useless for now: there is only one generator.
        'factories' => [
            'generateViaOpenAi' => Service\ControllerPlugin\GenerateViaOpenAiFactory::class,
            'validateRecordOrCreateOrUpdate' => Service\ControllerPlugin\ValidateRecordOrCreateOrUpdateFactory::class,
        ],
    ],
    'navigation' => [
        'AdminResource' => [
            'ai-generator' => [
                'label' => 'AI Records', // @translate
                'class' => 'o-icon- ai-records fa-robot',
                'route' => 'admin/ai-record',
                // 'resource' => Controller\Admin\IndexController::class,
                // 'privilege' => 'browse',
                'pages' => [
                    [
                        'route' => 'admin/ai-records/default',
                        'controller' => Controller\Admin\IndexController::class,
                        'visible' => false,
                    ],
                    [
                        'route' => 'admin/ai-record/id',
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
                    'ai-record' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/ai-record',
                            'defaults' => [
                                '__NAMESPACE__' => 'AiGenerator\Controller\Admin',
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
    'aigenerator' => [
        'config' => [
            'aigenerator_openai_api_key' => '',
            'aigenerator_openai_organization' => '',
            'aigenerator_openai_project' => '',
        ],
        'settings' => [
            'aigenerator_roles' => [],
            'aigenerator_validate' => false,
            'aigenerator_models' => [
                'gpt-4.1-nano' => 'GPT 4.1 nano ($0.50 / 1M tokens)',
                'gpt-4.1-mini' => 'GPT 4.1 mini ($2 / 1M tokens)',
                'gpt-4.1' => 'GPT 4.1 ($10 / 1M tokens)',
                'gpt-4o-mini' => 'GPT 4o mini ($0.75 / 1M tokens, tokens x 10)',
                'gpt-4o' => 'GPT 4o ($12.5 / 1M tokens)',
                // TODO How to support images with gpt-3.5?
                // 'gpt-3.5-turbo' => 'GPT 3.5 turbo ($2 / 1M tokens, tokens x 10)',
                // Require rights, so more purchases on OpenAI.
                'gpt-4.5-preview' => 'GPT 4.5 preview ($225 / 1M tokens)',
            ],
            'aigenerator_model' => 'gpt-4.1',
            'aigenerator_max_tokens' => 10000,
            'aigenerator_derivative' => 'large',
            // Keep the prompt short, else it may cost more tokens than image analysis.
            'aigenerator_prompt_system' => <<<'TXT'
                You are an image analysis system. Describe main content of image for indexing and search purposes.
                TXT, // @translate
                // Example of technical request when a structured output cannot be used.
                // It is automatically appended when needed when the model does
                // not support output json with tools.
                /*
                'Output the response as JSON object. Each property may be single or multiple. Return only requested properties. Skip empty values.
                */
            // This prompt may be useless with chat structure, since the urls
            // are added automatically and the system context is enough.
            'aigenerator_prompt_user' => 'Analyze image', // @translate
            'aigenerator_item_sets_auto' => [],
            'aigenerator_hide_flag_review' => false,
        ],
    ],
];
