<?php

declare(strict_types=1);

namespace AiGenerator;

if (!class_exists('Common\TraitModule', false)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use AiGenerator\Form\BatchEditFieldset;
use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Form\Fieldset;
use Laminas\ModuleManager\ModuleManager;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Module\AbstractModule;
use Omeka\Permissions\Assertion\OwnsEntityAssertion;

/**
 * AI Record Generator.
 *
 * @copyright Daniel Berthereau, 2019-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    public const NAMESPACE = __NAMESPACE__;

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

        $settings->set('aigenerator_roles', [
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
                'aigenerator_prompt_system',
                'aigenerator_prompt_user',
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

        // Users who can edit resources can update ai records.
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
                    'AiGenerator\Controller\Admin\Index',
                ],
                [
                    'index',
                    'search',
                    'browse',
                    'show',
                    'show-details',
                    'add',
                    'delete',
                    'delete-confirm',
                    'submit',
                ]
            )
            ->allow(
                $authors,
                [
                    \AiGenerator\Api\Adapter\AiRecordAdapter::class,
                ],
                [
                    'read',
                    'search',
                    'create',
                    'delete',
                    'batch_delete',
                ]
            )
            ->allow(
                $authors,
                [
                    \AiGenerator\Entity\AiRecord::class,
                ],
                [
                    'create',
                    'read',
                    'delete',
                ]
            )
            ->allow(
                [
                    \Omeka\Permissions\Acl::ROLE_AUTHOR,
                ],
                [
                    \AiGenerator\Entity\AiRecord::class,
                ],
                [
                    'delete',
                ],
                new OwnsEntityAssertion()
            )
            // TODO Author can validate if its own record.

            // Administration.
            ->allow(
                $validators,
                [
                    'AiGenerator\Controller\Admin\Index',
                ],
                [
                    'index',
                    'search',
                    'browse',
                    'show',
                    'show-details',
                    'add',
                    'edit',
                    'delete',
                    'delete-confirm',
                    'toggle-status',
                    'create-resource',
                    'validate',
                    'validate-value',
                    'batch-edit',
                    'batch-delete',
                    'batch-process',
                    'batch-process-all',
                ]
            )
            ->allow(
                $validators,
                [
                    \AiGenerator\Api\Adapter\AiRecordAdapter::class,
                ],
                [
                    'read',
                    'search',
                    'create',
                    'delete',
                    'batch_update',
                    'batch_delete',
                ]
            )
            ->allow(
                $validators,
                [
                    \AiGenerator\Entity\AiRecord::class,
                ],
                [
                    'create',
                    'read',
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
            // Create ai record. Use api post to manage uploaded file simpler:
            // the aim is to create an AI Record, not a Resource.
            $sharedEventManager->attach(
                $adapter,
                'api.create.post',
                [$this, 'handleCreateUpdateResource']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.update.post',
                [$this, 'handleCreateUpdateResource']
            );

            // Process validation only with api create/update, after all processes.
            // The validation must not hydrate the resource.
            $sharedEventManager->attach(
                $adapter,
                'api.hydrate.post',
                [$this, 'handleValidateAiRecord'],
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

            // Add a batch edit process.
            $sharedEventManager->attach(
                $adapter,
                'api.preprocess_batch_update',
                [$this, 'handleResourceBatchUpdatePreprocess']
            );
        }

        // Extend the batch edit form via js.
        $sharedEventManager->attach(
            '*',
            'view.batch_edit.before',
            [$this, 'addHeadersAdmin']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_elements',
            [$this, 'addBatchUpdateFormElements']
        );

        // Handle main settings.
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

        // TODO Check if dependency to Advanced Resource Template is still required: data can be stored somewhere else?

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
     * Clean params for batch update and set option for individual update.
     */
    public function handleResourceBatchUpdatePreprocess(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $post = $request->getContent();
        $data = $event->getParam('data');

        if (empty($post['ai_generator']['generate'])) {
            unset($data['ai_generator']);
            $event->setParam('data', $data);
            return;
        }

        // Empty string means empty string; null means default value.
        $data['ai_generator'] = [
            'generate' => true,
            'validate' => !empty($post['ai_generator']['validate']),
            'model' => $post['ai_generator']['model'] ?? null,
            'max_tokens' => $post['ai_generator']['max_tokens'] ?? null,
            'derivative' => $post['ai_generator']['derivative'] ?? null,
            'prompt_system' => $post['ai_generator']['prompt_system'] ?? null,
            'prompt_user' => $post['ai_generator']['prompt_user'] ?? null,
        ];
        $event->setParam('data', $data);

        unset($data['ai_generator']['generate']);

        $this->getServiceLocator()->get('Omeka\Logger')->info(
            "Generate ai records with options:\n{json}", // @translate
            ['json' => $post['ai_generator']],
        );
    }

    /**
     * Prepare generated metadata.
     */
    public function handleCreateUpdateResource(Event $event): void
    {
        /**
         * @var \Omeka\Api\Manager $api
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Api\Response $response
         * @var \Omeka\Settings\Settings $settings
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \Omeka\Entity\Item|\Omeka\Entity\Media $resource
         * @var \Omeka\Api\Adapter\ItemAdapter|\Omeka\Api\Adapter\MediaAdapter $adapter
         * @var \Omeka\Api\Representation\ItemRepresentation|\Omeka\Api\Representation\MediaRepresentation $representation
         */
        $services = $this->getServiceLocator();

        // This is an api-post event, so id is ready and checks are done.
        $resource = $event->getParam('response')->getContent();
        $adapter = $services->get('Omeka\ApiAdapterManager')->get($resource->getResourceName());
        $representation = $adapter->getRepresentation($resource);

        // Check if ai generator should be run.
        // The generation may be set via resource form or batch edit form.
        // It may be processed automatically when the resource is in the
        // specified item sets.

        $request = $event->getParam('request');
        $resourceData = $request->getContent();

        $generate = $resourceData['ai_generator'] ?? [];

        if (empty($generate['generate'])) {
            $settings = $services->get('Omeka\Settings');
            $itemSetsAuto = $settings->get('aigenerator_item_sets_auto');
            if (!$itemSetsAuto) {
                return;
            }
            $item = $representation instanceof MediaRepresentation
                ? $representation->item()
                : $representation;
            if (!array_intersect_key($item->itemSets(), array_flip($itemSetsAuto))) {
                return;
            }
            // Check if the record is already generated.
            $api = $services->get('Omeka\ApiManager');
            // TODO Item or media? The same for now, so item.
            $total = $api->search('ai_records', ['resource_id' => $item->id(),'limit' => 0])->getTotalResults();
            if ($total) {
                return;
            }
        }

        $plugins = $services->get('ControllerPluginManager');
        $generateViaOpenAi = $plugins->get('generateViaOpenAi');

        // Prepare all options, even not passed.
        // Empty string means empty string; null means default value.
        $args = [
            'model' => $generate['model'] ?? null,
            'validate' => !empty($generate['validate']),
            'max_tokens' => $generate['max_tokens'] ?? null,
            'derivative' => $generate['derivative'] ?? null,
            'prompt_system' => $generate['prompt_system'] ?? null,
            'prompt_user' => $generate['prompt_user'] ?? null,
        ];

        $generateViaOpenAi($representation, $args);
    }

    /**
     * Add an error during hydration to avoid to save a resource to validate.
     *
     * Context: When an ai record is converted into an item, it should be
     * checked first. Some checks are done via events in api and hydration.
     * So the process requires options "isAiRecord" and"validateOnly"
     * At the end, an error is added to the error store to avoid to save the
     * resource.
     */
    public function handleValidateAiRecord(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if (!$request->getOption('isAiRecord')
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
        $validateErrorStore = new \Omeka\Stdlib\ErrorStore();
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
            ->appendStylesheet($assetUrl('css/ai-generator-admin.css', 'AiGenerator'));
        $view->headScript()
            ->appendFile($assetUrl('js/ai-generator-admin.js', 'AiGenerator'), 'text/javascript', ['defer' => 'defer']);
    }

    public function addResourceFormElements(Event $event): void
    {
        $fieldset = $this->checkAndPrepareGenerateFieldset();
        if (!$fieldset) {
            return;
        }

        $fieldset->get('generate')->setOption('info', null);

        $view = $event->getTarget();

        $this->addHeadersAdmin($event);

        // TODO Add an info if the resource is in a specified item set and automatically processed?

        // Wrap fieldset to get full element names because it's echoed directly.
        $form = new \Laminas\Form\Form();
        $form->add($fieldset);
        $fieldset->prepareElement($form);

        echo $view->formCollection($fieldset, true);
    }

    public function addBatchUpdateFormElements(Event $event): void
    {
        $fieldset = $this->checkAndPrepareGenerateFieldset();
        if (!$fieldset) {
            return;
        }

        // This is not a view.
        // $this->addHeadersAdmin($event);

        /** @var \Omeka\Form\ResourceBatchUpdateForm $form */
        $form = $event->getTarget();
        $form->add($fieldset);

        $groups = $form->getOption('element_groups');
        $groups['ai_generator'] = 'AI Record Generator'; // @translate
        $form->setOption('element_groups', $groups);
    }

    protected function checkAndPrepareGenerateFieldset(): ?Fieldset
    {
        /**
         * @var \Omeka\Permissions\Acl $acl
         * @var \Omeka\Api\Request $request
         * @var \Omeka\Settings\Settings $settings
         * @var \Laminas\View\Renderer\PhpRenderer $view
         * @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource
         * @var \Omeka\File\ThumbnailManager $thumbnailManager
         * @var \Omeka\Entity\User $user
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $translator = $this->getServiceLocator()->get('MvcTranslator');

        // Generating may be expensive, so there is a specific check for roles.
        $generateRoles = $settings->get('aigenerator_roles');
        $user = $services->get('Omeka\AuthenticationService')->getIdentity();
        if (!$user || !in_array($user->getRole(), $generateRoles)) {
            return null;
        }

        $acl = $services->get('Omeka\Acl');

        $canValidate = $acl->userIsAllowed(\AiGenerator\Entity\AiRecord::class, 'update');

        $thumbnailManager = $services->get('Omeka\File\ThumbnailManager');
        $derivatives = $thumbnailManager->getTypes();
        $derivatives = ['original' => 'Original']
            + array_combine($derivatives, array_map('ucfirst', $derivatives));
        // Fix issue with translation of "medium".
        if (isset($derivatives['medium'])) {
            $derivatives['medium'] = 'Midsized'; // @translate
        }

        // The default is used only when value is not stored iin database, so
        // when badly installed.
        $defaults = $this->getModuleConfig('settings');

        $apiKey = $settings->get('aigenerator_openai_api_key');
        $validate = $canValidate && $settings->get('aigenerator_validate');
        $models = $settings->get('aigenerator_models', $defaults['aigenerator_models']);
        $model = trim((string) $settings->get('aigenerator_model', $defaults['aigenerator_models']));
        $maxTokens = (int) $settings->get('aigenerator_max_tokens', $defaults['aigenerator_max_tokens']);
        $derivative = trim((string) $settings->get('aigenerator_derivative', $defaults['aigenerator_derivative']));
        $promptSystem = trim((string) $settings->get('aigenerator_prompt_system', $translator->translate($defaults['aigenerator_prompt_system'])));
        $promptUser = trim((string) $settings->get('aigenerator_prompt_user', $translator->translate($defaults['aigenerator_prompt_user'])));

        /** @var \AiGenerator\Form\BatchEditFieldset $fieldset */
        $formElementManager = $services->get('FormElementManager');
        $fieldset = $formElementManager->get(BatchEditFieldset::class);
        $fieldset
            ->get('generate')
            ->setLabel(
                empty($apiKey)
                    ? 'Generate (api key undefined)' // @translate
                    : 'Generate' // @translate
            )
            ->setAttribute('disabled', empty($apiKey) ? 'disabled' : false);
        if ($canValidate) {
            $fieldset
                ->get('validate')
                ->setValue((int) $validate);
        } else {
            $fieldset->remove('validate');
        }
        $fieldset
            ->get('model')
            ->setValueOptions($models)
            ->setValue($model);
        $fieldset
            ->get('max_tokens')
            ->setValue($maxTokens);
        $fieldset
            ->get('derivative')
            ->setValueOptions($derivatives)
            ->setValue($derivative);
        $fieldset
            ->get('prompt_system')
            ->setValue($promptSystem);
        $fieldset
            ->get('prompt_user')
            ->setValue($promptUser);

        return $fieldset;
    }

    /**
     * Add a tab to section navigation.
     *
     * @param Event $event
     */
    public function appendTab(Event $event): void
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['ai-records'] = 'AI records'; // @translate
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

        $aiRecords = $api
            ->search('ai_records', [
                'resource_id' => $resource->id(),
                'sort_by' => 'modified',
                'sort_order' => 'DESC',
            ])
            ->getContent();

        $plugins = $services->get('ViewHelperManager');
        $defaultSite = $plugins->get('defaultSite');
        $siteSlug = $defaultSite('slug');

        $fieldset = $this->checkAndPrepareGenerateFieldset();
        $fieldset->get('generate')->setOption('info', null);

        echo '<div id="ai-records" class="section">';

        if ($fieldset) {
            $fieldset
                ->setLabel('{__}')
                ->add([
                    'name' => 'resource_id',
                    'type' => \Laminas\Form\Element\Hidden::class,
                    'attributes' => [
                        'value' => $resource->id(),
                    ],
                ])
                ->add([
                    'name' => 'submit',
                    'type' => \Laminas\Form\Element\Submit::class,
                    'options' => [
                        'element_group' => 'ai_generator',
                        'label' => ' ',
                    ],
                    'attributes' => [
                        'id' => 'generate-submit',
                        'type' => 'submit',
                        'class' => 'ai-generator-settings',
                        'value' => 'Submit', // @translate
                    ],
                ]);
            $form = new \Laminas\Form\Form();
            $form
                ->setAttributes([
                    'id' => 'ai-record-form',
                    'action' => $view->url('admin/ai-record/default', ['action' => 'add'], true),
                    // TODO Find why the submit is a get (js).
                    'method' => '¨POST',
                    'enctype' => 'multipart/form-data',
                ])
                ->add($fieldset)
                ->prepare();
            // TODO Find the right way to not display the label:
            echo strtr($view->form($form, false), ['<legend>{__}</legend>' => '']);
        }

        echo $view->partial('common/admin/ai-records-list', [
            'resource' => $resource,
            'aiRecords' => $aiRecords,
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
        $services = $this->getServiceLocator();
        $view = $event->getTarget();
        $api = $services->get('Omeka\ApiManager');
        $translate = $view->plugin('translate');
        $translator = $services->get('MvcTranslator');

        $resource = $event->getParam('entity');
        $total = $api
            ->search('ai_records', ['resource_id' => $resource->id(),'limit' => 0])
            ->getTotalResults();
        $totalNotReviewed = $api
            ->search('ai_records', ['resource_id' => $resource->id(),'reviewed' => '0','limit' => 0])
            ->getTotalResults();
        $heading = $translate('AI records'); // @translate
        $message = $total
            ? new PsrMessage(
                '{total} ai records ({count} not reviewed)', // @translate
                ['total' => $total, 'count' => $totalNotReviewed]
            )
            : new PsrMessage('No ai records'); // @translate
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
                'name' => 'generatable',
                'type' => \Common\Form\Element\OptionalRadio::class,
                'options' => [
                    'label' => 'Make properties generatable via AI', // @translate
                    'options' => [
                        'value_options' => [
                            'all' => 'All (default)', // @translate
                            'specific' => 'Specific properties', // @translate
                            'none' => 'None', // @translate
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'generatable',
                    'data-setting-key' => 'generatable',
                ],
            ]);
    }

    public function addResourceTemplatePropertyFieldsetElements(Event $event): void
    {
        /** @var \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset $fieldset */
        $fieldset = $event->getTarget();
        $fieldset
            ->add([
                'name' => 'generatable',
                'type' => \Laminas\Form\Element\Checkbox::class,
                'options' => [
                    'label' => 'Generatable via AI', // @translate
                ],
                'attributes' => [
                    // 'id' => 'generatable',
                    'class' => 'setting',
                    'data-setting-key' => 'generatable',
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
