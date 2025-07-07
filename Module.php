<?php declare(strict_types=1);

namespace Generate;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Assertion\OwnsEntityAssertion;

/**
 * Generate Resource Metadata.
 *
 * @copyright Daniel Berthereau, 2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'AdvancedResourceTemplate',
    ];

    public function init(ModuleManager $moduleManager): void
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.70')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.70'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        if (!$this->checkModuleActiveVersion('AdvancedResourceTemplate', '3.4.43')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Advanced Resource Template', '3.4.43'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        if (PHP_VERSION_ID < 80200) {
            $message = new \Common\Stdlib\PsrMessage(
                $translate('This module require php version {version} or above.'), // @translate
                ['version' => '8.2']
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    protected function postInstall(): void
    {
        $this->postInstallAuto();

        /**
         * @var \Omeka\Settings\Settings $settings
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $settings->set('generate_roles', [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
        ]);
    }

    protected function isSettingTranslatable(string $settingsType, string $name): bool
    {
        $translatables = [
            'settings' => [
                'generate_chatgpt_prompt',
            ],
        ];
        return isset($translatables[$settingsType])
            && in_array($name, $translatables[$settingsType]);
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        /**
         * Roles are the same than resource edition and batch processing, except
         * for deletion (a reviewer can delete).
         * @see \Omeka\Service\AclFactory::addRules()
         *
         * @var \Omeka\Permissions\Acl $acl
         */
        $acl = $this->getServiceLocator()->get('Omeka\Acl');

        // Users who can edit resources can update generations.
        // A check is done on the specific resource for some roles.
        $authors = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
            \Omeka\Permissions\Acl::ROLE_AUTHOR,
        ];

        $validators = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];

        $acl
            ->allow(
                $authors,
                [
                    'Generate\Controller\Admin\Index',
                ],
                [
                    'index',
                    'search',
                    'browse',
                    'show',
                    'add',
                    'edit',
                    'delete',
                    'delete-confirm',
                    'submit',
                ]
            );
            $acl->allow(
                $authors,
                [
                    'Generate\Controller\Admin\Index',
                ],
                [
                    'batch-edit',
                    'batch-delete',
                ]
            )
            ->allow(
                $authors,
                [
                    \Generate\Api\Adapter\GeneratedResourceAdapter::class,
                ],
                [
                    'create',
                    'update',
                    'delete',
                    'batch_update',
                    'batch_delete',
                ]
            )
            ->allow(
                $authors,
                [
                    \Generate\Entity\GeneratedResource::class,
                ],
                [
                    'create',
                ]
            )
            ->allow(
                [
                    \Omeka\Permissions\Acl::ROLE_AUTHOR,
                ],
                [
                    \Generate\Entity\GeneratedResource::class,
                ],
                [
                    'update',
                    'delete',
                ],
                new OwnsEntityAssertion()
            )

            // Administration.
            ->allow(
                $validators,
                [
                    \Generate\Entity\GeneratedResource::class,
                ],
                [
                    'update',
                    'delete',
                ]
            )
        ;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        // Admin management.
        $adaptersAndControllers = [
            \Omeka\Api\Adapter\ItemAdapter::class => 'Omeka\Controller\Admin\Item',
            \Omeka\Api\Adapter\MediaAdapter::class => 'Omeka\Controller\Admin\Media',
            // \Omeka\Api\Adapter\ItemSetAdapter::class => 'Omeka\Controller\Admin\ItemSet',
            // \Annotate\Api\Adapter\AnnotationAdapter::class => \Annotate\Controller\Admin\AnnotationController::class,
        ];
        foreach ($adaptersAndControllers as $adapter => $controller) {
            // Create metadata via llm.
            $sharedEventManager->attach(
                $adapter,
                'api.create.pre',
                [$this, 'handleCreateUpdateResource']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.update.pre',
                [$this, 'handleCreateUpdateResource']
            );

            // Process validation only with api create/update, after all processes.
            // The validation must not hydrate the resource.
            $sharedEventManager->attach(
                $adapter,
                'api.hydrate.post',
                [$this, 'handleValidateGeneratedResource'],
                -900
            );

            // Add form inputs to resource form to run generation.
            $sharedEventManager->attach(
                $controller,
                'view.add.form.advanced',
                [$this, 'addResourceFormElements']
            );
            $sharedEventManager->attach(
                $controller,
                'view.edit.form.advanced',
                [$this, 'addResourceFormElements']
            );

            // Add a tab to the resource show admin pages to manage generated
            // metadata.
            $sharedEventManager->attach(
                $controller,
                // There is no "view.show.before".
                'view.show.after',
                [$this, 'addHeadersAdmin']
            );
            $sharedEventManager->attach(
                $controller,
                'view.show.section_nav',
                [$this, 'appendTab']
            );
            $sharedEventManager->attach(
                $controller,
                'view.show.after',
                [$this, 'displayTab']
            );

            // Add the details to the resource browse admin pages.
            $sharedEventManager->attach(
                $controller,
                'view.details',
                [$this, 'viewDetails']
            );
        }

        $sharedEventManager->attach(
            'Generate\Controller\Admin\Generation',
            'view.browse.before',
            [$this, 'addHeadersAdmin']
        );

        // Handle main settings.
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

        // TODO Check if dependency to Advanced Resource Template is still required.

        $sharedEventManager->attach(
            // \Omeka\Form\ResourceTemplateForm::class,
            \AdvancedResourceTemplate\Form\ResourceTemplateForm::class,
            'form.add_elements',
            [$this, 'addResourceTemplateFormElements']
        );
        $sharedEventManager->attach(
            // \Omeka\Form\ResourceTemplatePropertyFieldset::class,
            \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset::class,
            'form.add_elements',
            [$this, 'addResourceTemplatePropertyFieldsetElements']
        );
    }

    /**
     * Prepare generated metadata.
     */
    public function handleCreateUpdateResource(Event $event): void
    {
        /**
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Api\Response $response
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \Omeka\Entity\User $user
         */
        $services = $this->getServiceLocator();

        // Check if generation is enabled.
        $request = $event->getParam('request');
        $resourceData = $request->getContent();
        if (empty($resourceData['generate_metadata'])) {
            return;
        }

        $settings = $services->get('Omeka\Settings');

        // Generating may be expensive, so there is a specific check for roles.
        $generateRoles = $settings->get('generate_roles');
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user || !in_array($user->getRole(), $generateRoles)) {
            return;
        }

        $prompt = $resourceData['generate_chatgpt_prompt'] ?? '';
        unset($resourceData['generate_metadata'], $resourceData['generate_chatgpt_prompt']);

        $plugins = $services->get('ControllerPluginManager');
        $generateViaChatGpt = $plugins->get('generateViaChatGpt');
        $generateViaChatGpt($resourceData, [
            'prompt' => $prompt,
        ]);
    }

    /**
     * Add an error during hydration to avoid to save a resource to validate.
     *
     * Context: When a generated resource is converted into an item, it should be
     * checked first. Some checks are done via events in api and hydration.
     * So the process requires options "isGeneratedResource" and"validateOnly"
     * At the end, an error is added to the error store to avoid to save the
     * resource.
     */
    public function handleValidateGeneratedResource(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if (!$request->getOption('isGeneratedResource')
            || !$request->getOption('validateOnly')
            || $request->getOption('flushEntityManager')
        ) {
            return;
        }

        $entity = $event->getParam('entity');
        if (!$entity instanceof \Omeka\Entity\Resource) {
            return;
        }

        // Don't add an error if there is already one.
        /** @var \Omeka\Stdlib\ErrorStore $errorStore */
        $errorStore = $event->getParam('errorStore');
        if ($errorStore->hasErrors()) {
            return;
        }

        // The validation of the entity in the adapter is processed after event,
        // so trigger it here with a new error store.
        $validateErrorStore = new \Omeka\Stdlib\ErrorStore;
        $adapter = $event->getTarget();
        $adapter->validateEntity($entity, $validateErrorStore);
        if ($validateErrorStore->hasErrors()) {
            return;
        }

        $errorStore->addError('validateOnly', 'No error');
    }

    public function addHeadersAdmin(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/generate-admin.css', 'Generate'));
        $view->headScript()
            ->appendFile($assetUrl('js/generate-admin.js', 'Generate'), 'text/javascript', ['defer' => 'defer']);
    }

    public function addResourceFormElements(Event $event): void
    {
        /**
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Settings\Settings $settings
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \Omeka\Entity\User $user
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        // Generating may be expensive, so there is a specific check for roles.
        $generateRoles = $settings->get('generate_roles');
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user || !in_array($user->getRole(), $generateRoles)) {
            return;
        }

        $this->addHeadersAdmin($event);

        $apiKey = $settings->get('generate_chatgpt_api_key');

        $prompt = trim((string) $settings->get('generate_chatgpt_prompt'))
            ?: $this->getModuleConfig('settings')['generate_chatgpt_prompt'];

        $elementGenerate = new \Laminas\Form\Element\Checkbox('generate_metadata');
        $elementGenerate
            ->setLabel(
                empty($apiKey)
                    ? 'Generate metadata (api key undefined)' // @translate
                    : 'Generate metadata' // @translate
            )
            ->setOptions([
                'use_hidden_element' => false,
            ])
            ->setAttributes([
                'id' => 'generate-metadata',
                'value' => 0,
                'disabled' => empty($apiKey) ? 'disabled' : false,
            ]);

        $elementPrompt = new \Laminas\Form\Element\Textarea('generate_chatgpt_prompt');
        $elementPrompt
            ->setLabel('Prompt') // @translate
            ->setAttributes([
                'id' => 'generate-prompt',
                'value' => $prompt,
                'rows' => 10,
                // Enabled via js when checkbox is on.
                'disabled' => 'disabled',
            ]);

        $view = $event->getTarget();
        echo $view->formRow($elementGenerate);
        echo strtr($view->formRow($elementPrompt), ['<div class="field"' => '<div class="field" style="display: none;"']);
    }

    /**
     * Add a tab to section navigation.
     *
     * @param Event $event
     */
    public function appendTab(Event $event): void
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['generated-resource'] = 'Generated'; // @translate
        $event->setParam('section_nav', $sectionNav);
    }

    /**
     * Display a partial for a resource.
     *
     * @param Event $event
     */
    public function displayTab(Event $event): void
    {
        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $view = $event->getTarget();

        $resource = $view->resource;

        $generatedResources = $api
            ->search('generated_resources', [
                'resource_id' => $resource->id(),
                'sort_by' => 'modified',
                'sort_order' => 'DESC',
            ])
            ->getContent();

        $plugins = $services->get('ViewHelperManager');
        $defaultSite = $plugins->get('defaultSite');
        $siteSlug = $defaultSite('slug');

        echo '<div id="generated-resource" class="section">';
        echo $view->partial('common/admin/generated-resources-list', [
            'resource' => $resource,
            'generatedResources' => $generatedResources,
            'siteSlug' => $siteSlug,
        ]);
        echo '</div>';
    }

    /**
     * Display the details for a resource.
     *
     * @param Event $event
     */
    public function viewDetails(Event $event): void
    {
        $view = $event->getTarget();
        $services = $this->getServiceLocator();
        $translate = $view->plugin('translate');
        $translator = $services->get('MvcTranslator');

        $resource = $event->getParam('entity');
        $total = $view->api()
            ->search('generated_resources', [
                'resource_id' => $resource->id(),
                'limit' => 0,
            ])
            ->getTotalResults();
        $totalNotReviewed = $view->api()
            ->search('generated_resources', [
                'resource_id' => $resource->id(),
                'reviewed' => '0',
                'limit' => 0,
            ])
            ->getTotalResults();
        $heading = $translate('Generated resources'); // @translate
        $message = $total
            ? new PsrMessage(
                '{total} generated resources ({count} not reviewed)', // @translate
                ['total' => $total, 'count' => $totalNotReviewed]
            )
            : new PsrMessage('No generated resource'); // @translate
        $message->setTranslator($translator);
        echo <<<HTML
            <div class="meta-group">
                <h4>$heading</h4>
                <div class="value">
                    $message
                </div>
            </div>
            HTML;
    }

    public function addResourceTemplateFormElements(Event $event): void
    {
        /** @var \Omeka\Form\ResourceTemplateForm $form */
        /** @var \AdvancedResourceTemplate\Form\ResourceTemplateDataFieldset $form */
        $form = $event->getTarget();
        $fieldset = $form->get('o:data');
        $fieldset
            ->add([
                'name' => 'generate_generable',
                'type' => \Laminas\Form\Element\Textarea::class,
                'options' => [
                    'label' => 'Make properties generable', // @translate
                    'options' => [
                        'value_options' => [
                            'all' => 'All', // @translate
                            'specific' => 'Specific properties', // @translate
                            'none' => 'None', // @translate
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'generate_generable',
                    'data-setting-key' => 'generate_generable',
                ],
            ]);
    }

    public function addResourceTemplatePropertyFieldsetElements(Event $event): void
    {
        /** @var \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset $fieldset */
        $fieldset = $event->getTarget();
        $fieldset
            ->add([
                'name' => 'generable',
                'type' => \Laminas\Form\Element\Checkbox::class,
                'options' => [
                    'label' => 'Generable', // @translate
                ],
                'attributes' => [
                    // 'id' => 'generable',
                    'class' => 'setting',
                    'data-setting-key' => 'generable',
                ],
            ]);
    }

    /**
     * Get the current url with query string if any.
     *
     * @param PhpRenderer $view
     * @return string
     */
    protected function getCurrentUrl(PhpRenderer $view)
    {
        $url = $view->url(null, [], true);
        $query = http_build_query($view->params()->fromQuery(), '', '&', PHP_QUERY_RFC3986);
        return $query
            ? $url . '?' . $query
            : $url;
    }
}
