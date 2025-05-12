<?php declare(strict_types=1);

namespace Generate;

return [
    'service_manager' => [
        'factories' => [
            File\Generation::class => Service\File\GenerationFactory::class,
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'generations' => Api\Adapter\GenerationAdapter::class,
            'generation_tokens' => Api\Adapter\TokenAdapter::class,
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
    'media_ingesters' => [
        'factories' => [
            // This is an internal ingester.
            'generation' => Service\Media\Ingester\GenerationFactory::class,
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
            'generationForm' => View\Helper\GenerationForm::class,
        ],
        'factories' => [
            'canGenerate' => Service\ViewHelper\CanGenerateFactory::class,
            'generationFields' => Service\ViewHelper\GenerationFieldsFactory::class,
            'generationLink' => Service\ViewHelper\GenerationLinkFactory::class,
            'generationSearchFilters' => Service\ViewHelper\GenerationSearchFiltersFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\ArrayQueryTextarea::class => Form\Element\ArrayQueryTextarea::class,
            Form\GenerateForm::class => Form\GenerateForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\QuickSearchForm::class => Service\Form\QuickSearchFormFactory::class,
        ],
    ],
    'resource_page_block_layouts' => [
        'invokables' => [
            'generateLink' => Site\ResourcePageBlockLayout\GenerationLink::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Generate\Controller\Site\GuestBoard' => Controller\Site\GuestBoardController::class,
        ],
        'factories' => [
            'Generate\Controller\Admin\Generation' => Service\Controller\AdminGenerationControllerFactory::class,
            'Generate\Controller\Site\Generation' => Service\Controller\SiteGenerationControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'checkToken' => Mvc\Controller\Plugin\CheckToken::class,
            'generativeData' => Mvc\Controller\Plugin\GenerativeData::class,
        ],
        'factories' => [
            'sendGenerationEmail' => Service\ControllerPlugin\SendGenerationEmailFactory::class,
        ],
    ],
    'navigation' => [
        'AdminResource' => [
            'generation' => [
                'label' => 'Generations', // @translate
                'class' => 'o-icon- generations fa-edit',
                'route' => 'admin/generation',
                // 'resource' => Controller\Admin\GenerationController::class,
                // 'privilege' => 'browse',
                'pages' => [
                    [
                        'route' => 'admin/generation/default',
                        'controller' => Controller\Admin\GenerationController::class,
                        'visible' => false,
                    ],
                    [
                        'route' => 'admin/generation/id',
                        'controller' => Controller\Admin\GenerationController::class,
                        'visible' => false,
                    ],
                ],
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'generation' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:resource/add',
                            'constraints' => [
                                'resource' => 'generation|item-set|item|media',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Generate\Controller\Site',
                                'controller' => 'generation',
                                'resource' => 'generation',
                                'action' => 'add',
                            ],
                        ],
                    ],
                    'generation-id' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            // TODO Use controller delegator or override the default site route?
                            // Overrides core public site resources for unused actions.
                            'route' => '/:resource/:id/:action',
                            'constraints' => [
                                'resource' => 'generation|item-set|item|media',
                                'id' => '\d+',
                                // "show" can be used only for generation, so use "view".
                                // "view" is always forwarded to "show".
                                // "add" is added to manage complex workflow. The id is useless for it.
                                'action' => 'add|view|edit|delete-confirm|delete|submit',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Generate\Controller\Site',
                                'controller' => 'generation',
                                'resource' => 'generation',
                                // Use automatically the core routes, since it is not in the constraints.
                                'action' => 'show',
                            ],
                        ],
                    ],
                    'guest' => [
                        // The default values for the guest user route are kept
                        // to avoid issues for visitors when an upgrade of
                        // module Guest occurs or when it is disabled.
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/guest',
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'generation' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/generation[/:action]',
                                    'constraints' => [
                                        'action' => 'add|browse',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Generate\Controller\Site',
                                        'controller' => 'guest-board',
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'generation-id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/generation/:id[/:action]',
                                    'constraints' => [
                                        // TODO Remove "view".
                                        'action' => 'show|view|edit|delete-confirm|delete|submit',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Generate\Controller\Site',
                                        'controller' => 'guest-board',
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'generation' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/generation',
                            'defaults' => [
                                '__NAMESPACE__' => 'Generate\Controller\Admin',
                                'controller' => 'generation',
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
    'js_translate_strings' => [
        'Prepare tokens to edit selected', // @translate
        'Prepare tokens to edit all', // @translate
    ],
    'blocksdisposition' => [
        'views' => [
            /* No event currently.
             'item_set_show' => [
                'Generate',
            ],
            */
            'item_show' => [
                'Generate',
            ],
            /* No event currently.
             'media_show' => [
                'Generate',
            ],
            */
            'item_browse' => [
                'Generate',
            ],
        ],
    ],
    'generate' => [
        'settings' => [
            'generate_mode' => 'user',
            'generate_roles' => [],
            'generate_email_regex' => '',
            'generate_templates' => [
                // The id is set during install.
                'Generation',
            ],
            'generate_templates_media' => [
                // The id is set during install.
                'Generation File',
            ],
            // Days.
            'generate_token_duration' => 60,
            'generate_allow_update' => 'submission',
            'generate_notify_recipients' => [],
            'generate_author_emails' => [],
            'generate_message_add' => '',
            'generate_message_edit' => '',
            'generate_author_confirmations' => [],
            'generate_author_confirmation_subject' => '',
            'generate_author_confirmation_body' => '',
            'generate_reviewer_confirmation_subject' => '',
            'generate_reviewer_confirmation_body' => '',
        ],
    ],
];
