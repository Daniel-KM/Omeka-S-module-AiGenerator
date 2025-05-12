<?php declare(strict_types=1);

namespace Generate;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use Common\Stdlib\PsrMessage;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;

/**
 * Generate
 *
 * @copyright Daniel Berthereau, 2019-2025
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected $dependencies = [
        'Common',
        'AdvancedResourceTemplate',
    ];

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);
        $this->addAclRules();
    }

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $translate = $plugins->get('translate');
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.66')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.66'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }

        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        if (!$this->checkDestinationDir($basePath . '/generation')) {
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/generation']
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message->setTranslator($translator));
        }
    }

    protected function postInstall(): void
    {
        $services = $this->getServiceLocator();

        $api = $services->get('ControllerPluginManager')->get('api');
        $settings = $services->get('Omeka\Settings');

        // Store the ids of the resource templates for medias.
        $templateNames = $settings->get('generate_templates_media', []);
        $templateIds = [];
        foreach ($templateNames as $templateName) {
            $templateIds[$templateName] = $api
                ->searchOne('resource_templates', is_numeric($templateName) ? ['id' => $templateName] : ['label' => $templateName], ['returnScalar' => 'id'])->getContent();
        }
        $templateFileIds = array_filter($templateIds);
        $settings->set('generate_templates_media', array_values($templateFileIds));

        // Store the ids of the resource templates for items.
        $templateNames = $settings->get('generate_templates', []);
        $templateIds = [];
        foreach ($templateNames as $templateName) {
            $templateIds[$templateName] = $api
                ->searchOne('resource_templates', is_numeric($templateName) ? ['id' => $templateName] : ['label' => $templateName], ['returnScalar' => 'id'])->getContent();
        }
        $templateItemIds = array_filter($templateIds);
        $settings->set('generate_templates', array_values($templateItemIds));

        // Set the tempalte Generation File the template for media in main
        // template Generation.
        $templateFile = $templateFileIds['Generation File'] ?? null;
        $templateItem = $templateItemIds['Generation'] ?? null;
        if ($templateItem && $templateFile) {
            /** @var \AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation $template */
            $template = $api->read('resource_templates', ['id' => $templateItem])->getContent();
            $templateData = $template->data();
            $templateData['generate_templates_media'] = [$templateFile];
            $api->update('resource_templates', $templateItem, ['o:data' => $templateData], [], ['isPartial' => true]);
        }
    }

    protected function postUninstall(): void
    {
        // Don't remove templates.

        $config = $this->getServiceLocator()->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $this->rmDir($basePath . '/generation');
    }

    /**
     * Add ACL rules for this module.
     */
    protected function addAclRules(): void
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');

        $generateMode = $settings->get('generate_mode', 'user');
        $isOpenGeneration = $generateMode === 'open' || $generateMode === 'token';

        $generateRoles = $generateMode === 'role'
            ? $settings->get('generate_roles', [])
            : null;

        $allowUpdateMode = $settings->get('generate_allow_update', 'submission');

        /**
         * For default rights:
         * @see \Omeka\Service\AclFactory
         *
         * @var \Omeka\Permissions\Acl $acl
         */
        $acl = $services->get('Omeka\Acl');

        // Since Omeka 1.4, modules are ordered so Guest comes after Generate.
        // See \Guest\Module::onBootstrap().
        if (!$acl->hasRole('guest')) {
            $acl->addRole('guest');
        }
        if (!$acl->hasRole('guest_private')) {
            $acl->addRole('guest_private');
        }

        $roles = $acl->getRoles();

        $generators = $isOpenGeneration
            ? []
            : ($generateRoles ?? $roles);

        $generators = array_intersect($generators, $acl->getRoles());

        // Users who can edit resources can update generations.
        // A check is done on the specific resource for some roles.
        $validators = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];

        // Only admins can delete a generation.
        $simpleValidators = [
            \Omeka\Permissions\Acl::ROLE_REVIEWER,
        ];
        $adminValidators = [
            \Omeka\Permissions\Acl::ROLE_GLOBAL_ADMIN,
            \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            \Omeka\Permissions\Acl::ROLE_EDITOR,
        ];

        // Nobody can view generations except owner and admins.
        // So anonymous generator cannot view or edit a generation.
        // Once submitted, the generation cannot be updated by the owner,
        // except with option "generate_allow_update".
        // Once reviewed, the generation can be viewed like the resource.

        // Generation.
        $acl
            ->allow(
                $generators,
                ['Generate\Controller\Site\Generation'],
                // TODO "view" is forwarded to "show" internally (will be removed).
                ['show', 'view', 'add', 'edit', 'delete', 'delete-confirm', 'submit']
            )
            ->allow(
                $generators,
                [\Generate\Api\Adapter\GenerationAdapter::class],
                ['search', 'read', 'create', 'update', 'delete']
            )
            ->allow(
                $generators,
                [\Generate\Entity\Generation::class],
                [
                    'create',
                    // TODO Remove right to change owner of the generation (only set it first time).
                    'change-owner',
                ]
            )
            ->allow(
                $generators,
                [\Generate\Entity\Generation::class],
                ['read'],
                (new \Laminas\Permissions\Acl\Assertion\AssertionAggregate)
                    ->setMode(\Laminas\Permissions\Acl\Assertion\AssertionAggregate::MODE_AT_LEAST_ONE)
                    ->addAssertion(new \Omeka\Permissions\Assertion\OwnsEntityAssertion)
                    ->addAssertion(new \Generate\Permissions\Assertion\IsSubmittedAndReviewedAndHasPublicResource)
            )
        ;
        if ($allowUpdateMode === 'submission' || $allowUpdateMode === 'validation') {
            $acl
                ->allow(
                    $generators,
                    [\Generate\Entity\Generation::class],
                    ['update', 'delete'],
                    (new \Laminas\Permissions\Acl\Assertion\AssertionAggregate)
                        ->addAssertion(new \Omeka\Permissions\Assertion\OwnsEntityAssertion)
                        ->addAssertion($allowUpdateMode === 'submission'
                            ? new \Generate\Permissions\Assertion\IsNotSubmitted()
                            : new \Generate\Permissions\Assertion\IsNotReviewed()
                        )
                );
        }

        // Token.
        $acl
            ->allow(
                $generators,
                [\Generate\Api\Adapter\TokenAdapter::class],
                ['search', 'read', 'update']
            )
            ->allow(
                $generators,
                [\Generate\Entity\Token::class],
                ['update']
            )

            // Administration in public side (module Guest).
            ->allow(
                $roles,
                ['Generate\Controller\Site\GuestBoard'],
                ['browse', 'show', 'view', 'add', 'edit', 'delete', 'delete-confirm', 'submit']
            )

            // Administration.
            ->allow(
                $validators,
                ['Generate\Controller\Admin\Generation']
            )
            ->allow(
                $validators,
                [\Generate\Api\Adapter\GenerationAdapter::class]
            )
            // TODO Give right to deletion to reviewer?
            ->allow(
                $simpleValidators,
                [\Generate\Entity\Generation::class],
                ['read', 'update']
            )
            ->allow(
                $adminValidators,
                [\Generate\Entity\Generation::class],
                ['read', 'update', 'delete']
            )

            //  TODO Remove this hack to allow validators to change owner.
            ->allow(
                $validators,
                [\Omeka\Entity\Item::class],
                ['create', 'read', 'update', 'change-owner']
            )
        ;
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Media\Ingester\Manager::class,
            'service.registered_names',
            [$this, 'handleMediaIngesterRegisteredNames']
        );

        // Process validation only with api create/update, after all processes.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.hydrate.post',
            [$this, 'handleValidateGeneration'],
            -1000
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            'api.hydrate.post',
            [$this, 'handleValidateGeneration'],
            -1000
        );
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\MediaAdapter::class,
            'api.hydrate.post',
            [$this, 'handleValidateGeneration'],
            -1000
        );

        // Link to edit form on item/show page.
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'handleViewShowAfter']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.browse.after',
            [$this, 'handleViewShowAfter']
        );

        // Guest integration.
        $sharedEventManager->attach(
            \Guest\Controller\Site\GuestController::class,
            'guest.widgets',
            [$this, 'handleGuestWidgets']
        );

        // Admin management.
        $controllers = [
            'Omeka\Controller\Admin\Item',
            'Omeka\Controller\Admin\ItemSet',
            'Omeka\Controller\Admin\Media',
        ];
        foreach ($controllers as $controller) {
            // Append a bulk process to batch create tokens when enabled.
            $sharedEventManager->attach(
                $controller,
                'view.browse.before',
                [$this, 'addHeadersAdminBrowse']
            );
            // Display a link to create a token in the sidebar when enabled.
            $sharedEventManager->attach(
                $controller,
                'view.show.sidebar',
                [$this, 'adminViewShowSidebar']
            );
            // Add a tab to the resource show admin pages.
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

        $sharedEventManager->attach(
            \Generate\Entity\Generation::class,
            'entity.remove.post',
            [$this, 'deleteGenerationFiles']
        );

        // Handle main settings.
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );

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
     * Avoid to display ingester in item edit, because it's an internal one.
     */
    public function handleMediaIngesterRegisteredNames(Event $event): void
    {
        $names = $event->getParam('registered_names');
        $key = array_search('generation', $names);
        unset($names[$key]);
        $event->setParam('registered_names', $names);
    }

    /**
     * Add an error during hydration to avoid to save a resource to validate.
     */
    public function handleValidateGeneration(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        if (!$request->getOption('isGeneration')
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

    public function handleViewShowAfter(Event $event): void
    {
        echo $event->getTarget()->generationLink();
    }

    public function handleGuestWidgets(Event $event): void
    {
        $widgets = $event->getParam('widgets');
        $helpers = $this->getServiceLocator()->get('ViewHelperManager');
        $translate = $helpers->get('translate');
        $partial = $helpers->get('partial');

        $widget = [];
        $widget['label'] = $translate('Generations'); // @translate
        $widget['content'] = $partial('guest/site/guest/widget/generation');
        $widgets['generate'] = $widget;

        $event->setParam('widgets', $widgets);
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

    public function addHeadersAdminBrowse(Event $event): void
    {
        // Don't display the token form if it is not used.
        $generateMode = $this->getServiceLocator()->get('Omeka\Settings')->get('generate_mode');
        if ($generateMode !== 'user_token' && $generateMode !== 'token') {
            return;
        }
        $this->addHeadersAdmin($event);
    }

    public function adminViewShowSidebar(Event $event): void
    {
        $view = $event->getTarget();
        $plugins = $view->getHelperPluginManager();
        $setting = $plugins->get('setting');
        if (!in_array($setting('generate_mode'), ['user_token', 'token'])) {
            return;
        }

        $url = $plugins->get('url');
        $translate = $plugins->get('translate');
        $escapeAttr = $plugins->get('escapeHtmlAttr');

        $resource = $view->resource;
        $query = [
            'resource_type' => $resource->resourceName(),
            'resource_ids' => [$resource->id()],
            'redirect' => $this->getCurrentUrl($view),
        ];
        $link = $view->hyperlink(
            $translate('Create generation token'), // @translate
            $url('admin/generation/default', ['action' => 'create-token'], ['query' => $query])
        );
        $htmlText = [
            'contritube' => $translate('Generate'), // @translate
            'email' => $escapeAttr($translate('Please input optional email…')), // @translate
            'token' => $escapeAttr($translate('Create token')), // @translate
        ];
        echo <<<HTML
            <div class="meta-group create_generation_token">
                <h4>{$htmlText['contritube']}</h4>
                <div class="value" id="create_generation_token">$link</div>
                <div id="create_generation_token_dialog" class="modal" style="display:none;">
                    <div class="modal-content">
                        <span class="close" id="create_generation_token_dialog_close">&times;</span>
                        <input type="text" value="" placeholder="{$htmlText['email']}" id="create_generation_token_dialog_email"/>
                        <input type="button" value="{$htmlText['token']}" id="create_generation_token_dialog_go"/>
                    </div>
                </div>
            </div>
            HTML;
    }

    /**
     * Add a tab to section navigation.
     *
     * @param Event $event
     */
    public function appendTab(Event $event): void
    {
        $sectionNav = $event->getParam('section_nav');
        $sectionNav['generation'] = 'Generations'; // @translate
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

        $generations = $api
            ->search('generations', [
                'resource_id' => $resource->id(),
                'sort_by' => 'modified',
                'sort_order' => 'DESC',
            ])
            ->getContent();

        $unusedTokens = $api
            ->search('generation_tokens', [
                'resource_id' => $resource->id(),
                'used' => false,
            ])
            ->getContent();

        $plugins = $services->get('ViewHelperManager');
        $defaultSite = $plugins->get('defaultSite');
        $siteSlug = $defaultSite('slug');

        echo '<div id="generation" class="section">';
        echo $view->partial('common/admin/generate-list', [
            'resource' => $resource,
            'generations' => $generations,
            'unusedTokens' => $unusedTokens,
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
            ->search('generations', [
                'resource_id' => $resource->id(),
            ])
            ->getTotalResults();
        $totalNotReviewed = $view->api()
            ->search('generations', [
                'resource_id' => $resource->id(),
                'reviewed' => '0',
            ])
            ->getTotalResults();
        $generations = $translate('Generations'); // @translat
        $message = $total
            ? new PsrMessage(
                '{total} generations ({count} not reviewed)', // @translate
                ['total' => $total, 'count' => $totalNotReviewed]
            )
            : new PsrMessage('No generation'); // @translate
        $message->setTranslator($translator);
        echo <<<HTML
            <div class="meta-group">
                <h4>$generations</h4>
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
            // TODO Move generative_templates_media to Advanced Resource Template.
            ->add([
                'name' => 'generate_templates_media',
                // Advanced Resource Template is a required dependency.
                'type' => \Common\Form\Element\OptionalResourceTemplateSelect::class,
                'options' => [
                    'label' => 'Media templates for generation', // @translate
                    'info' => 'If any, the templates should be in the list of allowed templates for generation of a media. Warning: to use multiple media is supported only with specific themes for now.', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    // 'id' => 'generate_templates_media',
                    'class' => 'setting chosen-select',
                    'multiple' => true,
                    'data-setting-key' => 'generate_templates_media',
                    'data-placeholder' => 'Select resource templates for media…', // @translate
                ],
            ])
            // Specific messages for the generator.
            ->add([
                'name' => 'generate_author_confirmation_subject',
                'type' => \Laminas\Form\Element\Text::class,
                'options' => [
                    'label' => 'Specific confirmation subject to the generator', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_author_confirmation_subject',
                    'data-setting-key' => 'generate_author_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'generate_author_confirmation_body',
                'type' => \Laminas\Form\Element\Textarea::class,
                'options' => [
                    'label' => 'Specific confirmation message to the generator', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_author_confirmation_body',
                    'rows' => 5,
                    'data-setting-key' => 'generate_author_confirmation_body',
                ],
            ])
            // Specific messages for the reviewer.
            ->add([
                'name' => 'generate_reviewer_confirmation_subject',
                'type' => \Laminas\Form\Element\Text::class,
                'options' => [
                    'label' => 'Specific confirmation subject to the reviewer', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_reviewer_confirmation_subject',
                    'data-setting-key' => 'generate_reviewer_confirmation_subject',
                ],
            ])
            ->add([
                'name' => 'generate_reviewer_confirmation_body',
                'type' => \Laminas\Form\Element\Textarea::class,
                'options' => [
                    'label' => 'Specific confirmation message to the reviewer', // @translate
                    'info' => 'Placeholders: wrap properties with "{}", for example "{dcterms:title}".', // @translate
                ],
                'attributes' => [
                    'id' => 'generate_reviewer_confirmation_body',
                    'rows' => 5,
                    'data-setting-key' => 'generate_reviewer_confirmation_body',
                ],
            ]);
    }

    public function addResourceTemplatePropertyFieldsetElements(Event $event): void
    {
        /** @var \AdvancedResourceTemplate\Form\ResourceTemplatePropertyFieldset $fieldset */
        $fieldset = $event->getTarget();
        $fieldset
            ->add([
                'name' => 'editable',
                'type' => \Laminas\Form\Element\Checkbox::class,
                'options' => [
                    'label' => 'Generate: Editable by generator', // @translate
                ],
                'attributes' => [
                    // 'id' => 'editable',
                    'class' => 'setting',
                    'data-setting-key' => 'editable',
                ],
            ])
            ->add([
                'name' => 'fillable',
                'type' => \Laminas\Form\Element\Checkbox::class,
                'options' => [
                    'label' => 'Generate: Fillable by generator', // @translate
                ],
                'attributes' => [
                    // 'id' => 'fillable',
                    'class' => 'setting',
                    'data-setting-key' => 'fillable',
                ],
            ]);
    }

    /**
     * Delete all files associated with a removed Generation entity.
     *
     * Processed via an event to be sure that the generation is removed.
     */
    public function deleteGenerationFiles(Event $event): void
    {
        $services = $this->getServiceLocator();

        // Fix issue when there is no path.
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        $dirPath = rtrim($basePath, '/') . '/generation';
        if (!$this->checkDestinationDir($dirPath)) {
            $translator = $services->get('MvcTranslator');
            $message = new PsrMessage(
                'The directory "{directory}" is not writeable.', // @translate
                ['directory' => $basePath . '/generation']
            );
            throw new \Omeka\File\Exception\RuntimeException((string) $message->setTranslator($translator));
        }

        $store = $services->get('Omeka\File\Store');
        $entity = $event->getTarget();
        $proposal = $entity->getProposal();
        foreach ($proposal['media'] ?? [] as $mediaFiles) {
            foreach ($mediaFiles['file'] ?? [] as $mediaFile) {
                if (isset($mediaFile['proposed']['store'])) {
                    $storagePath = 'generation/' . $mediaFile['proposed']['store'];
                    $store->delete($storagePath);
                }
            }
        }

        // The entity is flushed, so it is possible to remove all remaining
        // files (after update or deletion of a proposal).
        // It is simpler to manage globally than individually because the
        // storage reference is removed currently.
        // TODO Add a column for files.
        $sql = <<<SQL
            SELECT
                JSON_EXTRACT( proposal, "$.media[*].file[*].proposed.store" ) AS proposal_json
            FROM generation
            HAVING proposal_json IS NOT NULL;
            SQL;
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $storeds = $connection->executeQuery($sql)->fetchFirstColumn();
        $storeds = array_map('json_decode', $storeds);
        $storeds = $storeds ? array_unique(array_merge(...array_values($storeds))) : [];

        // TODO Scan dir is local store only for now.
        $files = array_diff(scandir($dirPath) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            if (!is_dir($path)
                && is_file($path)
                && is_writeable($path)
                && !in_array($file, $storeds)
            ) {
                @unlink($path);
            }
        }
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
